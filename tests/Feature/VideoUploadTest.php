<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\VideoUploadController;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rental videos stored on this server (erp.video_storage = local) instead of
 * Cloudflare Stream, uploaded in chunks.
 */
final class VideoUploadTest extends TestCase
{
    use DatabaseMigrations;

    /** The start of a real MP4 container — enough for finfo to call it video/mp4. */
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function sendChunk(string $bytes, int $index, int $total, string $id = 'upload-id-0123456789'): TestResponse
    {
        return $this->post('/app/video/upload-chunk', [
            'upload_id' => $id,
            'index' => $index,
            'total' => $total,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_default_is_this_server_not_cloudflare(): void
    {
        $this->assertSame('local', config('erp.video_storage'));
    }

    public function test_chunks_are_joined_into_one_video_on_the_public_disk(): void
    {
        $this->actingAs(User::factory()->create());
        $whole = self::MP4 . str_repeat("\x01", 100);

        $this->sendChunk(substr($whole, 0, 20), 0, 2)->assertOk()->assertJson(['received' => 1]);
        $response = $this->sendChunk(substr($whole, 20), 1, 2)->assertOk();

        $path = (string) $response->json('path');
        $this->assertMatchesRegularExpression('#^rental_orders/videos/\d{4}/\d{2}/[A-Za-z0-9]{40}\.mp4$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame($whole, Storage::disk('public')->get($path));
        $this->assertStringContainsString($path, (string) $response->json('url'));

        // The chunks are cleaned up once the video is assembled.
        $this->assertSame([], Storage::disk('local')->allFiles(VideoUploadController::CHUNK_DIRECTORY));
    }

    public function test_a_file_that_is_not_a_video_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->sendChunk('<?php echo "hello";', 0, 1)->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_missing_chunk_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        // Chunk 0 never arrived.
        $this->sendChunk(self::MP4, 1, 2)->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_one_user_cannot_finish_another_users_upload(): void
    {
        $this->actingAs(User::factory()->create());
        $this->sendChunk(substr(self::MP4, 0, 20), 0, 2)->assertOk();

        $this->actingAs(User::factory()->create());
        $this->sendChunk(substr(self::MP4, 20), 1, 2)->assertStatus(422);
    }

    public function test_a_bad_upload_id_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->sendChunk(self::MP4, 0, 1, '../../etc')->assertStatus(422);
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->sendChunk(self::MP4, 0, 1)->assertUnauthorized();
    }
}
