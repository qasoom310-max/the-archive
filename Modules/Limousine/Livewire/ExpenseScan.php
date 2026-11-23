<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Money\ExchangeRateService;
use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Http\Controllers\ExpenseBillController;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverBill;
use Modules\Limousine\Models\LimoPettyAdvance;
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

    /** A bill a driver sent through his trip link, being checked. Server-set only. */
    #[Locked]
    public int $billId = 0;

    /** The expense "Save and scan next" just saved, to confirm it on the next screen. */
    #[Locked]
    public string $savedReference = '';

    /** No driver: an office bill. */
    public const NO_DRIVER = 'none';

    /**
     * Whose bills are being scanned: a driver's id, NO_DRIVER for office
     * bills, or empty while nothing is chosen yet. Chosen once, then kept for
     * every bill until it is changed, so a driver's pile goes in one run.
     */
    #[Url(as: 'driver', except: '')]
    public string $driver = '';

    /** Not paid from the driver's petty cash. */
    public const NO_ADVANCE = 'none';

    /**
     * Was this bill paid from the cash the driver was given? An open petty-cash
     * advance of THIS driver (its id), NO_ADVANCE, or empty while unanswered.
     * Kept for the whole run, like the driver.
     */
    public string $advance = '';

    /** Bills saved for this driver in this run, and what they add up to (BHD). */
    #[Locked]
    public int $savedCount = 0;

    #[Locked]
    public float $savedTotal = 0.0;

    public string $date = '';

    public string $category = 'other';

    public string $payee = '';

    /** The currency the bill is in. The expense itself is always saved in BHD. */
    public string $currency = 'BHD';

    /** BHD value of one unit of the bill's currency; 1 for a BHD bill. */
    public string $exchange_rate = '1';

    /** The amount before VAT, in the bill's currency. */
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

        // The driver rides in the address, so a refresh keeps the run going.
        // Anything that is not a real driver is dropped.
        if ($this->driver !== self::NO_DRIVER && $this->driverId() === null) {
            $this->driver = '';
        }
        $this->defaultAdvance();

        // A bill a driver sent: opened from "Bills from drivers".
        $bill = request()->query('bill');
        if (is_string($bill) && ctype_digit($bill)) {
            $this->openDriverBill((int) $bill);

            return;
        }

        // A scan can be reopened by its address while it is still parked
        // (the same owner-only check as review() applies).
        $token = request()->query('scan');
        if (is_string($token) && $token !== '' && $this->scan($token) !== null) {
            $this->review($token);
        }
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
        $this->savedReference = '';
        $this->check = array_values($draft['check']);
        $this->date = (string) $draft['date'];
        $this->category = (string) $draft['category'];
        $this->currency = $this->knownCurrency((string) $draft['currency']);
        $this->exchange_rate = $this->rateFor($this->currency);
        $this->payee = (string) $draft['payee'];
        $this->net_amount = $plain((float) $draft['net_amount']);
        $this->vat_rate = (string) $draft['vat_rate'];
        $this->notes = (string) $draft['notes'];
        $this->resetValidation();
    }

    /** A driver's bill, waiting: load what was read, the driver and his cash. */
    private function openDriverBill(int $id): void
    {
        $bill = LimoDriverBill::query()->where('status', LimoDriverBill::STATUS_NEW)->find($id);
        if ($bill === null) {
            $this->addError('scan', __('That bill was already checked.'));

            return;
        }

        $this->billId = (int) $bill->id;
        $this->driver = (string) $bill->driver_id;
        $this->advance = '';
        $this->defaultAdvance();

        $draft = is_array($bill->draft) ? $bill->draft : [];
        $plain = static fn (float $v): string => $v > 0.0 ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') : '';
        $this->check = array_values(array_filter(is_array($draft['check'] ?? null) ? $draft['check'] : [], 'is_string'));
        if ($draft === []) {
            $this->check[] = __('This bill could not be read. Type the figures from the photo.');
        }
        $this->date = (string) ($draft['date'] ?? now()->toDateString());
        $this->category = (string) ($draft['category'] ?? 'other');
        $this->currency = $this->knownCurrency((string) ($draft['currency'] ?? 'BHD'));
        $this->exchange_rate = $this->rateFor($this->currency);
        $this->payee = (string) ($draft['payee'] ?? '');
        $this->net_amount = $plain((float) ($draft['net_amount'] ?? 0));
        $this->vat_rate = (string) ($draft['vat_rate'] ?? '0');
        $this->notes = (string) ($draft['notes'] ?? '');
        $this->resetValidation();
    }

    /** Throw the scan away and start again; its photo goes with it. */
    public function discard(): void
    {
        // A driver's bill is set aside, never deleted: it is his paper.
        if ($this->billId > 0) {
            LimoDriverBill::query()->whereKey($this->billId)->where('status', LimoDriverBill::STATUS_NEW)->update([
                'status' => LimoDriverBill::STATUS_DISCARDED,
                'handled_by_user_id' => Auth::id(),
                'handled_at' => now(),
            ]);
            $this->redirect(url('/app/limousine/expense/driver-bills'), navigate: true);

            return;
        }

        $scan = $this->token !== '' ? $this->scan($this->token) : null;
        if ($scan !== null) {
            Storage::disk(BillScanner::DISK)->delete($scan['path']);
            Cache::forget(ExpenseBillController::cacheKey($this->token));
        }

        $this->clear();
    }

    /** Back to the first screen, ready for a photo. Touches nothing on disk. */
    private function clear(): void
    {
        $this->reset(['token', 'check', 'date', 'payee', 'net_amount', 'notes', 'currency', 'exchange_rate']);
        $this->category = 'other';
        $this->vat_rate = '0';
        $this->resetValidation();
    }

    /** A new choice starts a new run: the count belongs to one driver. */
    public function updatedDriver(): void
    {
        if ($this->driverId() === null && $this->driver !== self::NO_DRIVER) {
            $this->driver = '';
        }

        $this->savedCount = 0;
        $this->savedTotal = 0.0;
        $this->savedReference = '';
        $this->resetValidation('driver');
        $this->advance = '';
        $this->defaultAdvance();
    }

    /**
     * The driver's petty-cash advances still open (given, not yet settled),
     * oldest first: the cash his receipts are spent from.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LimoPettyAdvance>
     */
    private function openAdvances(): \Illuminate\Database\Eloquent\Collection
    {
        $driverId = $this->driverId();
        if ($driverId === null || ! \Illuminate\Support\Facades\Schema::hasColumn('limo_petty_lines', 'expense_id')) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return LimoPettyAdvance::query()
            ->where('driver_id', $driverId)
            ->whereIn('status', [LimoPettyAdvance::STATUS_ISSUED, LimoPettyAdvance::STATUS_CONFIRMED])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /** One open advance: it is the obvious answer, so it is chosen (and can be changed). */
    private function defaultAdvance(): void
    {
        if ($this->advance !== '') {
            return;
        }

        $open = $this->openAdvances();
        if ($open->count() === 1) {
            $this->advance = (string) $open->first()?->id;
        }
    }

    /** Finished with this driver: back to choosing whose bills come next. */
    public function changeDriver(): void
    {
        $this->driver = '';
        $this->updatedDriver();
    }

    /** The chosen driver's id, only if that driver exists. */
    private function driverId(): ?int
    {
        if ($this->driver === '' || ! ctype_digit($this->driver)) {
            return null;
        }

        return LimoDriver::query()->whereKey((int) $this->driver)->exists() ? (int) $this->driver : null;
    }

    /** Choosing another currency looks up its rate; the rate box stays editable. */
    public function updatedCurrency(): void
    {
        $this->currency = $this->knownCurrency($this->currency);
        $this->exchange_rate = $this->rateFor($this->currency);
        $this->resetValidation('exchange_rate');
    }

    private function knownCurrency(string $code): string
    {
        $code = strtoupper(trim($code));

        return array_key_exists($code, LimoExpense::BILL_CURRENCIES) ? $code : 'BHD';
    }

    /**
     * BHD value of one unit: today's rate when it can be fetched, otherwise
     * the standard figure, so a lookup that fails never blocks an expense.
     */
    private function rateFor(string $currency): string
    {
        if ($currency === 'BHD') {
            return '1';
        }

        $rate = app(ExchangeRateService::class)->rate($currency, 'BHD');
        if ($rate === null || $rate <= 0.0) {
            $rate = LimoExpense::BILL_CURRENCIES[$currency];
        }

        return rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
    }

    /** The rate in force for the maths: always 1 for BHD, whatever the box says. */
    private function rate(): float
    {
        if ($this->knownCurrency($this->currency) === 'BHD') {
            return 1.0;
        }

        return is_numeric($this->exchange_rate) ? max(0.0, (float) $this->exchange_rate) : 0.0;
    }

    public function save(): void
    {
        $expense = $this->store();
        if ($expense === null) {
            return;
        }

        session()->flash('toast', __('Expense :ref saved from the scanned bill.', ['ref' => (string) $expense->reference]));
        if ($this->billId > 0) {
            $this->redirect(url('/app/limousine/expense/driver-bills'), navigate: true);

            return;
        }
        $this->redirect(url('/app/limousine/expense/' . $expense->id), navigate: true);
    }

    /**
     * Save this bill and go straight back to the camera for the next one, for
     * working through a pile of bills on a phone without leaving the page.
     */
    public function saveAndNext(): void
    {
        $expense = $this->store();
        if ($expense === null) {
            return;
        }

        // A driver's bill: back to the list for the next one.
        if ($this->billId > 0) {
            session()->flash('toast', __('Expense :ref saved from the scanned bill.', ['ref' => (string) $expense->reference]));
            $this->redirect(url('/app/limousine/expense/driver-bills'), navigate: true);

            return;
        }

        $this->clear();
        $this->savedReference = (string) $expense->reference;
    }

    /** Check the boxes and write the expense. Null when something stopped it. */
    private function store(): ?LimoExpense
    {
        $this->guardAccess(Permission::Create);

        $bill = $this->billId > 0
            ? LimoDriverBill::query()->where('status', LimoDriverBill::STATUS_NEW)->find($this->billId)
            : null;
        $scan = $bill !== null
            ? ['user' => Auth::id(), 'path' => $bill->photo_path, 'draft' => []]
            : ($this->token !== '' ? $this->scan($this->token) : null);
        if ($scan === null) {
            $this->addError('scan', $this->billId > 0 ? __('That bill was already checked.') : __('That scan has expired. Take the photo again.'));

            return null;
        }

        // Whose bill it is has to be said, even if the answer is "nobody's".
        if ($this->driver !== self::NO_DRIVER && $this->driverId() === null) {
            $this->addError('driver', __('Choose the driver these bills belong to, or choose "Office bill, no driver".'));

            return null;
        }

        // A driver who still holds petty cash: say whether this bill came out of it.
        $open = $this->openAdvances();
        $advance = null;
        if ($open->isNotEmpty()) {
            if ($this->advance === self::NO_ADVANCE) {
                $advance = null;
            } else {
                $advance = $open->firstWhere('id', (int) $this->advance);
                if ($advance === null) {
                    $this->addError('advance', __('Say whether this bill was paid from the cash this driver was given.'));

                    return null;
                }
            }
        }

        $this->validate([
            'date' => ['required', 'date'],
            'category' => ['required', 'in:' . implode(',', array_column(LimoExpense::categoryOptions(), 'value'))],
            'payee' => ['nullable', 'string', 'max:190'],
            'currency' => ['required', 'in:' . implode(',', array_keys(LimoExpense::BILL_CURRENCIES))],
            'exchange_rate' => ['required', 'numeric', 'gt:0', 'lt:1000'],
            'net_amount' => ['required', 'numeric', 'gt:0'],
            'vat_rate' => ['required', 'in:' . implode(',', LimoExpense::VAT_RATES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'date' => __('Date'), 'category' => __('Category'), 'payee' => __('Paid to'),
            'net_amount' => __('Amount before VAT'), 'vat_rate' => __('VAT'), 'notes' => __('Notes'),
            'currency' => __('Currency'), 'exchange_rate' => __('Exchange rate'),
        ]);

        $expense = new LimoExpense([
            'date' => $this->date,
            'category' => $this->category,
            'payee' => trim($this->payee) !== '' ? trim($this->payee) : null,
            // Always BHD: the bill's amount at the rate shown on screen.
            'net_amount' => round((float) $this->net_amount * $this->rate(), 3),
            'vat_rate' => (int) $this->vat_rate,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            'driver_id' => $this->driverId(),
        ]);
        // From the server's own record of the upload, not from the page.
        $expense->setAttribute('bill_path', $scan['path']);
        if ($this->currency !== 'BHD') {
            // What the paper said, kept beside the BHD figures.
            $expense->setAttribute('bill_currency', $this->currency);
            $expense->setAttribute('bill_amount', round((float) $this->net_amount, 3));
            $expense->setAttribute('bill_rate', $this->rate());
        }
        $expense->save();

        if ($bill !== null) {
            $bill->forceFill([
                'status' => LimoDriverBill::STATUS_SAVED,
                'expense_id' => $expense->id,
                'handled_by_user_id' => Auth::id(),
                'handled_at' => now(),
            ])->save();
        } else {
            Cache::forget(ExpenseBillController::cacheKey($this->token));
        }

        if ($advance instanceof LimoPettyAdvance) {
            $this->countAgainst($advance, $expense);
        }

        $this->savedCount++;
        $this->savedTotal = round($this->savedTotal + (float) $expense->amount, 3);

        return $expense->refresh();
    }

    /**
     * Put the bill on the driver's petty-cash advance as one of his receipts,
     * pointing at the expense, so the cash he holds goes down now and settling
     * the advance does not write the expense a second time.
     */
    private function countAgainst(LimoPettyAdvance $advance, LimoExpense $expense): void
    {
        app(\Modules\Limousine\Services\PettyCash::class)->addExpenseAsReceipt($advance, $expense);
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
            'drivers' => LimoDriver::query()->where('active', true)->orWhere('id', (int) $this->driver)->orderBy('name')->get(['id', 'name']),
            'driverChosen' => $this->driver === self::NO_DRIVER || $this->driverId() !== null,
            'openAdvances' => $this->openAdvances()->map(static fn (LimoPettyAdvance $a): array => [
                'id' => (int) $a->id,
                'reference' => (string) $a->reference,
                'date' => $a->date?->format('j-M') ?? '',
                'amount' => (float) $a->amount,
                'left' => round((float) $a->amount - $a->linesTotal(), 3),
            ])->all(),
            'driverName' => $this->driver === self::NO_DRIVER
                ? __('Office bill, no driver')
                : (string) (LimoDriver::query()->whereKey((int) $this->driver)->value('name') ?? ''),
            'reviewing' => $this->token !== '' || $this->billId > 0,
            'billPhoto' => $this->billId > 0 ? url('/app/limousine/expense/driver-bill/' . $this->billId . '/photo') : null,
            // The number this bill will get, to write on the paper.
            'nextReference' => $this->token !== '' ? LimoExpense::nextReference() : '',
            'categories' => LimoExpense::categoryOptions(),
            'vatOptions' => LimoExpense::vatOptions(),
            'currencies' => array_keys(LimoExpense::BILL_CURRENCIES),
            'foreign' => $this->knownCurrency($this->currency) !== 'BHD',
            'shownCurrency' => $this->knownCurrency($this->currency),
            // In the bill's currency…
            'vatAmount' => round($net * $rate / 100, 3),
            'total' => round($net + $net * $rate / 100, 3),
            // …and what will be saved, in BHD.
            'bhdNet' => $bhd = round($net * $this->rate(), 3),
            'bhdVat' => round($bhd * $rate / 100, 3),
            'bhdTotal' => round($bhd + round($bhd * $rate / 100, 3), 3),
        ]);
    }
}
