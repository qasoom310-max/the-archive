<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Support\ExpenseVatReport;

/**
 * The quarterly VAT report on limousine expenses: read-only, in BHD, with
 * the Bahrain VAT to claim kept apart from VAT paid abroad.
 */
#[Layout('components.layouts.app')]
#[Title('VAT report')]
final class ExpenseVat extends Component
{
    use GuardsModelAccess;

    #[Url]
    public int $year = 0;

    #[Url]
    public int $quarter = 0;

    protected function accessModelKey(): string
    {
        return 'limousine.expense';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        request()->attributes->set('breadcrumb_terminal_label', __('VAT report'));

        [$this->year, $this->quarter] = self::clamp($this->year, $this->quarter);
    }

    public function setQuarter(int $year, int $quarter): void
    {
        [$this->year, $this->quarter] = self::clamp($year, $quarter);
    }

    /**
     * The address bar can carry anything; a quarter nobody could mean
     * becomes the one today falls in.
     *
     * @return array{0: int, 1: int}
     */
    public static function clamp(int $year, int $quarter): array
    {
        $now = ExpenseVatReport::current();

        return $year >= 2020 && $year <= $now->year + 1 && $quarter >= 1 && $quarter <= 4
            ? [$year, $quarter]
            : [$now->year, $now->quarter];
    }

    public function render(): View
    {
        $this->guardAccess(Permission::Read);

        [$year, $quarter] = self::clamp($this->year, $this->quarter);
        $report = new ExpenseVatReport($year, $quarter);

        // The quarters that can be picked: from the first expense to today.
        $first = LimoExpense::query()->min('date');
        $firstYear = max(2020, $first !== null ? (int) Carbon::parse((string) $first)->year : $year);
        $nowYear = ExpenseVatReport::current()->year;

        return view('limousine::expense-vat', [
            'report' => $report->report(),
            'from' => $report->from,
            'to' => $report->to,
            'shownYear' => $year,
            'shownQuarter' => $quarter,
            'years' => range(max($nowYear, $year), min($firstYear, $year)),
            'categories' => array_column(LimoExpense::categoryOptions(), 'label', 'value'),
        ]);
    }
}
