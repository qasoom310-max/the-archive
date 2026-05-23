<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Pos\Imports\ImportReport;
use Modules\Pos\Services\PosProductImporter;
use Throwable;

/**
 * Three-stage product import: upload → preview → commit.
 *
 * Why we cache the ImportReport instead of holding it as a public prop:
 * Livewire 3 needs typed object properties to be Wireable, and even with
 * Wireable implemented the nested-array marker format (`{"s":"arr"}`) that
 * wraps every associative entry on the wire is fiddly to round-trip
 * without losing data. We cache the whole report under a random key for
 * 1 hour and only keep the key on the component — a single string. That
 * keeps the wire payload tiny, sidesteps Livewire's serialisation rules
 * entirely, and lets `commit()` rebuild the exact preview the user
 * confirmed even if the original tmp upload is reaped by Hostinger.
 *
 * Gated by `pos.product` Create (a Cashier without Create can preview
 * but not commit — same rule as adding a product through the form).
 */
#[Layout('components.layouts.app')]
#[Title('Import POS Products')]
final class PosProductImport extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    public string $stage = 'upload'; // upload | preview | done

    /**
     * Cache key under which the in-progress ImportReport is parked. Null
     * before preview, set by `storeReport()`, cleared by `restart()`.
     */
    public ?string $reportCacheKey = null;

    /** Cache TTL in seconds — long enough for a leisurely Confirm click. */
    private const REPORT_TTL = 3600;

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
        $this->storeReport($report);
        $this->stage = 'preview';
    }

    public function commit(): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Create);

        $importer = app(PosProductImporter::class);
        $report = $this->buildCommitReport($importer);

        if ($report === null) {
            // Both the file AND the cached preview are gone — surface a
            // real error and drop the user back on upload so they can
            // pick the file again. (Pre-fix this branch silently returned,
            // leaving the user staring at a button that did nothing.)
            $this->storeReport(new ImportReport(fileErrors: [
                'Your uploaded file is no longer available. Please re-upload and try again.',
            ]));
            $this->stage = 'preview';
            $this->file = null;

            return;
        }

        if ($report->isFatal()) {
            $this->storeReport($report);
            $this->stage = 'preview';

            return;
        }

        try {
            $applied = $importer->apply($report);
        } catch (Throwable $e) {
            // Any DB-level failure (charset, NOT NULL, unique constraint…)
            // bubbled up silently before. Now we surface it on the preview
            // panel so the user sees what blew up instead of staring at
            // an inert Confirm button.
            $this->storeReport(new ImportReport(fileErrors: [
                'Import failed: ' . $e->getMessage(),
            ]));
            $this->stage = 'preview';

            return;
        }

        $this->storeReport($applied);
        $this->stage = 'done';
    }

    public function restart(): void
    {
        if ($this->reportCacheKey !== null) {
            Cache::forget($this->reportCacheKey);
        }
        $this->reset(['file', 'reportCacheKey', 'stage']);
        $this->stage = 'upload';
    }

    /**
     * Source the import report for `commit()`. Prefers a fresh parse of
     * the still-uploaded file (catches barcode collisions / row edits
     * that landed since preview); falls back to the cached preview report
     * when Livewire's tmp upload has been reaped by Hostinger's janitor
     * between Preview and Confirm.
     */
    private function buildCommitReport(PosProductImporter $importer): ?ImportReport
    {
        if ($this->file !== null) {
            $path = (string) $this->file->getRealPath();
            if ($path !== '' && is_file($path)) {
                return $importer->parse($path);
            }
        }

        return $this->loadReport();
    }

    /**
     * Persist the report in the cache and remember its key on the
     * component. Generates a fresh key on first store so re-running
     * preview doesn't accidentally read a stale report.
     */
    private function storeReport(ImportReport $report): void
    {
        $this->reportCacheKey ??= 'pos-import:' . Str::uuid()->toString();
        Cache::put($this->reportCacheKey, $report, self::REPORT_TTL);
    }

    public function loadReport(): ?ImportReport
    {
        if ($this->reportCacheKey === null) {
            return null;
        }

        $value = Cache::get($this->reportCacheKey);

        return $value instanceof ImportReport ? $value : null;
    }

    public function render(): View
    {
        return view('pos::product-import', [
            'result' => $this->loadReport(),
        ]);
    }
}
