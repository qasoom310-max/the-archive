<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Pricing\PricingPortalPing;
use App\Erp\Pricing\PricingVersion;
use App\Erp\Pricing\PricingWriter;
use App\Livewire\Concerns\ScrollsToFirstError;
use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingService;
use App\Models\Pricing\PricingSetting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Where the owner edits the fares the website publishes.
 *
 * One service at a time, options down the side and cars across the top, saved
 * as a single submit — one transaction, one version bump, one line in the
 * activity log. The website is told to come and fetch afterwards, and never
 * blocks the save if it doesn't answer.
 */
#[Layout('components.layouts.app')]
#[Title('Website fares')]
final class PricingManager extends Component
{
    use ScrollsToFirstError;

    /** Which service's grid is open. */
    #[Url(except: '')]
    public string $service = '';

    /** @var array<int, array<string, string>> option id → car id → the typed amount. */
    public array $rates = [];

    /** @var array<int, bool> option id → shown on the website. */
    public array $optionActive = [];

    /** @var array<string, string> car id → the per-hour charge past a booked block. */
    public array $extraHours = [];

    public bool $offerActive = false;

    /** @var list<string> which of the service's cars the offer applies to */
    public array $offerCarIds = [];

    public string $offerPercent = '0';

    public string $offerLabelEn = '';

    public string $offerLabelAr = '';

    public string $offerStarts = '';

    public string $offerEnds = '';

    public string $returnFactor = '';

    /** Widget settings — where "book on WhatsApp" points, and required notice. */
    public string $whatsapp = '';

    public string $leadHours = '12';

    #[Locked]
    public int $version = 0;

    #[Locked]
    public ?string $updatedAt = null;

    /** Result of the last manual "send to website" press. */
    public ?string $pingResult = null;

    public bool $pingOk = false;

    public function mount(): void
    {
        $this->guardAdmin();

        if (! Schema::hasTable('pricing_services')) {
            return;
        }

        $first = PricingService::query()->orderBy('sort')->orderBy('id')->first();
        if ($this->service === '' && $first !== null) {
            $this->service = $first->id;
        }

        $this->loadService();
    }

    private function guardAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    public function updatedService(): void
    {
        $this->resetValidation();
        $this->pingResult = null;
        $this->loadService();
    }

    /** Pull the open service's grid into the form. */
    private function loadService(): void
    {
        $this->version = PricingVersion::current();
        $this->updatedAt = PricingVersion::updatedAt()?->toDayDateTimeString();

        $service = $this->currentService();
        if ($service === null) {
            return;
        }

        $this->rates = [];
        $this->optionActive = [];

        foreach ($service->options as $option) {
            $this->optionActive[$option->id] = $option->active;

            foreach ($this->cars() as $car) {
                $rate = $option->rates->firstWhere('car_id', $car->id);
                $this->rates[$option->id][$car->id] = $rate === null ? '' : $this->trim($rate->amount);
            }
        }

        $this->extraHours = [];
        foreach ($this->cars() as $car) {
            $row = $service->extraHours->firstWhere('car_id', $car->id);
            $this->extraHours[$car->id] = $row === null ? '' : $this->trim($row->amount);
        }

        $offer = $service->offer;
        $this->offerActive = (bool) $offer?->active;
        $this->offerPercent = $offer === null ? '0' : $this->trim($offer->percent);
        $this->offerLabelEn = (string) $offer?->label_en;
        $this->offerLabelAr = (string) $offer?->label_ar;
        $this->offerStarts = $offer?->starts_at?->toDateString() ?? '';
        $this->offerEnds = $offer?->ends_at?->toDateString() ?? '';

        // Nothing selected yet (a brand-new offer, or one never touched under
        // this feature) shows every car ticked — the admin unchecks the ones
        // that should NOT get the discount, rather than starting from a blank
        // grid that reads as "the offer applies to nothing".
        $existingOfferCarIds = $offer?->cars->pluck('id')->all() ?? [];
        $this->offerCarIds = $existingOfferCarIds !== []
            ? $existingOfferCarIds
            : $this->cars()->pluck('id')->all();

        $this->returnFactor = $service->return_factor === null ? '' : $this->trim($service->return_factor);

        $settings = PricingSetting::current();
        $this->whatsapp = $settings->whatsapp;
        $this->leadHours = (string) $settings->lead_hours;
    }

    public function save(PricingWriter $writer, PricingPortalPing $ping): void
    {
        $this->guardAdmin();

        $service = $this->currentService();
        if ($service === null) {
            return;
        }

        $this->validateFocusing($this->rules(), $this->messages());

        // A car the website will show must have a fare. Publishing a blank as
        // zero is the one failure mode that costs real money, so it is refused
        // here rather than papered over in the payload.
        $cars = $this->cars();
        foreach ($service->options as $option) {
            if (! ($this->optionActive[$option->id] ?? false)) {
                continue;
            }

            foreach ($cars as $car) {
                $typed = trim((string) ($this->rates[$option->id][$car->id] ?? ''));
                if ($typed === '') {
                    $this->addError(
                        "rates.{$option->id}.{$car->id}",
                        __('":option" needs a price for :car, or switch the option off.', [
                            'option' => $option->label_en,
                            'car' => $car->name_en,
                        ]),
                    );
                }
            }
        }

        // An offer that's ON must apply to something — otherwise "Offer is on"
        // reads as live while discounting nothing, which is worse than making
        // the admin pick at least one car.
        $offerCarIds = array_values(array_intersect($this->offerCarIds, $cars->pluck('id')->all()));
        if ($this->offerActive && $offerCarIds === []) {
            $this->addError('offerCarIds', __('Select at least one car for the offer, or switch it off.'));
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->dispatch('scroll-to-error', field: (string) array_key_first($this->getErrorBag()->messages()));

            return;
        }

        $version = $writer->transaction($service->name_en, function () use ($writer, $service, $cars, $offerCarIds): array {
            $changes = [];

            foreach ($service->options as $option) {
                $changes[] = $writer->setOptionActive($option, (bool) ($this->optionActive[$option->id] ?? false));

                foreach ($cars as $car) {
                    $typed = trim((string) ($this->rates[$option->id][$car->id] ?? ''));
                    $changes[] = $writer->setRate($option, $car->id, $typed === '' ? null : (float) $typed);
                }
            }

            foreach ($cars as $car) {
                $typed = trim((string) ($this->extraHours[$car->id] ?? ''));
                $changes[] = $writer->setExtraHour($service, $car->id, $typed === '' ? null : (float) $typed);
            }

            $changes[] = $writer->updateOffer($service, [
                'active' => $this->offerActive,
                'percent' => (float) ($this->offerPercent === '' ? 0 : $this->offerPercent),
                'label_en' => $this->offerLabelEn !== '' ? $this->offerLabelEn : null,
                'label_ar' => $this->offerLabelAr !== '' ? $this->offerLabelAr : null,
                'starts_at' => $this->offerStarts !== '' ? $this->offerStarts : null,
                'ends_at' => $this->offerEnds !== '' ? $this->offerEnds : null,
            ], $offerCarIds);

            $serviceAttributes = ['return_factor' => $this->returnFactor === '' ? null : (float) $this->returnFactor];

            // A human has now been through this grid, so the fares are no
            // longer assumptions — the warning clears itself on first save.
            if ($service->estimated) {
                $serviceAttributes['estimated'] = false;
                $changes[] = 'estimated fares confirmed';
            }

            $changes[] = $writer->updateService($service, $serviceAttributes);
            $changes[] = $writer->updateSettings([
                'whatsapp' => $this->whatsapp,
                'lead_hours' => (int) $this->leadHours,
            ]);

            return array_values(array_filter($changes));
        });

        $this->loadService();

        // Best effort, always after the save has committed: the website being
        // down must never cost the owner a price change.
        $result = $ping->send($version);
        $this->pingOk = $result['sent'];
        $this->pingResult = $result['message'];

        session()->flash('pricing_toast', __('Fares saved — version :v.', ['v' => $version]));
    }

    /** The manual "tell the website now" button, for when the two look out of sync. */
    public function sendToWebsite(PricingPortalPing $ping): void
    {
        $this->guardAdmin();

        $result = $ping->send(PricingVersion::current(), force: true);
        $this->pingOk = $result['sent'];
        $this->pingResult = $result['message'];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            // Blank is allowed here and caught above with a clearer message —
            // "needs a price for Sedan" beats "the rates.4.sedan field is
            // required".
            'rates.*.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'extraHours.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'offerPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'offerLabelEn' => ['nullable', 'string', 'max:120'],
            'offerLabelAr' => ['nullable', 'string', 'max:120'],
            'offerStarts' => ['nullable', 'date'],
            'offerEnds' => ['nullable', 'date', 'after_or_equal:offerStarts'],
            'returnFactor' => ['nullable', 'numeric', 'min:1', 'max:9.99'],
            'whatsapp' => ['required', 'string', 'regex:/^[0-9]{8,15}$/'],
            'leadHours' => ['required', 'integer', 'min:0', 'max:168'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'rates.*.*.numeric' => __('A fare must be a number.'),
            'rates.*.*.decimal' => __('A fare can have at most 3 decimal places.'),
            'extraHours.*.numeric' => __('An hourly charge must be a number.'),
            'offerEnds.after_or_equal' => __('The offer cannot end before it starts.'),
            'whatsapp.regex' => __('The WhatsApp number must be digits only, including the country code.'),
        ];
    }

    private function currentService(): ?PricingService
    {
        if ($this->service === '' || ! Schema::hasTable('pricing_services')) {
            return null;
        }

        return PricingService::query()
            ->with(['options' => fn ($q) => $q->orderBy('sort')->orderBy('id'), 'options.rates', 'vehicles', 'extraHours', 'offer', 'offer.cars'])
            ->find($this->service);
    }

    /**
     * The vehicles the OPEN service offers — an airport grid must not show a
     * 50-seat coach. A service with no list yet falls back to the whole active
     * fleet rather than rendering an empty grid.
     *
     * @return \Illuminate\Support\Collection<int, PricingCar>
     */
    private function cars(): \Illuminate\Support\Collection
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

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    public function render(): View
    {
        $ready = Schema::hasTable('pricing_services');

        return view('livewire.pages.pricing-manager', [
            'ready' => $ready,
            'services' => $ready
                ? PricingService::query()->orderBy('sort')->orderBy('id')->get()
                : collect(),
            'current' => $this->currentService(),
            'cars' => $ready ? $this->cars() : collect(),
        ]);
    }
}
