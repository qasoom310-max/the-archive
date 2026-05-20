<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Pos\Imports\ImportReport;
use Modules\Pos\Services\PosProductImporter;

/**
 * Three-stage product import: upload → preview → commit.
 *
 * The uploaded file stays in Livewire's tmp storage between requests, so we
 * re-parse it on demand (cheap for typical files) rather than serialise the
 * DTO tree onto the wire — keeps the component state small and avoids the
 * ImportRow object-array round-trip in Livewire's hydrator.
 *
 * Gated by `pos.product` Create (a Cashier without Create can preview but
 * not commit — same rule as adding a product through the form).
 */
#[Layout('components.layouts.app')]
#[Title('Import POS Products')]
final class PosProductImport extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    public string $stage = 'upload'; // upload | preview | done

    public ?ImportReport $result = null;

    public function mount(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Read);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        // 2 MB cap, .xlsx or .csv. PhpSpreadsheet auto-detects format.
        return [
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048'],
        ];
    }

    public function preview(): void
    {
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $report = app(PosProductImporter::class)->parse((string) $this->file->getRealPath());
        $this->result = $report;
        $this->stage = 'preview';
    }

    public function commit(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Create);

        if ($this->file === null) {
            return;
        }

        // Re-parse on commit (not trusting any client-side state — file is
        // the source of truth). This also re-checks for barcode collisions
        // that may have changed since preview.
        $importer = app(PosProductImporter::class);
        $report = $importer->parse((string) $this->file->getRealPath());

        if ($report->isFatal()) {
            $this->result = $report;
            $this->stage = 'preview';

            return;
        }

        $this->result = $importer->apply($report);
        $this->stage = 'done';
    }

    public function restart(): void
    {
        $this->reset(['file', 'result', 'stage']);
        $this->stage = 'upload';
    }

    public function render(): View
    {
        return view('pos::product-import');
    }
}
