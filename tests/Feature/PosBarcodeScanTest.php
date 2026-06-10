<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * Camera barcode scanning: the terminal's scan icon decodes a barcode and
 * calls scanBarcode(code), which resolves an ACTIVE product by its barcode
 * and adds it to the cart (Odoo-style), flashing scan-hit / scan-miss.
 */
final class PosBarcodeScanTest extends TestCase
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
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    private function product(string $barcode, bool $active = true): PosProduct
    {
        return PosProduct::query()->create([
            'name' => 'Espresso',
            'price' => 2.5,
            'tax_rate' => 0.0,
            'active' => $active,
            'barcode' => $barcode,
        ]);
    }

    public function test_scanning_a_known_barcode_adds_the_product_and_flashes_a_hit(): void
    {
        $session = $this->openSession();
        $this->product('500001');

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('scanBarcode', '500001')
            ->assertDispatched('scan-hit', name: 'Espresso');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(1, $order->lines()->count());
        $this->assertSame(1.0, (float) $order->lines()->firstOrFail()->qty);
    }

    public function test_scanning_the_same_barcode_twice_increments_the_line(): void
    {
        $session = $this->openSession();
        $this->product('500001');

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('scanBarcode', '500001')
            ->call('scanBarcode', '500001');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        // One merged line at qty 2 (same plain product), not two lines.
        $this->assertSame(1, $order->lines()->count());
        $this->assertSame(2.0, (float) $order->lines()->firstOrFail()->qty);
        $component->assertDispatched('scan-hit');
    }

    public function test_unknown_barcode_adds_nothing_and_flashes_a_miss(): void
    {
        $session = $this->openSession();
        $this->product('500001');

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('scanBarcode', '999999')
            ->assertDispatched('scan-miss', barcode: '999999')
            ->assertNotDispatched('scan-hit');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(0, $order->lines()->count());
    }

    public function test_inactive_products_are_not_scannable(): void
    {
        $session = $this->openSession();
        $this->product('500001', active: false);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('scanBarcode', '500001')
            ->assertDispatched('scan-miss', barcode: '500001');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(0, $order->lines()->count());
    }

    public function test_blank_barcode_is_a_noop(): void
    {
        $session = $this->openSession();
        $this->product('500001');

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('scanBarcode', '   ')
            ->assertNotDispatched('scan-hit')
            ->assertNotDispatched('scan-miss');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->firstOrFail();
        $this->assertSame(0, $order->lines()->count());
    }
}
