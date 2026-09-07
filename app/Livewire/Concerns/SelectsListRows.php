<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Row checkboxes on a bespoke list, so Copy / CSV / Excel / PDF / Print can
 * be narrowed to just the ticked rows. Nothing ticked = the whole tab, as
 * before, so the buttons never change meaning underfoot.
 *
 * The host provides {@see currentPageIds()} — the ids of the rows on the page
 * being looked at — and includes `'ids' => $this->selectedIdsParam()` in its
 * export links. Changing the tab must call {@see clearSelection()}: a tick
 * made on one tab is not a tick on another.
 */
trait SelectsListRows
{
    /** @var list<int|string> ids of the ticked rows (the browser sends strings) */
    public array $selected = [];

    /** The header checkbox: tick / untick every row on the current page. */
    public bool $selectPage = false;

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value ? $this->currentPageIds() : [];
    }

    public function updatedSelected(): void
    {
        // Ticking rows by hand never leaves the header box claiming "all".
        $this->selectPage = false;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    /** Comma-joined for the export links; '' when nothing is ticked. */
    public function selectedIdsParam(): string
    {
        return implode(',', array_map('intval', $this->selected));
    }

    public function isSelected(int $id): bool
    {
        return in_array($id, array_map('intval', $this->selected), true);
    }

    /**
     * @return list<int>
     */
    abstract protected function currentPageIds(): array;
}
