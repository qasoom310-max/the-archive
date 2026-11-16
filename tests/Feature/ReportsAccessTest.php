<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The owner's reports (Daily Summary, Profit & Expenses, Car income, Payroll)
 * can be given to a named person who is not an admin (users.reports_access).
 */
final class ReportsAccessTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    /** @return list<string> */
    private function reportUrls(): array
    {
        return ['/reports/daily-summary', '/reports/profit', '/reports/car-income', '/hr/payroll', '/hr/employees'];
    }

    public function test_a_staff_member_without_the_grant_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->reportUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_granted_user_opens_every_report_and_sees_only_the_report_tiles(): void
    {
        $this->actingAs(User::factory()->create(['reports_access' => true]));

        foreach ($this->reportUrls() as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('Daily Summary')
            ->assertSee('Profit &amp; Expenses', false)
            ->assertSee('Car income')
            ->assertSee('Payroll')
            // The owner's other tools stay the owner's.
            ->assertDontSee('Website fares')
            ->assertDontSee('Saved logins');
    }

    public function test_a_regular_admin_still_cannot_see_car_income(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/reports/daily-summary')->assertOk();
        $this->get('/reports/car-income')->assertForbidden();
    }

    public function test_the_migration_grants_prejitha_by_name_only(): void
    {
        $prejitha = User::factory()->create(['name' => 'Prejitha', 'reports_access' => false]);
        $other = User::factory()->create(['name' => 'Hasan', 'reports_access' => false]);

        $migration = require database_path('migrations/2026_11_16_100001_add_reports_access_to_users.php');
        $migration->up();

        $this->assertTrue((bool) DB::table('users')->where('id', $prejitha->id)->value('reports_access'));
        $this->assertFalse((bool) DB::table('users')->where('id', $other->id)->value('reports_access'));
    }
}
