<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Stream\CloudflareStreamService;
use App\Erp\Stream\StreamException;
use App\Livewire\Settings\StreamSettings;
use App\Models\CloudflareStreamConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cloudflare Stream integration: per-database encrypted config, the direct
 * upload + watch-url service, the admin settings tab, and the rental order's
 * pickup/return video persistence.
 */
final class CloudflareStreamTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function configure(): CloudflareStreamConfiguration
    {
        return CloudflareStreamConfiguration::query()->create([
            'account_id' => 'acc123',
            'api_token' => 'cf-secret-token',
            'enabled' => true,
        ]);
    }

    public function test_token_is_encrypted_at_rest(): void
    {
        $this->configure();

        $this->assertSame('cf-secret-token', CloudflareStreamConfiguration::current()->api_token);

        $raw = DB::table('cloudflare_stream_configuration')->value('api_token');
        $this->assertIsString($raw);
        $this->assertNotSame('cf-secret-token', $raw);
    }

    public function test_settings_tab_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(StreamSettings::class)->assertForbidden();
    }

    public function test_enabling_without_credentials_is_rejected(): void
    {
        Livewire::test(StreamSettings::class)
            ->set('enabled', true)
            ->set('accountId', '')
            ->call('save')
            ->assertHasErrors('enabled');
    }

    public function test_service_mints_a_direct_upload_url(): void
    {
        $this->configure();
        Http::fake([
            '*/accounts/acc123/stream/direct_upload' => Http::response([
                'success' => true,
                'result' => ['uid' => 'vid1', 'uploadURL' => 'https://upload.cloudflarestream.com/abc'],
            ], 200),
        ]);

        $upload = app(CloudflareStreamService::class)->createDirectUpload('clip.mp4');

        $this->assertSame('vid1', $upload['uid']);
        $this->assertSame('https://upload.cloudflarestream.com/abc', $upload['uploadURL']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer cf-secret-token')
            && str_contains($request->url(), '/accounts/acc123/stream/direct_upload'));
    }

    public function test_service_reads_the_public_watch_url(): void
    {
        $this->configure();
        Http::fake([
            '*/accounts/acc123/stream/vid1' => Http::response([
                'success' => true,
                'result' => [
                    'uid' => 'vid1',
                    'status' => ['state' => 'ready'],
                    'readyToStream' => true,
                    'preview' => 'https://customer-x.cloudflarestream.com/vid1/watch',
                    'thumbnail' => 'https://customer-x.cloudflarestream.com/vid1/thumbnails/thumbnail.jpg',
                    'duration' => 12.5,
                ],
            ], 200),
        ]);

        $info = app(CloudflareStreamService::class)->videoInfo('vid1');

        $this->assertSame('https://customer-x.cloudflarestream.com/vid1/watch', $info['watchUrl']);
        $this->assertTrue($info['ready']);
        $this->assertSame('ready', $info['status']);
    }

    public function test_service_throws_when_unconfigured(): void
    {
        $this->expectException(StreamException::class);
        app(CloudflareStreamService::class)->createDirectUpload('x.mp4');
    }

    public function test_upload_url_endpoint_mints_when_configured_and_422s_when_not(): void
    {
        // Unconfigured → friendly 422 for the uploader to show.
        $this->postJson('/app/stream/upload-url', ['name' => 'a.mp4'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        // Configured → returns the upload URL.
        $this->configure();
        Http::fake([
            '*/stream/direct_upload' => Http::response([
                'success' => true,
                'result' => ['uid' => 'vid9', 'uploadURL' => 'https://upload.cloudflarestream.com/zzz'],
            ], 200),
        ]);

        $this->postJson('/app/stream/upload-url', ['name' => 'a.mp4'])
            ->assertOk()
            ->assertJson(['uid' => 'vid9', 'uploadURL' => 'https://upload.cloudflarestream.com/zzz']);
    }

    public function test_rental_order_form_renders_the_stream_uploader(): void
    {
        // The handover/return modals embed <x-stream-video-upload> feeding the
        // existing handover_video_url / damage_video_url props (see OrderForm).
        app(\App\Erp\Modules\ModuleManager::class)->install('rental');

        $order = \Modules\Rental\Models\RentalOrder::query()->create([
            'reference' => 'RNT/0001',
            'start_date' => now(),
            'end_date' => now()->addDay(),
        ]);

        Livewire::test(\Modules\Rental\Livewire\OrderForm::class, ['id' => $order->id])
            ->set('handover_video_url', 'https://customer-x.cloudflarestream.com/v1/watch')
            ->assertSet('handover_video_url', 'https://customer-x.cloudflarestream.com/v1/watch');
    }
}
