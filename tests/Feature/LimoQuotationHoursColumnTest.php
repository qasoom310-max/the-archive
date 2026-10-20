<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;
use Tests\TestCase;

/**
 * A chauffeur quote says how many hours a day the car is with the customer,
 * and the printed columns multiply out to the amount whatever the rate basis.
 */
final class LimoQuotationHoursColumnTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** @param list<array<string, mixed>> $legs */
    private function quote(array $legs): LimoQuotation
    {
        $quote = LimoQuotation::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Bahraini German International Services'])->id,
            'quote_date' => now(),
            'status' => LimoQuotation::STATUS_DRAFT,
        ]);

        foreach ($legs as $i => $leg) {
            LimoLeg::query()->create($leg + [
                'legable_type' => LimoQuotation::class,
                'legable_id' => $quote->id,
                'sequence' => $i,
                'from_location' => 'Bahrain',
            ]);
        }

        return $quote->refresh();
    }

    public function test_a_chauffeur_quote_prints_hours_a_day_and_its_sums_add_up(): void
    {
        $quote = $this->quote([
            // 8 hours a day for 4 days at 700 a day, two coasters.
            ['service_type' => LimoLeg::TYPE_CHAUFFEUR, 'rate_basis' => LimoLeg::BASIS_DAY, 'hours' => 8, 'days' => 4, 'units' => 2, 'rate' => 700],
            // Per hour: 6 hours × 2 days × 25.
            ['service_type' => LimoLeg::TYPE_CHAUFFEUR, 'rate_basis' => LimoLeg::BASIS_HOUR, 'hours' => 6, 'days' => 2, 'units' => 1, 'rate' => 25],
            // A transfer has no hours.
            ['service_type' => LimoLeg::TYPE_TRANSFER, 'rate_basis' => LimoLeg::BASIS_TRIP, 'to_location' => 'Airport', 'units' => 1, 'rate' => 30],
        ]);

        $lines = app(QuotationPdf::class)->viewData($quote)['lines'];

        $this->assertSame(8.0, $lines[0]['hours']);
        $this->assertSame(2, $lines[0]['unit'], 'Unit is the number of cars.');
        $this->assertSame(5600.0, (float) $lines[0]['amount']);
        $this->assertSame((float) $lines[0]['amount'], $lines[0]['rate'] * $lines[0]['daysTrips'] * $lines[0]['unit']);

        $this->assertSame(6.0, $lines[1]['hours']);
        $this->assertSame((float) $lines[1]['amount'], $lines[1]['rate'] * $lines[1]['hours'] * $lines[1]['daysTrips'] * $lines[1]['unit']);

        $this->assertNull($lines[2]['hours']);

        $html = view('limousine::quotation-pdf', app(QuotationPdf::class)->viewData($quote))->render();
        $this->assertStringContainsString('Hours/Day', $html);
    }

    public function test_a_quote_of_transfers_prints_no_hours_column(): void
    {
        $quote = $this->quote([
            ['service_type' => LimoLeg::TYPE_TRANSFER, 'rate_basis' => LimoLeg::BASIS_TRIP, 'to_location' => 'Airport', 'rate' => 30],
        ]);

        $html = view('limousine::quotation-pdf', app(QuotationPdf::class)->viewData($quote))->render();

        $this->assertStringNotContainsString('Hours/Day', $html);
        $this->assertStringContainsString('Days/Trips', $html);
    }
}
