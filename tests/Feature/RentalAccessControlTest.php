<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings as LimoBookings;
use Modules\Limousine\Livewire\InvoiceForm as LimoInvoiceForm;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Livewire\Reports as LimoReports;
use Modules\Rental\Livewire\InvoiceForm;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Livewire\QuotationForm;
use Modules\Rental\Livewire\RentalHome;
use Modules\Rental\Livewire\Reports;
use Modules\Rental\Livewire\Sales;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The Rental and Limousine screens are hand-written rather than built on the
 * engine's list/form components, so they carried none of the engine's
 * permission checks: any account with a login could create and price
 * invoices, quotations, bookings and receipts, whatever per-app access an
 * admin had granted in Settings → Users.
 */
final class RentalAccessControlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
    }

    private function staff(): User
    {
        return User::factory()->create(['is_admin' => false]);
    }

    public function test_a_user_without_access_cannot_open_a_rental_invoice(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(InvoiceForm::class)->assertForbidden();
    }

    public function test_a_user_without_access_cannot_open_a_limousine_invoice(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(LimoInvoiceForm::class)->assertForbidden();
    }

    public function test_read_access_alone_cannot_price_a_quotation(): void
    {
        // A "view only" grant is exactly what Settings → Users hands a staff
        // account: they may look, they may not write.
        $this->grantEveryone('rental.quotation');
        $this->readOnly('rental.quotation');
        $this->actingAs($this->staff());

        $customer = RentalCustomer::query()->create(['name' => 'Ali']);
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]);

        Livewire::test(QuotationForm::class)
            ->assertOk()
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', Carbon::now()->addDay()->format('Y-m-d'))
            ->set('end_date', Carbon::now()->addDays(3)->format('Y-m-d'))
            ->set('rate', '25')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, RentalQuotation::query()->count());
    }

    public function test_a_granted_user_can_price_a_quotation(): void
    {
        $this->grantEveryone('rental.quotation');
        $this->actingAs($this->staff());

        $customer = RentalCustomer::query()->create(['name' => 'Ali']);
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', Carbon::now()->addDay()->format('Y-m-d'))
            ->set('end_date', Carbon::now()->addDays(3)->format('Y-m-d'))
            ->set('rate', '25')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RentalQuotation::query()->count());
    }

    public function test_a_bespoke_form_cannot_be_repointed_at_another_record(): void
    {
        $this->grantEveryone('rental.quotation');
        $this->actingAs($this->staff());

        $component = Livewire::test(QuotationForm::class);

        try {
            $component->set('id', 1);
            $this->fail('The record id should be locked against client updates.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('locked', mb_strtolower($e->getMessage()));
        }
    }

    /**
     * The bespoke index / report / home screens render business data directly
     * in render() — they were protected by nothing but `auth`, so any account
     * with a login could read every customer's PII, orders, invoices, receipts
     * and financial reports regardless of the per-app access an admin granted.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function unguardedScreens(): iterable
    {
        yield 'rental orders list' => [Orders::class, 'rental.order'];
        yield 'rental reports' => [Reports::class, 'rental.order'];
        yield 'rental sales matrix' => [Sales::class, 'rental.order'];
        yield 'rental home dashboard' => [RentalHome::class, 'rental.order'];
        yield 'limousine bookings list' => [LimoBookings::class, 'limousine.booking'];
        yield 'limousine reports' => [LimoReports::class, 'limousine.booking'];
        yield 'limousine home dashboard' => [LimoHome::class, 'limousine.booking'];
    }

    /**
     * @param  class-string  $component
     *
     * @dataProvider unguardedScreens
     */
    public function test_a_user_without_access_cannot_open_a_bespoke_screen(string $component, string $model): void
    {
        $this->actingAs($this->staff());

        Livewire::test($component)->assertForbidden();
    }

    /**
     * @param  class-string  $component
     *
     * @dataProvider unguardedScreens
     */
    public function test_a_granted_user_can_open_a_bespoke_screen(string $component, string $model): void
    {
        $this->grantEveryone($model);
        $this->actingAs($this->staff());

        Livewire::test($component)->assertOk();
    }

    /** Downgrade a global grant to read-only. */
    private function readOnly(string $model): void
    {
        \App\Models\Auth\ModelAccess::query()
            ->where('model', $model)
            ->whereNull('group_id')
            ->update(['perm_write' => false, 'perm_create' => false, 'perm_unlink' => false]);
    }
}
