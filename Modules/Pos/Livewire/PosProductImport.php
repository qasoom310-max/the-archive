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

        $importer = app(PosProductImporter::class);
        $report = $this->buildCommitReport($importer);

        if ($report === null) {
            // File gone AND no buffered preview to fall back on — surface
            // a real error rather than silently returning (which left the
            // user staring at a button that did nothing). Drops back to
            // the upload stage so they can re-pick the file.
            $this->result = new ImportReport(fileErrors: [
                'Your uploaded file is no longer available. Please re-upload and try again.',
            ]);
            $this->stage = 'preview';
            $this->file = null;

            return;
        }

        if ($report->isFatal()) {
            $this->result = $report;
            $this->stage = 'preview';

            return;
        }

        $this->result = $importer->apply($report);
        $this->stage = 'done';
    }

    /**
     * Source the import report for `commit()`. Prefers a fresh parse of
     * the still-uploaded file (catches barcode collisions / row edits
     * that landed since preview); falls back to the Wireable-buffered
     * preview report when Livewire's tmp upload has been cleaned up
     * between Preview and Confirm (Hostinger's tmp janitor is known to
     * do this, leaving `$this->file` non-null but pointing at a missing
     * path — pre-Wireable that meant a fatal `parse()` and a silent
     * commit; now `$this->result` round-trips reliably across the wire,
     * so the user's already-validated preview survives even when the
     * underlying file does not).
     */
    private function buildCommitReport(PosProductImporter $importer): ?ImportReport
    {
        if ($this->file !== null) {
            $path = (string) $this->file->getRealPath();
            if ($path !== '' && is_file($path)) {
                return $importer->parse($path);
            }
        }

        return $this->result;
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
