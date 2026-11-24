<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Pages\CorporateRates;
use App\Livewire\Pages\DailySummary;
use App\Livewire\Pages\MonthlyProfit;
use App\Livewire\Pages\Payroll;
use App\Livewire\Pages\PricingManager;
use App\Livewire\Settings\UserManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Report pages opened one by one (the owner's grant to Ali, Hasan and Abbas,
 * 2026-11-24): website fares, corporate rates, Daily Summary, Profit and Car
 * income — without Payroll or the employee records.
 */
final class ReportPagesTest extends TestCase
{
    use DatabaseMigrations;

    public function test_given_pages_open_and_payroll_stays_closed(): void
    {
        $ali = User::factory()->create(['name' => 'Ali Ops', 'report_pages' => ['fares', 'corporate', 'daily', 'profit', 'car_income']]);
        $this->actingAs($ali);

        $this->assertTrue($ali->seesReportPage('fares'));
        $this->assertTrue($ali->seesCarIncome());
        Livewire::test(DailySummary::class)->assertOk();
        Livewire::test(MonthlyProfit::class)->assertOk();
        Livewire::test(PricingManager::class)->assertOk();
        Livewire::test(CorporateRates::class)->assertOk();
        Livewire::test(Payroll::class)->assertForbidden();
    }

    public function test_without_the_page_it_stays_closed(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Mac', 'report_pages' => ['daily']]));

        Livewire::test(DailySummary::class)->assertOk();
        Livewire::test(MonthlyProfit::class)->assertForbidden();
        Livewire::test(PricingManager::class)->assertForbidden();
    }

    public function test_the_owner_switches_a_page_on_and_off(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $hasan = User::factory()->create(['name' => 'Hasan']);

        Livewire::test(UserManager::class)
            ->assertSeeHtml('data-report-page="profit"')
            ->call('toggleReportPage', $hasan->id, 'profit');
        $this->assertTrue($hasan->fresh()?->hasReportPage('profit'));
        // The list shows it ticked (the column is loaded with the list).
        Livewire::test(UserManager::class)->assertSeeHtml('border-emerald-500 bg-emerald-500 text-white');

        Livewire::test(UserManager::class)->call('toggleReportPage', $hasan->id, 'profit');
        $this->assertFalse($hasan->fresh()?->hasReportPage('profit'));
    }

    public function test_a_named_person_can_add_late_fees_and_the_owner_switches_it(): void
    {
        app(\App\Erp\Modules\ModuleManager::class)->install('limousine');
        $this->grantEveryone('limousine.customer');
        $customer = \Modules\Limousine\Models\LimoCustomer::query()->create(['name' => 'Demo Co', 'type' => 'company']);
        $via = User::factory()->create(['name' => 'Via']);

        $this->actingAs($via);
        Livewire::test(\Modules\Limousine\Livewire\CustomerSummary::class, ['id' => $customer->id])->call('openFee')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        Livewire::test(UserManager::class)->assertSeeHtml('data-late-fees')->call('toggleLateFees', $via->id);
        $this->assertTrue((bool) $via->fresh()?->can_charge_late_fees);

        $this->actingAs($via->fresh() ?? $via);
        Livewire::test(\Modules\Limousine\Livewire\CustomerSummary::class, ['id' => $customer->id])
            ->call('openFee')->assertSet('charging', true)
            ->set('feeAmount', '10')->set('feePeriod', 'November 2026')
            ->call('saveFee')->assertHasNoErrors();
        $this->assertSame(1, \Modules\Limousine\Models\LimoInvoice::query()->where('customer_id', $customer->id)->count());
    }
}
