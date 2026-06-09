<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Views\FormView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Enums\AccountType;
use Tests\TestCase;

/**
 * The asset-tracking fields added to a Chart-of-Accounts account: a document
 * upload (PDF/image) plus cost-per-unit and number-of-units — and the generic
 * engine `file` widget + FormFileUploadController that back the upload.
 */
final class AccountFileFieldTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('accounting');
    }

    private function account(): Account
    {
        return Account::query()->create([
            'code' => '100',
            'name' => 'Chair',
            'type' => AccountType::Asset,
        ]);
    }

    public function test_upload_endpoint_stores_a_pdf_and_returns_its_path(): void
    {
        Storage::fake('public');

        $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
            'bucket' => 'accounts',
        ])
            ->assertOk()
            ->assertJsonStructure(['path', 'url', 'name'])
            ->assertJson(['name' => 'invoice.pdf']);

        $this->assertCount(1, Storage::disk('public')->files('accounts'));
    }

    public function test_upload_endpoint_rejects_a_disallowed_type_and_bucket(): void
    {
        Storage::fake('public');

        // Wrong file type.
        $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->create('macro.txt', 5, 'text/plain'),
            'bucket' => 'accounts',
        ])->assertSessionHasErrors('file');

        // Non-whitelisted bucket (path-traversal guard).
        $this->post(route('form.upload-file'), [
            'file' => UploadedFile::fake()->create('invoice.pdf', 5, 'application/pdf'),
            'bucket' => 'etc_passwd',
        ])->assertSessionHasErrors('bucket');

        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_account_form_shows_the_three_asset_fields(): void
    {
        $account = $this->account();

        Livewire::test(FormView::class, [
            'model' => Account::class,
            'modelKey' => 'accounting.account',
            'recordId' => $account->id,
        ])
            ->assertSee('Attachment')
            ->assertSee('Cost / unit')
            ->assertSee('Number of units')
            ->assertSeeHtml('filePaths.document_path');
    }

    public function test_saving_persists_cost_units_and_the_document_path(): void
    {
        $account = $this->account();

        Livewire::test(FormView::class, [
            'model' => Account::class,
            'modelKey' => 'accounting.account',
            'recordId' => $account->id,
        ])
            ->set('form.cost_per_unit', 12.5)
            ->set('form.units', 4)
            ->set('filePaths.document_path', 'accounts/chair-invoice.pdf')
            ->call('save')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame(12.5, (float) $account->cost_per_unit);
        $this->assertSame(4, $account->units);
        $this->assertSame('accounts/chair-invoice.pdf', $account->document_path);
    }
}
