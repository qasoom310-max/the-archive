<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\FormView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoExpense;
use Tests\TestCase;

/**
 * VAT on a limousine expense: the office types the amount before VAT and
 * picks none, 10% or 15%; the VAT and the total are worked out, never typed.
 */
final class LimoExpenseVatTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_the_vat_and_total_are_worked_out_from_the_amount_before_vat(): void
    {
        foreach ([[0, 100.0, 0.0, 100.0], [10, 100.0, 10.0, 110.0], [15, 100.0, 15.0, 115.0], [10, 12.345, 1.235, 13.58]] as [$rate, $net, $vat, $total]) {
            $expense = LimoExpense::query()->create(['date' => '2026-11-16', 'category' => 'fuel', 'net_amount' => $net, 'vat_rate' => $rate]);

            $this->assertSame($vat, round($expense->vat_amount, 3), "VAT at {$rate}%");
            $this->assertSame($total, round($expense->amount, 3), "total at {$rate}%");
        }
    }

    /** A typed total cannot survive: the total is always before-VAT + VAT. */
    public function test_the_total_cannot_be_typed(): void
    {
        $expense = LimoExpense::query()->create(['date' => '2026-11-16', 'net_amount' => 100, 'vat_rate' => 10, 'amount' => 999, 'vat_amount' => 500]);

        $this->assertSame(110.0, $expense->refresh()->amount);
        $this->assertSame(10.0, $expense->vat_amount);
    }

    public function test_a_rate_that_is_not_offered_counts_as_no_vat(): void
    {
        $expense = LimoExpense::query()->create(['date' => '2026-11-16', 'net_amount' => 100, 'vat_rate' => 25]);

        $this->assertSame(0, $expense->vat_rate);
        $this->assertSame(100.0, $expense->amount);
    }

    /** Petty cash and the importer still write `amount` alone; it stays what they wrote. */
    public function test_an_expense_written_with_only_its_amount_keeps_that_amount(): void
    {
        $expense = LimoExpense::query()->create(['date' => '2026-11-16', 'category' => 'parking', 'amount' => 7.5]);

        $this->assertSame([7.5, 7.5, 0, 0.0], [$expense->amount, $expense->net_amount, $expense->vat_rate, $expense->vat_amount]);
    }

    public function test_changing_the_rate_later_re_prices_the_expense(): void
    {
        $expense = LimoExpense::query()->create(['date' => '2026-11-16', 'net_amount' => 200]);
        $this->assertSame(200.0, $expense->amount);

        $expense->update(['vat_rate' => 15]);
        $this->assertSame([30.0, 230.0], [$expense->vat_amount, $expense->amount]);

        $expense->update(['vat_rate' => 0]);
        $this->assertSame([0.0, 200.0], [$expense->vat_amount, $expense->amount]);
    }

    public function test_the_form_takes_the_amount_before_vat_and_shows_the_result(): void
    {
        $expense = LimoExpense::query()->create(['date' => '2026-11-16', 'net_amount' => 50]);

        $form = Livewire::test(FormView::class, ['model' => LimoExpense::class, 'modelKey' => 'limousine.expense', 'recordId' => $expense->id])
            ->assertSee('Amount before VAT (BHD)')
            ->assertSee('VAT 10%')
            ->assertSee('VAT 15%')
            ->assertSee('No VAT')
            ->set('form.net_amount', '100')
            ->set('form.vat_rate', '15')
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertSame([100.0, 15, 15.0, 115.0], [$expense->net_amount, $expense->vat_rate, $expense->vat_amount, $expense->amount]);

        // The read-only boxes show what was worked out, not what they held on opening.
        $form->assertSet('form.vat_amount', 15.0)->assertSet('form.amount', 115.0);

        // The total box is read-only: a crafted request cannot set it.
        $form->set('form.amount', '5');
        $this->assertSame(115.0, $expense->refresh()->amount);
    }

    public function test_the_migration_reads_old_expenses_as_before_vat_with_no_vat(): void
    {
        DB::table('limo_expenses')->insert(['date' => '2026-10-01', 'category' => 'fuel', 'amount' => 42.5, 'net_amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $migration = require base_path('Modules/Limousine/database/migrations/2026_11_16_950058_add_vat_to_limo_expenses.php');
        $migration->up();

        $row = DB::table('limo_expenses')->first();
        $this->assertSame([42.5, 42.5, 0.0], [(float) $row->amount, (float) $row->net_amount, (float) $row->vat_amount]);
    }
}
