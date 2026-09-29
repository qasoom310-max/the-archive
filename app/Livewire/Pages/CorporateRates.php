<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Activity\ActivityLogger;
use App\Livewire\Concerns\ScrollsToFirstError;
use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingCorporateRate;
use App\Models\Pricing\PricingService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Models\LimoCustomer;

/**
 * Where the owner keeps the prices agreed with companies that have a deal
 * with us.
 *
 * The same grid as the website fares — one service at a time, options down,
 * cars across — for either the STANDARD corporate rate (every company) or one
 * company's own deal, which overrides the standard one. A blank cell falls
 * back: company → standard corporate → website fare.
 *
 * Nothing here is published to the website. The staff assistant reads it to
 * quote and book company trips at the agreed price.
 */
#[Layout('components.layouts.app')]
#[Title('Corporate rates')]
final class CorporateRates extends Component
{
    use ScrollsToFirstError;

    #[Url(except: '')]
    public string $service = '';

    /** A company's id, or '' for the standard corporate rate every company gets. */
    #[Url(except: '')]
    public string $company = '';

    /** @var array<int, array<string, string>> option id → car id → the typed amount. */
    public array $rates = [];

    public function mount(): void
    {
        $this->guardAdmin();

        if (! $this->ready()) {
            return;
        }

        $first = PricingService::query()->orderBy('sort')->orderBy('id')->first();
        if ($this->service === '' && $first !== null) {
            $this->service = $first->id;
        }

        if ($this->companyId() === null) {
            $this->company = '';
        }

        $this->load();
    }

    public function updatedService(): void
    {
        $this->resetValidation();
        $this->load();
    }

    public function updatedCompany(): void
    {
        if ($this->companyId() === null) {
            $this->company = '';
        }
        $this->resetValidation();
        $this->load();
    }

    public function save(ActivityLogger $activity): void
    {
        $this->guardAdmin();

        $service = $this->currentService();
        if ($service === null) {
            return;
        }

        $this->validateFocusing(
            ['rates.*.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,3']],
            [
                'rates.*.*.numeric' => __('A fare must be a number.'),
                'rates.*.*.decimal' => __('A fare can have at most 3 decimal places.'),
            ],
        );

        $companyId = $this->companyId();
        $cars = $this->cars();
        $changed = 0;

        DB::transaction(function () use ($service, $cars, $companyId, &$changed): void {
            foreach ($service->options as $option) {
                foreach ($cars as $car) {
                    $typed = trim((string) ($this->rates[$option->id][$car->id] ?? ''));
                    $existing = PricingCorporateRate::query()
                        ->where('option_id', $option->id)
                        ->where('car_id', $car->id)
                        ->where('customer_id', $companyId)
                        ->first();

                    if ($typed === '' || (float) $typed <= 0) {
                        if ($existing !== null) {
                            $existing->delete();
                            $changed++;
                        }

                        continue;
                    }

                    $amount = round((float) $typed, 3);
                    if ($existing === null) {
                        PricingCorporateRate::query()->create([
                            'customer_id' => $companyId,
                            'option_id' => $option->id,
                            'car_id' => $car->id,
                            'amount' => $amount,
                        ]);
                        $changed++;
                    } elseif (abs($existing->amount - $amount) > 0.0005) {
                        $existing->update(['amount' => $amount]);
                        $changed++;
                    }
                }
            }
        });

        $who = $this->companyName() ?? __('Standard corporate rate');
        if ($changed > 0) {
            $activity->log('updated', 'Corporate rates', $who . ' — ' . $service->name_en . ' (' . $changed . ')');
        }

        $this->load();
        session()->flash('corporate_toast', __('Corporate rates saved for :who.', ['who' => $who]));
    }

    private function guardAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    private function ready(): bool
    {
        return Schema::hasTable('pricing_services') && Schema::hasTable('pricing_corporate_rates');
    }

    private function companiesReady(): bool
    {
        // Limousine customers share the rental customers table, so ask the
        // model rather than guessing its name.
        return class_exists(LimoCustomer::class) && Schema::hasTable((new LimoCustomer())->getTable());
    }

    /** The chosen company, or null for the standard corporate rate. */
    private function companyId(): ?int
    {
        if ($this->company === '' || ! ctype_digit($this->company) || ! $this->companiesReady()) {
            return null;
        }

        $id = (int) $this->company;

        return LimoCustomer::query()->whereKey($id)->exists() ? $id : null;
    }

    private function companyName(): ?string
    {
        $id = $this->companyId();

        return $id === null ? null : (string) LimoCustomer::query()->whereKey($id)->value('name');
    }

    /** Pull the open service's corporate grid for the chosen company into the form. */
    private function load(): void
    {
        $service = $this->currentService();
        $this->rates = [];
        if ($service === null) {
            return;
        }

        $rows = PricingCorporateRate::query()
            ->whereIn('option_id', $service->options->pluck('id'))
            ->where('customer_id', $this->companyId())
            ->get();

        foreach ($service->options as $option) {
            foreach ($this->cars() as $car) {
                $row = $rows->first(fn (PricingCorporateRate $r): bool => $r->option_id === $option->id && $r->car_id === $car->id);
                $this->rates[$option->id][$car->id] = $row === null ? '' : $this->trim($row->amount);
            }
        }
    }

    private function currentService(): ?PricingService
    {
        if ($this->service === '' || ! $this->ready()) {
            return null;
        }

        return PricingService::query()
            ->with(['options' => fn ($q) => $q->orderBy('sort')->orderBy('id'), 'options.rates', 'vehicles'])
            ->find($this->service);
    }

    /**
     * The vehicles the open service offers (the whole active fleet when it
     * has no list yet) — same rule as the website fares grid.
     *
     * @return Collection<int, PricingCar>
     */
    private function cars(): Collection
    {
        $service = $this->currentService();
        if ($service !== null) {
            $own = $service->vehicles->where('active', true)->values();
            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return PricingCar::query()->where('active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * What a blank cell falls back to, per option and car: the standard
     * corporate rate when a company is open, the website fare otherwise.
     *
     * @return array<int, array<string, string>>
     */
    private function fallbacks(): array
    {
        $service = $this->currentService();
        if ($service === null) {
            return [];
        }

        $standard = $this->companyId() !== null
            ? PricingCorporateRate::query()->whereIn('option_id', $service->options->pluck('id'))->whereNull('customer_id')->get()
            : collect();

        $out = [];
        foreach ($service->options as $option) {
            foreach ($this->cars() as $car) {
                $std = $standard->first(fn (PricingCorporateRate $r): bool => $r->option_id === $option->id && $r->car_id === $car->id);
                $web = $option->rates->firstWhere('car_id', $car->id);
                $out[$option->id][$car->id] = $std !== null
                    ? $this->trim($std->amount)
                    : ($web !== null && $option->active ? $this->trim((float) $web->amount) : '');
            }
        }

        return $out;
    }

    /**
     * Companies to choose from: every company customer, plus any customer that
     * already has rates of its own (in case it was changed to an individual).
     *
     * @return list<array{value: string, label: string}>
     */
    private function companyOptions(): array
    {
        if (! $this->companiesReady()) {
            return [];
        }

        $withRates = PricingCorporateRate::query()->whereNotNull('customer_id')->distinct()->pluck('customer_id')->all();

        return LimoCustomer::query()
            ->where(fn ($q) => $q->where('type', LimoCustomer::TYPE_COMPANY)->orWhereIn('id', $withRates))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LimoCustomer $c): array => ['value' => (string) $c->id, 'label' => (string) $c->name])
            ->values()
            ->all();
    }

    /**
     * Companies that already have a deal of their own, for quick switching.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    private function companiesWithDeals(): array
    {
        if (! $this->companiesReady()) {
            return [];
        }

        $counts = PricingCorporateRate::query()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as aggregate')
            ->groupBy('customer_id')
            ->pluck('aggregate', 'customer_id');

        if ($counts->isEmpty()) {
            return [];
        }

        return LimoCustomer::query()
            ->whereIn('id', $counts->keys()->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (LimoCustomer $c): array => ['id' => (int) $c->id, 'name' => (string) $c->name, 'count' => (int) $counts->get($c->id, 0)])
            ->values()
            ->all();
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    public function render(): View
    {
        $ready = $this->ready();

        return view('livewire.pages.corporate-rates', [
            'ready' => $ready,
            'services' => $ready ? PricingService::query()->orderBy('sort')->orderBy('id')->get() : collect(),
            'current' => $this->currentService(),
            'cars' => $ready ? $this->cars() : collect(),
            'fallbacks' => $ready ? $this->fallbacks() : [],
            'companyOptions' => $this->companyOptions(),
            'companiesWithDeals' => $ready ? $this->companiesWithDeals() : [],
            'companyName' => $this->companyName(),
        ]);
    }
}
