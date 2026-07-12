<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Proof of payment: an opt-in POS feature that lets the cashier attach a photo
 * (a Benefit / bank-transfer screenshot) to an order from the payment popup, so
 * the owner can verify the money actually came in.
 */
final class PosPaymentProofTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
    }

    private function draftWithProduct(PosSession $session): PosProduct
    {
        return PosProduct::query()->create([
            'name' => 'Perfume', 'price' => 10, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5,
        ]);
    }

    public function test_a_proof_photo_attaches_to_the_order_when_the_feature_is_on(): void
    {
        Storage::fake('public');
        Features::setOverrides([Feature::PaymentProof->value => true]);
        $session = $this->openSession();
        $product = $this->draftWithProduct($session);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->set('paymentProof', UploadedFile::fake()->image('benefit.jpg'))
            ->assertHasNoErrors();

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertNotNull($order->payment_proof_path);
        $this->assertTrue($order->hasPaymentProof());
        $this->assertNotNull($order->paymentProofUrl());
        Storage::disk('public')->assertExists((string) $order->payment_proof_path);
    }

    public function test_the_upload_is_ignored_when_the_feature_is_off(): void
    {
        Storage::fake('public');
        // PaymentProof is DEFAULT_OFF and no override is set → stays off.
        $session = $this->openSession();
        $product = $this->draftWithProduct($session);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->set('paymentProof', UploadedFile::fake()->image('benefit.jpg'));

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertNull($order->payment_proof_path);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        Features::setOverrides([Feature::PaymentProof->value => true]);
        $session = $this->openSession();
        $product = $this->draftWithProduct($session);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->set('paymentProof', UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'))
            ->assertHasErrors('paymentProof');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertNull($order->payment_proof_path);
    }

    public function test_removing_a_proof_clears_the_path_and_deletes_the_file(): void
    {
        Storage::fake('public');
        Features::setOverrides([Feature::PaymentProof->value => true]);
        $session = $this->openSession();
        $product = $this->draftWithProduct($session);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->set('paymentProof', UploadedFile::fake()->image('benefit.jpg'));

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $path = (string) $order->payment_proof_path;
        $this->assertNotSame('', $path);

        $component->call('removePaymentProof');

        $this->assertNull($order->fresh()?->payment_proof_path);
        Storage::disk('public')->assertMissing($path);
    }
}
