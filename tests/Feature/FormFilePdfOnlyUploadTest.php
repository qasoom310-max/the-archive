<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The FormView `file` widget can be locked to PDF-only via `only=pdf` (set by a
 * field declaring accept:'pdf', e.g. a car's registration / insurance papers).
 * Server-side it rejects anything that isn't a PDF.
 */
final class FormFilePdfOnlyUploadTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_pdf_only_rejects_an_image(): void
    {
        $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
            'bucket' => 'rental_vehicles',
            'only' => 'pdf',
        ])->assertSessionHasErrors('file');
    }

    public function test_pdf_only_accepts_a_pdf(): void
    {
        $response = $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->create('registration.pdf', 100, 'application/pdf'),
            'bucket' => 'rental_vehicles',
            'only' => 'pdf',
        ]);

        $response->assertOk();
        $path = $response->json('path');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_without_the_flag_an_image_is_still_accepted(): void
    {
        // Other models (e.g. accounts) keep the PDF + image behaviour.
        $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->image('receipt.png'),
            'bucket' => 'accounts',
        ])->assertOk();
    }
}
