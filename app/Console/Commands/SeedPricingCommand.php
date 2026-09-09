<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Pricing\PricingVersion;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingExtraHour;
use App\Models\Pricing\PricingOffer;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingRate;
use App\Models\Pricing\PricingService;
use App\Models\Pricing\PricingSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds Wanaan's published fares.
 *
 * Idempotent: re-running updates the rows in place rather than duplicating, so
 * it is safe to run after a deploy. It is NOT in the deploy chain — deploy
 * seeders run against Main, and these fares belong to one workspace.
 *
 *   php artisan pricing:seed --workspace=7
 */
final class SeedPricingCommand extends Command
{
    protected $signature = 'pricing:seed {--workspace= : Workspace id to seed into (defaults to the current database)}';

    protected $description = 'Seed the published fares (vehicles, services, options, rates, settings).';

    public function handle(WorkspaceManager $workspaces): int
    {
        $option = $this->option('workspace');
        $workspaceId = is_string($option) && $option !== '' ? (int) $option : null;

        // A mistyped id must FAIL, never silently seed Main with one business's
        // fares — the same rule the customer import follows.
        if ($workspaceId !== null && $workspaces->findAny($workspaceId) === null) {
            $this->error("Workspace {$workspaceId} does not exist.");

            return self::FAILURE;
        }

        /** @var int $result */
        $result = $workspaces->runFor($workspaceId, fn (): int => $this->seed());

        return $result;
    }

    private function seed(): int
    {
        if (! Schema::hasTable('pricing_services') || ! Schema::hasTable('pricing_service_vehicles')) {
            $this->error('Pricing tables are missing here — run the migrations for this database first.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            foreach (self::VEHICLES as $vehicle) {
                PricingCar::query()->updateOrCreate(['id' => $vehicle['id']], $vehicle);
            }

            foreach (self::services() as $sort => $service) {
                $model = PricingService::query()->updateOrCreate(
                    ['id' => $service['id']],
                    [
                        'name_en' => $service['name_en'],
                        'name_ar' => $service['name_ar'],
                        'ask_en' => $service['ask_en'],
                        'ask_ar' => $service['ask_ar'],
                        'note_en' => $service['note_en'],
                        'note_ar' => $service['note_ar'],
                        'return_factor' => $service['return_factor'],
                        'estimated' => $service['estimated'] ?? false,
                        'sort' => ($sort + 1) * 10,
                        'active' => true,
                    ],
                );

                // Which vehicles this service offers, in the given order.
                $vehicles = [];
                foreach ($service['vehicles'] as $index => $carId) {
                    $vehicles[$carId] = ['sort' => ($index + 1) * 10];
                }
                $model->vehicles()->sync($vehicles);

                foreach ($service['options'] as $index => $option) {
                    $optionModel = PricingOption::query()->updateOrCreate(
                        ['service_id' => $model->id, 'code' => $option['code']],
                        [
                            'label_en' => $option['label_en'],
                            'label_ar' => $option['label_ar'],
                            'short_en' => $option['short_en'] ?? null,
                            'short_ar' => $option['short_ar'] ?? null,
                            'hours' => $option['hours'] ?? null,
                            'sort' => ($index + 1) * 10,
                            'active' => true,
                        ],
                    );

                    foreach ($option['prices'] as $carId => $amount) {
                        PricingRate::query()->updateOrCreate(
                            ['option_id' => $optionModel->id, 'car_id' => $carId],
                            ['amount' => $amount],
                        );
                    }
                }

                foreach ($service['extra_hours'] ?? [] as $carId => $amount) {
                    PricingExtraHour::query()->updateOrCreate(
                        ['service_id' => $model->id, 'car_id' => $carId],
                        ['amount' => $amount],
                    );
                }

                // Every offer starts switched off; the owner turns one on.
                PricingOffer::query()->firstOrCreate(
                    ['service_id' => $model->id],
                    ['active' => false, 'percent' => 0],
                );
            }

            $settings = PricingSetting::current();
            $settings->fill(self::SETTINGS)->save();

            PricingVersion::bump();
        });

        $this->info(sprintf(
            'Seeded %d vehicles and %d services. Version is now %d.',
            PricingCar::query()->count(),
            PricingService::query()->count(),
            PricingVersion::current(),
        ));

        $estimated = PricingService::query()->where('estimated', true)->pluck('id')->implode(', ');
        if ($estimated !== '') {
            $this->warn("Estimated fares (not the owner's prices) on: {$estimated}");
        }

        $this->line('Also awaiting confirmation: car pax/bags, chauffeur extra-hour rates, KSA return factor.');

        return self::SUCCESS;
    }

    /** @var array<string, mixed> */
    private const SETTINGS = ['whatsapp' => '97317474949', 'lead_hours' => 12];

    /**
     * Cars and buses. The four CAR `pax`/`bags` figures are sensible values per
     * model, NOT measured from the fleet — flagged for confirmation. The bus
     * seat counts came from the owner and are real; their `bags` is null
     * because luggage on a coach depends on the group, and an invented number
     * is worse than none.
     *
     * @var list<array<string, mixed>>
     */
    private const VEHICLES = [
        ['id' => 'sedan', 'name_en' => 'Sedan', 'name_ar' => 'سيدان', 'model' => 'Ford Taurus 2024', 'pax' => 3, 'bags' => 2, 'sort' => 10, 'active' => true],
        ['id' => 'suv', 'name_en' => 'SUV', 'name_ar' => 'دفع رباعي', 'model' => 'Ford Expedition', 'pax' => 6, 'bags' => 4, 'sort' => 20, 'active' => true],
        ['id' => 'lsuv', 'name_en' => 'Luxury SUV', 'name_ar' => 'دفع رباعي فاخر', 'model' => 'Mercedes Vito', 'pax' => 7, 'bags' => 5, 'sort' => 30, 'active' => true],
        ['id' => 'luxury', 'name_en' => 'Luxury', 'name_ar' => 'فاخرة', 'model' => 'BMW 7 Series 2024', 'pax' => 3, 'bags' => 3, 'sort' => 40, 'active' => true],
        ['id' => 'hiace', 'name_en' => 'Toyota Hiace', 'name_ar' => 'تويوتا هايس', 'model' => 'Toyota Hiace 2023', 'pax' => 15, 'bags' => null, 'sort' => 50, 'active' => true],
        ['id' => 'coaster', 'name_en' => 'Toyota Coaster', 'name_ar' => 'تويوتا كوستر', 'model' => 'Toyota Coaster 2023', 'pax' => 30, 'bags' => null, 'sort' => 60, 'active' => true],
        ['id' => 'coach', 'name_en' => 'Mercedes Coach', 'name_ar' => 'مرسيدس كوتش', 'model' => 'Mercedes Coach', 'pax' => 50, 'bags' => null, 'sort' => 70, 'active' => true],
        ['id' => 'sprinter', 'name_en' => 'Sprinter VIP', 'name_ar' => 'مرسيدس سبرينتر VIP', 'model' => 'Mercedes Sprinter VIP', 'pax' => 12, 'bags' => null, 'sort' => 80, 'active' => true],
    ];

    /** The four car classes, in display order — shared by the car services. */
    private const CAR_FLEET = ['sedan', 'suv', 'lsuv', 'luxury'];

    /** The four buses, in display order. */
    private const BUS_FLEET = ['hiace', 'coaster', 'coach', 'sprinter'];

    /**
     * The zones, shared by `airport` and `city` — the owner priced them the
     * same deliberately, so they are written once.
     *
     * @var list<array<string, mixed>>
     */
    private const ZONES = [
        [
            'code' => 'zone_main',
            'label_en' => 'Manama · Muharraq · Juffair · Seef · Sitra · Isa Town · Budaiya · Hamad Town',
            'label_ar' => 'المنامة · المحرق · الجفير · السيف · سترة · مدينة عيسى · البديع · مدينة حمد',
            'short_en' => 'Manama, Seef, Juffair and most areas',
            'short_ar' => 'المنامة، السيف، الجفير ومعظم المناطق',
            'prices' => ['sedan' => 15, 'suv' => 20, 'lsuv' => 30, 'luxury' => 50],
        ],
        [
            'code' => 'zone_south',
            'label_en' => 'Durrat · Askar · Jaww · Alzallaq',
            'label_ar' => 'درة البحرين · عسكر · جو · الزلاق',
            'short_en' => 'Durrat, Askar, Jaww, Alzallaq',
            'short_ar' => 'درة البحرين، عسكر، جو، الزلاق',
            'prices' => ['sedan' => 22, 'suv' => 33, 'lsuv' => 40, 'luxury' => 80],
        ],
    ];

    /**
     * Saudi destination labels, shared by the car and bus KSA services.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const KSA_DESTINATIONS = [
        'khobar' => ['Khobar / Dhahran', 'الخبر / الظهران'],
        'dammam' => ['Dammam', 'الدمام'],
        'qatif' => ['Qatif', 'القطيف'],
        'jubail' => ['Jubail / Al-Ahsa', 'الجبيل / الأحساء'],
        'riyadh' => ['Riyadh / Qatar / Kuwait', 'الرياض / قطر / الكويت'],
    ];

    /**
     * The real fares, per vehicle, driver included, in BHD.
     *
     * @return list<array<string, mixed>>
     */
    private static function services(): array
    {
        return [
            [
                'id' => 'airport',
                'name_en' => 'Airport Transfer',
                'name_ar' => 'توصيل المطار',
                'ask_en' => 'Where in Bahrain?',
                'ask_ar' => 'إلى أي منطقة في البحرين؟',
                'note_en' => 'Fixed fare, driver included. We track your flight — no extra charge if you land late.',
                'note_ar' => 'سعر ثابت شامل السائق. نتابع رحلتك — لا رسوم إضافية عند تأخر الهبوط.',
                'return_factor' => null,
                'vehicles' => self::CAR_FLEET,
                'options' => self::ZONES,
            ],
            [
                'id' => 'city',
                'name_en' => 'City to City',
                'name_ar' => 'من مدينة إلى مدينة',
                'ask_en' => 'Where are you going?',
                'ask_ar' => 'إلى أين أنت ذاهب؟',
                'note_en' => 'One way, fixed fare, driver included. Need the car to wait for you? That is our chauffeur service.',
                'note_ar' => 'اتجاه واحد بسعر ثابت شامل السائق. تحتاج السيارة أن تنتظرك؟ تلك هي خدمة الليموزين بالساعة.',
                'return_factor' => null,
                'vehicles' => self::CAR_FLEET,
                'options' => self::ZONES,
            ],
            [
                'id' => 'chauffeur',
                'name_en' => 'Chauffeur in Bahrain',
                'name_ar' => 'ليموزين داخل البحرين',
                'ask_en' => 'How many hours?',
                'ask_ar' => 'كم عدد الساعات؟',
                'note_en' => 'The car and driver stay with you for the whole block. Fuel included.',
                'note_ar' => 'السيارة والسائق معك طوال المدة. الوقود مشمول.',
                'return_factor' => null,
                'vehicles' => self::CAR_FLEET,
                'options' => [
                    // The Luxury jump from 180 (4h) to 400 (8h) is deliberate
                    // and confirmed by the owner — do not "correct" it.
                    ['code' => 'h4', 'hours' => 4, 'label_en' => '4 hours', 'label_ar' => '٤ ساعات', 'prices' => ['sedan' => 45, 'suv' => 65, 'lsuv' => 75, 'luxury' => 180]],
                    ['code' => 'h8', 'hours' => 8, 'label_en' => '8 hours', 'label_ar' => '٨ ساعات', 'prices' => ['sedan' => 90, 'suv' => 120, 'lsuv' => 140, 'luxury' => 400]],
                    ['code' => 'h12', 'hours' => 12, 'label_en' => '12 hours', 'label_ar' => '١٢ ساعة', 'prices' => ['sedan' => 120, 'suv' => 170, 'lsuv' => 180, 'luxury' => 450]],
                ],
                // PLACEHOLDERS, derived from the 4-hour rates — awaiting confirmation.
                'extra_hours' => ['sedan' => 12, 'suv' => 17, 'lsuv' => 19, 'luxury' => 45],
            ],
            [
                'id' => 'ksa',
                'name_en' => 'Bahrain → Saudi Arabia',
                'name_ar' => 'البحرين → السعودية',
                'ask_en' => 'Which destination?',
                'ask_ar' => 'ما هي الوجهة؟',
                // The website's own copy said causeway fees were NOT included;
                // the owner confirmed they are. This is the corrected wording.
                'note_en' => 'Per car, driver included. Causeway fees are included in the price — nothing to pay at the border.',
                'note_ar' => 'لكل سيارة شامل السائق. رسوم الجسر مشمولة في السعر — لا يوجد ما يُدفع على الحدود.',
                // PLACEHOLDER: returns are discounted, rate unconfirmed.
                'return_factor' => 1.80,
                'vehicles' => self::CAR_FLEET,
                'options' => self::ksaOptions([
                    'khobar' => ['sedan' => 40, 'suv' => 45, 'lsuv' => 55, 'luxury' => 130],
                    'dammam' => ['sedan' => 50, 'suv' => 60, 'lsuv' => 75, 'luxury' => 150],
                    'qatif' => ['sedan' => 55, 'suv' => 65, 'lsuv' => 80, 'luxury' => 160],
                    'jubail' => ['sedan' => 60, 'suv' => 70, 'lsuv' => 85, 'luxury' => 190],
                    'riyadh' => ['sedan' => 140, 'suv' => 160, 'lsuv' => 220, 'luxury' => 450],
                ]),
            ],
            [
                'id' => 'bus',
                'name_en' => 'Luxury Bus with Driver',
                'name_ar' => 'حافلة فاخرة مع سائق',
                'ask_en' => 'How many hours?',
                'ask_ar' => 'كم عدد الساعات؟',
                'note_en' => 'Driver and fuel included. The bus stays with your group for the whole block.',
                'note_ar' => 'شامل السائق والوقود. الحافلة تبقى مع مجموعتك طوال المدة.',
                'return_factor' => null,
                'vehicles' => self::BUS_FLEET,
                // Blocks are 6 / 8 / 12 here, NOT 4 / 8 / 12 like the cars.
                // Read off the live product pages — do not normalise them.
                'options' => [
                    ['code' => 'h6', 'hours' => 6, 'label_en' => '6 hours', 'label_ar' => '٦ ساعات', 'prices' => ['hiace' => 70, 'coaster' => 80, 'coach' => 160, 'sprinter' => 180]],
                    ['code' => 'h8', 'hours' => 8, 'label_en' => '8 hours', 'label_ar' => '٨ ساعات', 'prices' => ['hiace' => 90, 'coaster' => 100, 'coach' => 200, 'sprinter' => 240]],
                    ['code' => 'h12', 'hours' => 12, 'label_en' => '12 hours', 'label_ar' => '١٢ ساعة', 'prices' => ['hiace' => 120, 'coaster' => 140, 'coach' => 280, 'sprinter' => 320]],
                ],
            ],
            [
                'id' => 'ksa_bus',
                'name_en' => 'Bus to Saudi Arabia',
                'name_ar' => 'حافلة إلى السعودية',
                'ask_en' => 'Which destination?',
                'ask_ar' => 'ما هي الوجهة؟',
                'note_en' => 'Per bus, driver included.',
                'note_ar' => 'لكل حافلة شامل السائق.',
                'return_factor' => 1.80,
                // EVERY fare here is an ESTIMATE, not the owner's price — no
                // bus-to-Saudi figure exists on the website. Derived from each
                // bus's own 12-hour rate scaled by the destination multipliers
                // his CAR fares already imply against Khobar. The flag drives a
                // warning on the admin screen and clears when a human overwrites.
                'estimated' => true,
                'vehicles' => self::BUS_FLEET,
                'options' => self::ksaOptions([
                    'khobar' => ['hiace' => 120, 'coaster' => 140, 'coach' => 280, 'sprinter' => 320],
                    'dammam' => ['hiace' => 150, 'coaster' => 175, 'coach' => 350, 'sprinter' => 400],
                    'qatif' => ['hiace' => 165, 'coaster' => 190, 'coach' => 380, 'sprinter' => 440],
                    'jubail' => ['hiace' => 180, 'coaster' => 210, 'coach' => 420, 'sprinter' => 480],
                    'riyadh' => ['hiace' => 420, 'coaster' => 490, 'coach' => 980, 'sprinter' => 1120],
                ]),
            ],
        ];
    }

    /**
     * Build the Saudi options from a code → prices map, so the two KSA
     * services share one set of destination labels.
     *
     * @param  array<string, array<string, float|int>>  $prices
     * @return list<array<string, mixed>>
     */
    private static function ksaOptions(array $prices): array
    {
        $out = [];

        foreach ($prices as $code => $row) {
            [$labelEn, $labelAr] = self::KSA_DESTINATIONS[$code];
            $out[] = ['code' => $code, 'label_en' => $labelEn, 'label_ar' => $labelAr, 'prices' => $row];
        }

        return $out;
    }
}
