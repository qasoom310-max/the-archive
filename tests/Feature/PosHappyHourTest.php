<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Pos\Enums\PrepStation;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\HappyHour;
use Tests\TestCase;

/**
 * Sweileh Café late-night happy hour (00:00–06:00 Bahrain, that database only):
 * shisha rings up at a flat 1.400, food & drinks come off by 25%.
 */
final class PosHappyHourTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function beSweilehCafeAt(string $bahrainTime): void
    {
        Setting::set('company.name', 'Sweileh Cafe');
        // A fixed instant expressed in Bahrain local time.
        Carbon::setTestNow(Carbon::parse($bahrainTime, 'Asia/Bahrain'));
    }

    private function shishaProduct(float $price = 1.6): PosProduct
    {
        $category = PosCategory::query()->create([
            'name' => 'Shisha', 'slug' => 'shisha', 'active' => true, 'station' => PrepStation::Shisha,
        ]);

        return PosProduct::query()->create([
            'name' => 'Grape Shisha', 'price' => $price, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $category->id,
        ]);
    }

    private function foodProduct(float $price = 2.0): PosProduct
    {
        $category = PosCategory::query()->create([
            'name' => 'Hot Drinks', 'slug' => 'hot-drinks', 'active' => true, // no station
        ]);

        return PosProduct::query()->create([
            'name' => 'Karak', 'price' => $price, 'tax_rate' => 0.0,
            'active' => true, 'pos_category_id' => $category->id,
        ]);
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001', 'state' => SessionState::Opened,
            'opening_cash' => 0.0, 'opened_at' => now(),
        ]);
    }

    // ── The window + database gate ──────────────────────────────────────────

    public function test_window_is_midnight_to_six_am_bahrain_time(): void
    {
        $hh = app(HappyHour::class);

        $this->assertTrue($hh->withinWindow(Carbon::parse('2026-07-14 00:00', 'Asia/Bahrain')));
        $this->assertTrue($hh->withinWindow(Carbon::parse('2026-07-14 02:30', 'Asia/Bahrain')));
        $this->assertTrue($hh->withinWindow(Carbon::parse('2026-07-14 05:59', 'Asia/Bahrain')));
        $this->assertFalse($hh->withinWindow(Carbon::parse('2026-07-14 06:00', 'Asia/Bahrain')));
        $this->assertFalse($hh->withinWindow(Carbon::parse('2026-07-14 12:00', 'Asia/Bahrain')));
        $this->assertFalse($hh->withinWindow(Carbon::parse('2026-07-13 23:59', 'Asia/Bahrain')));
    }

    public function test_window_is_evaluated_in_bahrain_time_not_the_server_timezone(): void
    {
        // 03:00 UTC is 06:00 in Bahrain (UTC+3) — the window has just CLOSED.
        $this->assertFalse(app(HappyHour::class)->withinWindow(Carbon::parse('2026-07-14 03:00', 'UTC')));
        // 00:00 UTC is 03:00 Bahrain — inside the window.
        $this->assertTrue(app(HappyHour::class)->withinWindow(Carbon::parse('2026-07-14 00:00', 'UTC')));
    }

    public function test_only_the_sweileh_cafe_database_qualifies(): void
    {
        $hh = app(HappyHour::class);

        Setting::set('company.name', 'Kaleem Perfume W.L.L');
        $this->assertFalse($hh->isSweilehCafe());

        // Tolerant of the Café/Cafe spelling and the sweileh/swelieh transposition.
        foreach (['Sweileh Cafe', 'Swelieh Café', 'sweileh  cafe'] as $name) {
            Setting::set('company.name', $name);
            $this->assertTrue($hh->isSweilehCafe(), "[$name] should qualify");
        }
    }

    public function test_not_active_in_another_database_even_inside_the_window(): void
    {
        Setting::set('company.name', 'Kaleem Perfume W.L.L');
        Carbon::setTestNow(Carbon::parse('2026-07-14 02:00', 'Asia/Bahrain'));

        $this->assertFalse(app(HappyHour::class)->active());
    }

    // ── Pricing at the register ─────────────────────────────────────────────

    public function test_shisha_rings_up_at_the_flat_price_during_the_window(): void
    {
        $this->beSweilehCafeAt('2026-07-14 02:00');
        $session = $this->openSession();
        $shisha = $this->shishaProduct(1.6);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $shisha->id);

        $line = PosOrder::query()->latest('id')->first()?->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(1.4, round((float) $line->unit_price, 2));
        $this->assertSame(0.0, round((float) $line->discount, 2));
        $this->assertSame(1.4, round((float) $line->total, 2));
    }

    public function test_food_and_drinks_get_the_percentage_off_during_the_window(): void
    {
        $this->beSweilehCafeAt('2026-07-14 02:00');
        $session = $this->openSession();
        $food = $this->foodProduct(2.0);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $food->id);

        $line = PosOrder::query()->latest('id')->first()?->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(2.0, round((float) $line->unit_price, 2));   // price unchanged…
        $this->assertSame(25.0, round((float) $line->discount, 2));    // …25% off
        $this->assertSame(1.5, round((float) $line->total, 2));        // 2.00 − 25%
    }

    public function test_repeated_taps_stack_onto_one_discounted_line(): void
    {
        $this->beSweilehCafeAt('2026-07-14 02:00');
        $session = $this->openSession();
        $food = $this->foodProduct(2.0);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $food->id)
            ->call('addProduct', $food->id);

        $lines = PosOrder::query()->latest('id')->first()?->lines()->get();
        $this->assertNotNull($lines);
        $this->assertCount(1, $lines);                               // one line, not two
        $this->assertSame(2.0, round((float) $lines->first()->qty, 2));
        $this->assertSame(3.0, round((float) $lines->first()->total, 2)); // 2 × 1.50
    }

    public function test_outside_the_window_prices_are_normal(): void
    {
        $this->beSweilehCafeAt('2026-07-14 12:00');   // midday — deal closed
        $session = $this->openSession();
        $shisha = $this->shishaProduct(1.6);
        $food = $this->foodProduct(2.0);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $shisha->id)
            ->call('addProduct', $food->id);

        $order = PosOrder::query()->latest('id')->first();
        $this->assertNotNull($order);

        $shishaLine = $order->lines()->where('pos_product_id', $shisha->id)->first();
        $foodLine = $order->lines()->where('pos_product_id', $food->id)->first();

        $this->assertSame(1.6, round((float) $shishaLine?->unit_price, 2));
        $this->assertSame(0.0, round((float) $shishaLine?->discount, 2));
        $this->assertSame(2.0, round((float) $foodLine?->unit_price, 2));
        $this->assertSame(0.0, round((float) $foodLine?->discount, 2));
    }

    public function test_a_line_rung_up_in_the_window_keeps_its_price_after_it_closes(): void
    {
        $this->beSweilehCafeAt('2026-07-14 02:00');
        $session = $this->openSession();
        $shisha = $this->shishaProduct(1.6);

        $component = Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $shisha->id);

        // The window closes; adding a SECOND unit now would be full price, so it
        // must land on a NEW line rather than merging into the 1.400 one.
        Carbon::setTestNow(Carbon::parse('2026-07-14 07:00', 'Asia/Bahrain'));
        $component->call('addProduct', $shisha->id);

        $lines = PosOrder::query()->latest('id')->first()?->lines()->orderBy('id')->get();
        $this->assertNotNull($lines);
        $this->assertCount(2, $lines);
        $this->assertSame(1.4, round((float) $lines[0]->unit_price, 2)); // the deal, preserved
        $this->assertSame(1.6, round((float) $lines[1]->unit_price, 2)); // full price now
    }
}
