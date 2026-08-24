<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\CompanyTimezone;
use App\Erp\Settings\Setting;
use App\Erp\Tenancy\EachDatabase;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scheduled work runs on Main's connection, so anything per-database — the
 * 6 AM sales report, the discount expiry sweep — only ever ran against Main. A
 * second database never received its report and its lapsed discounts kept
 * showing as active. A workspace also inherited Main's timezone, which puts its
 * sales on the wrong day in exactly the databases meant to be separate.
 */
final class ScheduledTenantTasksTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_daily_task_runs_against_every_database(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        app(WorkspaceManager::class)->provision('Second shop', $owner, []);

        $seen = [];
        $count = EachDatabase::run(function (string $label) use (&$seen): void {
            $seen[] = $label . ':' . DB::connection()->getDatabaseName();
        });

        $this->assertSame(2, $count, 'Main and the workspace should both run');
        $this->assertCount(2, $seen);
        $this->assertNotSame($seen[0], $seen[1], 'each ran against its own database');
    }

    public function test_a_failure_on_one_database_does_not_stop_the_others(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        app(WorkspaceManager::class)->provision('Second shop', $owner, []);

        $ran = 0;
        $count = EachDatabase::run(function (string $label) use (&$ran): void {
            $ran++;

            if ($ran === 1) {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertSame(2, $ran, 'the second database still ran');
        $this->assertSame(1, $count, 'only the successful one is counted');
    }

    public function test_a_workspace_uses_its_own_timezone(): void
    {
        Setting::set('company.timezone', 'Asia/Bahrain');
        CompanyTimezone::apply();
        $this->assertSame('Asia/Bahrain', date_default_timezone_get());

        $owner = User::factory()->create(['is_admin' => true]);
        $workspace = app(WorkspaceManager::class)->provision('Tokyo shop', $owner, []);
        $path = $workspace->databasePath();
        $this->assertNotNull($path);

        $manager = app(WorkspaceManager::class);
        $manager->withTenant($path, static function (): void {
            Setting::set('company.timezone', 'Asia/Tokyo');
        });

        $inside = $manager->withTenant($path, static fn (): string => date_default_timezone_get());

        $this->assertSame('Asia/Tokyo', $inside, 'the workspace must use its own timezone');
        $this->assertSame('Asia/Bahrain', date_default_timezone_get(), 'and Main must be put back');
    }
}
