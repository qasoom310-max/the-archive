<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Tests\TestCase;

/**
 * The terminal's product grid must never hide a product without saying so.
 *
 * Two ways it used to: a product saved with NO category belonged to no chip at
 * all (so a cashier browsing by category could never reach it — they'd swear it
 * "isn't in the system"), and the grid hard-capped at 60 products with no hint
 * that the rest existed.
 */
final class PosTerminalProductGridTest extends TestCase
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

    public function test_an_uncategorised_product_is_reachable_from_the_grid(): void
    {
        $session = $this->openSession();
        $packages = PosCategory::query()->create(['name' => 'Kaleem packages', 'active' => true, 'sequence' => 1]);

        PosProduct::query()->create(['name' => 'Red package', 'price' => 20, 'tax_rate' => 0, 'active' => true, 'pos_category_id' => $packages->id]);
        // Saved without a category — the exact shape of the bug.
        PosProduct::query()->create(['name' => 'Red Package Kaleem 5x', 'price' => 20, 'tax_rate' => 0, 'active' => true]);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id]);

        // "All" shows both, and the Uncategorised chip is offered.
        $component->assertSee('Red package')
            ->assertSee('Red Package Kaleem 5x')
            ->assertSee('Uncategorised');

        // Browsing the category chip shows ONLY that category's product — this is
        // what Hussain saw, and why the uncategorised one looked missing.
        $component->call('selectCategory', $packages->id)
            ->assertSee('Red package')
            ->assertDontSee('Red Package Kaleem 5x');

        // The Uncategorised chip is the way back to it.
        $component->call('selectUncategorised')
            ->assertSee('Red Package Kaleem 5x')
            ->assertDontSee('Red package');
    }

    public function test_the_uncategorised_chip_is_hidden_when_every_product_has_a_category(): void
    {
        $session = $this->openSession();
        $cat = PosCategory::query()->create(['name' => 'Perfumes', 'active' => true, 'sequence' => 1]);
        PosProduct::query()->create(['name' => 'Oud', 'price' => 10, 'tax_rate' => 0, 'active' => true, 'pos_category_id' => $cat->id]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])->assertDontSee('Uncategorised');
    }

    public function test_a_capped_grid_tells_the_cashier_the_rest_are_hidden(): void
    {
        $session = $this->openSession();

        // 65 products — more than the 60 the grid renders.
        for ($i = 1; $i <= 65; $i++) {
            PosProduct::query()->create([
                'name' => sprintf('Perfume %02d', $i),
                'price' => 5, 'tax_rate' => 0, 'active' => true,
            ]);
        }

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertSet('uncategorised', false)
            ->assertSee('Showing 60 of 65 products.')
            ->assertSee('Search by name or pick a category to find the rest.');
    }

    public function test_no_notice_when_everything_fits(): void
    {
        $session = $this->openSession();
        PosProduct::query()->create(['name' => 'Oud', 'price' => 10, 'tax_rate' => 0, 'active' => true]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertDontSee('Search by name or pick a category to find the rest.');
    }
}
