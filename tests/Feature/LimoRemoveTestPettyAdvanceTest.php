<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoPettyAdvance;
use Modules\Limousine\Models\LimoPettyLine;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/** The one-off removal of the test petty-cash advance PC/00011. */
final class LimoRemoveTestPettyAdvanceTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'Modules/Limousine/database/migrations/2026_11_23_950066_remove_test_petty_advance_pc00011.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function advance(string $reference, float $amount, string $driver = 'Abbas Naser Hamdan'): LimoPettyAdvance
    {
        $d = LimoDriver::query()->create(['name' => $driver, 'phone' => '39000001']);

        return LimoPettyAdvance::query()->create([
            'reference' => $reference, 'driver_id' => $d->id, 'date' => '2026-11-13', 'amount' => $amount,
        ]);
    }

    private function runFix(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    public function test_the_test_advance_and_its_lines_go_and_others_stay(): void
    {
        $test = $this->advance('PC/00011', 459.10);
        LimoPettyLine::query()->create([
            'advance_id' => $test->id, 'date' => '2026-11-13', 'category' => 'Fuel', 'amount' => 10,
        ]);
        $other = $this->advance('PC/00012', 50, 'Someone Else');

        $this->runFix();

        $this->assertNull(LimoPettyAdvance::query()->find($test->id));
        $this->assertSame(0, LimoPettyLine::query()->where('advance_id', $test->id)->count());
        $this->assertNotNull(LimoPettyAdvance::query()->find($other->id));
    }

    public function test_a_different_amount_or_driver_is_left_alone(): void
    {
        $wrongAmount = $this->advance('PC/00011', 300);
        $this->runFix();
        $this->assertNotNull(LimoPettyAdvance::query()->find($wrongAmount->id));

        $wrongAmount->delete();
        $wrongDriver = $this->advance('PC/00011', 459.10, 'Ali Hassan');
        $this->runFix();
        $this->assertNotNull(LimoPettyAdvance::query()->find($wrongDriver->id));
    }
}
