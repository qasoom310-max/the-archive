<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoCustomer;
use Tests\TestCase;

/**
 * The shared query behind every "active, plus whatever is already selected"
 * picker list (customers, vehicles, drivers, branches). Exercised once here
 * via LimoCustomer — every model using the trait shares this exact query.
 */
final class ActiveOrSelectedTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_active_records_are_returned(): void
    {
        $active = LimoCustomer::query()->create(['name' => 'Active One', 'active' => true]);

        $this->assertTrue(LimoCustomer::activeOrSelected(null)->contains('id', $active->id));
    }

    public function test_an_inactive_unselected_record_is_left_out(): void
    {
        $inactive = LimoCustomer::query()->create(['name' => 'Retired', 'active' => false]);

        $this->assertFalse(LimoCustomer::activeOrSelected(null)->contains('id', $inactive->id));
    }

    /** The exact bug this trait exists to fix: an archived record still on a saved record. */
    public function test_an_inactive_but_currently_selected_record_is_kept(): void
    {
        $inactive = LimoCustomer::query()->create(['name' => 'Retired', 'active' => false]);

        $this->assertTrue(LimoCustomer::activeOrSelected($inactive->id)->contains('id', $inactive->id));
    }

    public function test_a_different_inactive_record_is_not_pulled_in_by_someone_elses_selection(): void
    {
        $selected = LimoCustomer::query()->create(['name' => 'Selected', 'active' => true]);
        $otherInactive = LimoCustomer::query()->create(['name' => 'Someone Else', 'active' => false]);

        $this->assertFalse(LimoCustomer::activeOrSelected($selected->id)->contains('id', $otherInactive->id));
    }

    public function test_results_are_ordered_by_name(): void
    {
        LimoCustomer::query()->create(['name' => 'Zainab', 'active' => true]);
        LimoCustomer::query()->create(['name' => 'Ahmed', 'active' => true]);

        $this->assertSame(['Ahmed', 'Zainab'], LimoCustomer::activeOrSelected(null)->pluck('name')->all());
    }
}
