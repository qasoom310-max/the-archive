<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoExpense;
use Tests\TestCase;

/** New limousine expenses are numbered RF/L/EXP123, RF/L/EXP124… (the owner's format). */
final class LimoExpenseReferenceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function expense(?string $reference = null): LimoExpense
    {
        return LimoExpense::query()->create(['reference' => $reference, 'date' => '2026-11-17', 'net_amount' => 10]);
    }

    public function test_new_expenses_start_at_rf_l_exp_123_and_count_up(): void
    {
        $this->assertSame('RF/L/EXP123', $this->expense()->refresh()->reference);
        $this->assertSame('RF/L/EXP124', $this->expense()->refresh()->reference);
    }

    public function test_old_references_are_left_alone_and_do_not_count(): void
    {
        $old = $this->expense('EXP/00042');
        $this->assertSame('EXP/00042', $old->refresh()->reference);
        $this->assertSame('RF/L/EXP123', $this->expense()->refresh()->reference);
    }

    public function test_numbering_follows_the_highest_number_on_file(): void
    {
        $this->expense('RF/L/EXP200');
        $this->expense('RF/L/EXP150');
        $this->assertSame('RF/L/EXP201', $this->expense()->refresh()->reference);
    }

    /** A number given under the short-lived earlier name is never given again. */
    public function test_the_earlier_spelling_still_counts_so_no_number_repeats(): void
    {
        $this->expense('Rf/Exp130');
        $this->assertSame('RF/L/EXP131', $this->expense()->refresh()->reference);
    }

    public function test_the_earlier_spelling_is_renamed_keeping_its_number(): void
    {
        $earlier = $this->expense('Rf/Exp125');
        $old = $this->expense('EXP/00042');
        $typed = $this->expense('Rf/Exp-fuel');

        $migration = require base_path('Modules/Limousine/database/migrations/2026_11_17_950061_rename_limo_expense_references.php');
        $migration->up();

        $this->assertSame('RF/L/EXP125', $earlier->refresh()->reference);
        $this->assertSame('EXP/00042', $old->refresh()->reference);
        $this->assertSame('Rf/Exp-fuel', $typed->refresh()->reference);
    }

    public function test_the_reference_shows_inside_the_expense_new_and_saved(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();

        $this->get('/app/limousine/expense/new')->assertOk()->assertSee('RF/L/EXP123')->assertSee('Given when you save.');

        $expense = $this->expense();
        $this->get('/app/limousine/expense/' . $expense->id)->assertOk()->assertSee('RF/L/EXP123')->assertDontSee('Given when you save.');
    }
}
