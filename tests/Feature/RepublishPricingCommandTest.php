<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Pricing\PricingVersion;
use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Tests\TestCase;

/**
 * The lever for "the payload computation changed but the data didn't" — see
 * the command's own docblock for why a plain re-save can't do this.
 */
final class RepublishPricingCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->install('limousine');
        $this->artisan('pricing:seed')->assertSuccessful();

        LimoPortalConfiguration::query()->create([
            'portal_url' => 'https://example.test',
            'shared_secret' => 'republish-test-secret',
            'enabled' => true,
        ]);
    }

    public function test_it_bumps_the_version_and_pings_with_the_new_number(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'version' => 999], 200)]);

        $before = PricingVersion::current();

        $this->artisan('pricing:republish')
            ->expectsOutputToContain('Version is now ' . ($before + 1) . '.')
            ->expectsOutputToContain('Website confirmed')
            ->assertSuccessful();

        $this->assertSame($before + 1, PricingVersion::current());

        Http::assertSent(function ($request) use ($before): bool {
            $this->assertStringContainsString('"version":' . ($before + 1), $request->body());

            return true;
        });
    }

    public function test_it_bumps_even_when_the_website_cannot_be_reached(): void
    {
        // The whole point is the version has to move regardless — the
        // website will pick up a genuine change on its next scheduled poll
        // even if this particular ping fails.
        Http::fake(['*' => Http::response('', 500)]);

        $before = PricingVersion::current();

        $this->artisan('pricing:republish')->assertFailed();

        $this->assertSame($before + 1, PricingVersion::current());
    }

    public function test_an_unknown_workspace_fails_instead_of_falling_through(): void
    {
        $this->artisan('pricing:republish', ['--workspace' => '999'])
            ->assertFailed();
    }
}
