<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoExpense;
use Tests\TestCase;

/** New limousine expenses are numbered Rf/Exp123, Rf/Exp124… (the owner's format). */
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

    public function test_new_expenses_start_at_rf_exp_123_and_count_up(): void
    {
        $this->assertSame('Rf/Exp123', $this->expense()->refresh()->reference);
        $this->assertSame('Rf/Exp124', $this->expense()->refresh()->reference);
    }

    public function test_old_references_are_left_alone_and_do_not_count(): void
    {
        $old = $this->expense('EXP/00042');
        $this->assertSame('EXP/00042', $old->refresh()->reference);
        $this->assertSame('Rf/Exp123', $this->expense()->refresh()->reference);
    }

    public function test_numbering_follows_the_highest_rf_exp_on_file(): void
    {
        $this->expense('Rf/Exp200');
        $this->expense('Rf/Exp150');
        $this->assertSame('Rf/Exp201', $this->expense()->refresh()->reference);
    }
}
