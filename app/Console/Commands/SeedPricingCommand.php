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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds Wanaan's real published fares.
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

    protected $description = 'Seed the published fares (cars, services, options, rates).';

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
        if (! Schema::hasTable('pricing_services')) {
            $this->error('Pricing tables are missing here — run the migrations for this database first.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            foreach (self::CARS as $car) {
                PricingCar::query()->updateOrCreate(['id' => $car['id']], $car);
            }

            foreach (self::SERVICES as $sort => $service) {
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
                        'sort' => ($sort + 1) * 10,
                        'active' => true,
                    ],
                );

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

            PricingVersion::bump();
        });

        $this->info(sprintf(
            'Seeded %d cars and %d services. Version is now %d.',
            PricingCar::query()->count(),
            PricingService::query()->count(),
            PricingVersion::current(),
        ));

        $this->line('Placeholders needing confirmation: pax/bags per car, chauffeur extra-hour rates, KSA return factor.');

        return self::SUCCESS;
    }

    /**
     * `pax` and `bags` are sensible figures per model, NOT measured from the
     * fleet — flagged for confirmation.
     *
     * @var list<array<string, mixed>>
     */
    private const CARS = [
        ['id' => 'sedan', 'name_en' => 'Sedan', 'name_ar' => 'سيدان', 'model' => 'Ford Taurus 2024', 'pax' => 3, 'bags' => 2, 'sort' => 10, 'active' => true],
        ['id' => 'suv', 'name_en' => 'SUV', 'name_ar' => 'دفع رباعي', 'model' => 'Ford Expedition', 'pax' => 6, 'bags' => 4, 'sort' => 20, 'active' => true],
        ['id' => 'lsuv', 'name_en' => 'Luxury SUV', 'name_ar' => 'دفع رباعي فاخر', 'model' => 'Mercedes Vito', 'pax' => 7, 'bags' => 5, 'sort' => 30, 'active' => true],
        ['id' => 'luxury', 'name_en' => 'Luxury', 'name_ar' => 'فاخرة', 'model' => 'BMW 7 Series 2024', 'pax' => 3, 'bags' => 3, 'sort' => 40, 'active' => true],
    ];

    /**
     * The real fares, per car, driver included, in BHD.
     *
     * @var list<array<string, mixed>>
     */
    private const SERVICES = [
        [
            'id' => 'airport',
            'name_en' => 'Airport Transfer',
            'name_ar' => 'توصيل المطار',
            'ask_en' => 'Where in Bahrain?',
            'ask_ar' => 'إلى أي منطقة في البحرين؟',
            'note_en' => 'Fixed fare, driver included. We track your flight — no extra charge if you land late.',
            'note_ar' => 'سعر ثابت شامل السائق. نتابع رحلتك — لا رسوم إضافية عند تأخر الهبوط.',
            'return_factor' => null,
            'options' => [
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
                    'prices' => ['sedan' => 22, 'suv' => 33, 'lsuv' => 40, 'luxury' => 80],
                ],
            ],
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
            'options' => [
                // The Luxury jump from 180 (4h) to 400 (8h) is deliberate and
                // confirmed by the owner — do not "correct" it.
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
            'note_en' => 'Per car, driver included. Causeway fees are included in the price — nothing to pay at the border.',
            'note_ar' => 'لكل سيارة شامل السائق. رسوم الجسر مشمولة في السعر — لا يوجد ما يُدفع على الحدود.',
            // PLACEHOLDER: returns are discounted, rate unconfirmed.
            'return_factor' => 1.80,
            'options' => [
                ['code' => 'khobar', 'label_en' => 'Khobar / Dhahran', 'label_ar' => 'الخبر / الظهران', 'prices' => ['sedan' => 40, 'suv' => 45, 'lsuv' => 55, 'luxury' => 130]],
                ['code' => 'dammam', 'label_en' => 'Dammam', 'label_ar' => 'الدمام', 'prices' => ['sedan' => 50, 'suv' => 60, 'lsuv' => 75, 'luxury' => 150]],
                ['code' => 'qatif', 'label_en' => 'Qatif', 'label_ar' => 'القطيف', 'prices' => ['sedan' => 55, 'suv' => 65, 'lsuv' => 80, 'luxury' => 160]],
                ['code' => 'jubail', 'label_en' => 'Jubail / Al-Ahsa', 'label_ar' => 'الجبيل / الأحساء', 'prices' => ['sedan' => 60, 'suv' => 70, 'lsuv' => 85, 'luxury' => 190]],
                ['code' => 'riyadh', 'label_en' => 'Riyadh / Qatar / Kuwait', 'label_ar' => 'الرياض / قطر / الكويت', 'prices' => ['sedan' => 140, 'suv' => 160, 'lsuv' => 220, 'luxury' => 450]],
            ],
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
            'options' => [
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
            ],
        ],
    ];
}
