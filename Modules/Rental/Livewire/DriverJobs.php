<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Services\DriverJobHistory;

/**
 * A driver's job history: searchable, and paged rather than endless.
 *
 * Four jobs a day is a hundred and twenty a month, so the whole list on one
 * screen would be a scroll with no bottom. Ten at a time by default, with the
 * page size in the office's hands — someone auditing a driver wants five
 * hundred at once, and being told they can only have ten is its own annoyance.
 */
final class DriverJobs extends Component
{
    use WithPagination;

    /** Whose jobs. Server-set: the browser must not repoint it at someone else. */
    #[Locked]
    public int $driverId = 0;

    /** Reference, car, who gave it out, status, date. */
    public string $search = '';

    public int $perPage = self::PER_PAGE_DEFAULT;

    public const PER_PAGE_DEFAULT = 10;

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100, 500];

    public function mount(int $driverId): void
    {
        $this->driverId = $driverId;
    }

    public function updatedSearch(): void
    {
        // Typing while deep in the list would otherwise leave the user on a page
        // the narrowed results no longer have.
        $this->resetPage('jobsPage');
    }

    public function updatedPerPage(): void
    {
        $this->resetPage('jobsPage');
    }

    public function setPerPage(int $size): void
    {
        if (! in_array($size, self::PER_PAGE_OPTIONS, true)) {
            return;
        }

        $this->perPage = $size;
        $this->resetPage('jobsPage');
    }

    public function render(): View
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->driverId > 0
            ? app(DriverJobHistory::class)->for($this->driverId, $this->search)
            : [];

        // Merged from two sources in PHP, so the page is taken here rather than
        // in SQL — the whole point is that the two businesses read as one list.
        $page = max(1, (int) $this->getPage('jobsPage'));
        $perPage = in_array($this->perPage, self::PER_PAGE_OPTIONS, true)
            ? $this->perPage
            : self::PER_PAGE_DEFAULT;

        $jobs = new LengthAwarePaginator(
            new Collection(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            count($rows),
            $perPage,
            $page,
            ['pageName' => 'jobsPage'],
        );

        return view('rental::driver-jobs', [
            'jobs' => $jobs,
            'total' => count($rows),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }
}
