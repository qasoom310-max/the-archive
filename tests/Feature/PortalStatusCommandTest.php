<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Tests\TestCase;

/**
 * The read-only twin of Settings → Service Portal's write-only secret field —
 * reports whether a secret is set without ever printing it.
 */
final class PortalStatusCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_reports_unconfigured_when_nothing_is_set(): void
    {
        app(ModuleManager::class)->install('limousine');

        $this->artisan('portal:status')
            ->expectsOutputToContain('Portal URL set: no')
            ->expectsOutputToContain('Shared secret set: no')
            ->assertSuccessful();
    }

    public function test_it_reports_configured_without_printing_the_secret(): void
    {
        app(ModuleManager::class)->install('limousine');

        LimoPortalConfiguration::query()->create([
            'portal_url' => 'https://example.test',
            'shared_secret' => 'super-secret-value',
            'enabled' => true,
        ]);

        $this->artisan('portal:status')
            ->expectsOutputToContain('Portal URL set: yes (https://example.test)')
            ->expectsOutputToContain('Shared secret set: yes')
            ->doesntExpectOutputToContain('super-secret-value')
            ->assertSuccessful();
    }

    public function test_an_unknown_workspace_fails_instead_of_falling_through(): void
    {
        $this->artisan('portal:status', ['--workspace' => '999'])
            ->assertFailed();
    }
}
