<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Http\Controllers\ExpenseBillController;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Services\BillScanner;

/**
 * Scan a bill: take a photo, check what was read, save it as an expense.
 *
 * The reading is a suggestion. Every figure lands in an ordinary box that can
 * be corrected, the photo stays on screen beside them, and nothing becomes an
 * expense until Save is pressed.
 */
#[Layout('components.layouts.app')]
#[Title('Scan a bill')]
final class ExpenseScan extends Component
{
    use GuardsModelAccess;

    /** The scan being reviewed. Set by the server from the upload, never by the browser. */
    #[Locked]
    public string $token = '';

    /**
     * Things the reader was unsure of, to look at twice.
     *
     * @var list<string>
     */
    #[Locked]
    public array $check = [];

    public string $date = '';

    public string $category = 'other';

    public string $payee = '';

    public string $net_amount = '';

    public string $vat_rate = '0';

    public string $notes = '';

    protected function accessModelKey(): string
    {
        return 'limousine.expense';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Create);

        request()->attributes->set('breadcrumb_terminal_label', __('Scan a bill'));
    }

    /** Load the reading the upload just produced. */
    public function review(string $token): void
    {
        $this->guardAccess(Permission::Create);

        $scan = $this->scan($token);
        if ($scan === null) {
            $this->addError('scan', __('That scan has expired. Take the photo again.'));

            return;
        }

        $draft = $scan['draft'];
        $plain = static fn (float $v): string => $v > 0.0 ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') : '';

        $this->token = $token;
        $this->check = array_values($draft['check']);
        $this->date = (string) $draft['date'];
        $this->category = (string) $draft['category'];
        $this->payee = (string) $draft['payee'];
        $this->net_amount = $plain((float) $draft['net_amount']);
        $this->vat_rate = (string) $draft['vat_rate'];
        $this->notes = (string) $draft['notes'];
        $this->resetValidation();
    }

    /** Throw the scan away and start again; its photo goes with it. */
    public function discard(): void
    {
        $scan = $this->token !== '' ? $this->scan($this->token) : null;
        if ($scan !== null) {
            Storage::disk(BillScanner::DISK)->delete($scan['path']);
            Cache::forget(ExpenseBillController::cacheKey($this->token));
        }

        $this->reset(['token', 'check', 'date', 'payee', 'net_amount', 'notes']);
        $this->category = 'other';
        $this->vat_rate = '0';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->guardAccess(Permission::Create);

        $scan = $this->token !== '' ? $this->scan($this->token) : null;
        if ($scan === null) {
            $this->addError('scan', __('That scan has expired. Take the photo again.'));

            return;
        }

        $this->validate([
            'date' => ['required', 'date'],
            'category' => ['required', 'in:' . implode(',', array_column(LimoExpense::categoryOptions(), 'value'))],
            'payee' => ['nullable', 'string', 'max:190'],
            'net_amount' => ['required', 'numeric', 'gt:0'],
            'vat_rate' => ['required', 'in:' . implode(',', LimoExpense::VAT_RATES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'date' => __('Date'), 'category' => __('Category'), 'payee' => __('Paid to'),
            'net_amount' => __('Amount before VAT'), 'vat_rate' => __('VAT'), 'notes' => __('Notes'),
        ]);

        $expense = new LimoExpense([
            'date' => $this->date,
            'category' => $this->category,
            'payee' => trim($this->payee) !== '' ? trim($this->payee) : null,
            'net_amount' => (float) $this->net_amount,
            'vat_rate' => (int) $this->vat_rate,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);
        // From the server's own record of the upload, not from the page.
        $expense->setAttribute('bill_path', $scan['path']);
        $expense->save();

        Cache::forget(ExpenseBillController::cacheKey($this->token));

        session()->flash('toast', __('Expense :ref saved from the scanned bill.', ['ref' => (string) $expense->refresh()->reference]));
        $this->redirect(url('/app/limousine/expense/' . $expense->id), navigate: true);
    }

    /**
     * The parked scan for a token, only if it belongs to the signed-in user.
     *
     * @return array{user: int|string|null, path: string, draft: array<string, mixed>}|null
     */
    private function scan(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $scan = Cache::get(ExpenseBillController::cacheKey($token));

        return is_array($scan) && ($scan['user'] ?? null) === Auth::id() && is_string($scan['path'] ?? null) && is_array($scan['draft'] ?? null)
            ? ['user' => $scan['user'], 'path' => $scan['path'], 'draft' => $scan['draft']]
            : null;
    }

    public function render(): View
    {
        $net = is_numeric($this->net_amount) ? max(0.0, (float) $this->net_amount) : 0.0;
        $rate = in_array((int) $this->vat_rate, LimoExpense::VAT_RATES, true) ? (int) $this->vat_rate : 0;

        return view('limousine::expense-scan', [
            'available' => BillScanner::isAvailable(),
            'reviewing' => $this->token !== '',
            'categories' => LimoExpense::categoryOptions(),
            'vatOptions' => LimoExpense::vatOptions(),
            'vatAmount' => round($net * $rate / 100, 3),
            'total' => round($net + $net * $rate / 100, 3),
        ]);
    }
}
