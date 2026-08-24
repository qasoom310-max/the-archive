<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Mail\RentalAgreementMail;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalAgreementPdf;
use Tests\TestCase;

/**
 * The printable Car Hire Agreement overlay — drops the order's values onto the
 * pre-printed form, with a calibration grid.
 */
final class RentalAgreementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function order(?string $email = null): RentalOrder
    {
        $customer = RentalCustomer::query()->create(['name' => 'Manar Mohamed', 'phone' => '+971556412563', 'license_no' => '9151974', 'email' => $email]);
        $vehicle = Vehicle::query()->create(['name' => 'Taurus', 'make' => 'Ford', 'model' => 'Taurus', 'plate_no' => '111629', 'daily_rate' => 33]);

        return RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'phone' => '+971556412563',
            'order_date' => Carbon::parse('2026-06-11'),
            'start_date' => Carbon::now()->addDay(), 'end_date' => Carbon::now()->addDays(2),
            'rate_type' => 'daily', 'rate' => 33, 'deposit' => 50,
        ]);
    }

    private function render(RentalOrder $order, bool $calibrate): string
    {
        return view('rental::agreement-print', [
            'order' => $order->load('customer', 'vehicle'),
            'calibrate' => $calibrate,
        ])->render();
    }

    public function test_agreement_renders_the_order_values(): void
    {
        $html = $this->render($this->order(), false);

        $this->assertStringContainsString('Manar Mohamed', $html); // customer name
        $this->assertStringContainsString('111629', $html);        // plate
        $this->assertStringContainsString('9151974', $html);       // driving licence
        // Two decimals, per the currency policy — this print was hard-coded to three.
        $this->assertStringContainsString('33.00', $html);         // daily rate
        $this->assertStringNotContainsString('33.000', $html);
        // Not calibrating → the print dialog auto-opens.
        $this->assertStringContainsString('onload="window.print()"', $html);
    }

    public function test_calibration_grid_is_opt_in(): void
    {
        $order = $this->order();

        $this->assertStringNotContainsString('Hide grid', $this->render($order, false));

        $grid = $this->render($order, true);
        $this->assertStringContainsString('Hide grid', $grid);
        // No auto-print while calibrating (so the grid can be read on screen).
        $this->assertStringNotContainsString('onload=', $grid);
    }

    public function test_self_contained_pdf_includes_details_and_the_terms(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::set('rental.agreement_terms', 'No smoking. Fuel returned as received.');
        app(SettingManager::class)->flush();

        $order = $this->order();
        $html = view('rental::agreement-pdf', app(RentalAgreementPdf::class)->viewData($order))->render();

        $this->assertStringContainsString('Car Hire Agreement', $html);
        $this->assertStringContainsString('Manar Mohamed', $html);
        $this->assertStringContainsString('111629', $html);                       // plate
        $this->assertStringContainsString('No smoking', $html);                   // terms from setting
        $this->assertStringContainsString('Customer signature', $html);
    }

    public function test_emailing_sends_the_agreement_to_the_customer(): void
    {
        Mail::fake();
        $order = $this->order('renter@example.com');

        Livewire::test(OrderForm::class, ['id' => $order->id])->call('emailAgreement');

        Mail::assertSent(RentalAgreementMail::class, fn (RentalAgreementMail $m): bool => $m->hasTo('renter@example.com'));
        $this->assertNotNull($order->fresh()?->agreement_emailed_at);
    }

    public function test_the_agreement_can_only_be_emailed_once(): void
    {
        Mail::fake();
        $order = $this->order('renter@example.com');

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('emailAgreement')   // sends
            ->call('emailAgreement');  // blocked — already sent

        Mail::assertSent(RentalAgreementMail::class, 1);
    }

    public function test_emailing_without_a_customer_email_sends_nothing(): void
    {
        Mail::fake();
        $order = $this->order(); // no email

        Livewire::test(OrderForm::class, ['id' => $order->id])->call('emailAgreement');

        Mail::assertNothingSent();
    }
}
