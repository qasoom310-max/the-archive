<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Pricing\PricingSetting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\BorderOffers;
use Tests\TestCase;

/**
 * The WordPress plugin's half of the contract (wp-plugin/wanaan-border-offers),
 * checked against the real ERP endpoint: what the plugin signs, the ERP
 * accepts; what the plugin passes on, is only the hours.
 */
final class BorderOffersPluginTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'plugin-contract-secret';

    private const PLUGIN = 'wp-plugin/wanaan-border-offers';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        require_once dirname(__DIR__, 2) . '/' . self::PLUGIN . '/includes/class-wbo-signature.php';
        require_once dirname(__DIR__, 2) . '/' . self::PLUGIN . '/includes/class-wbo-query.php';
        require_once dirname(__DIR__, 2) . '/' . self::PLUGIN . '/includes/class-wbo-strings.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
        Carbon::setTestNow('2026-10-20 00:00:00');

        PricingSetting::query()->create(['whatsapp' => '97339991869']);
        LimoPortalConfiguration::query()->create([
            'portal_url' => 'https://example.test', 'shared_secret' => self::SECRET, 'enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_search_signed_by_the_plugin_is_answered_by_the_erp(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Traveller']);
        LimoBooking::query()->create(['customer_id' => $customer->id])->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER, 'status' => LimoLeg::STATUS_QUEUE,
            'from_location' => 'Manama', 'from_country' => 'BH', 'to_location' => 'Riyadh', 'to_country' => 'SA',
            'start_at' => '2026-10-21 01:00', 'rate' => 100, 'rate_basis' => LimoLeg::BASIS_TRIP, 'days' => 1,
        ]);

        $workspace = (int) app(WorkspaceManager::class)->ensureMain()->getKey();
        $search = \WBO_Query::normalise('sa', 'bh', '2026-10-21', '2026-10-20');
        $this->assertIsArray($search);

        $path = \WBO_Query::path($workspace, $search);
        $response = $this->withHeaders(\WBO_Signature::headers('GET', $path, self::SECRET))->get($path);

        $response->assertOk();
        $answer = \WBO_Query::public_answer($response->json());
        $this->assertNotNull($answer);
        $this->assertTrue($answer['hours'][3]['discount']);
        $this->assertFalse($answer['hours'][2]['discount']);
        $this->assertSame(30.0, (float) $answer['discount_percent']);
        $this->assertSame('97339991869', $answer['whatsapp']);
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $workspace = (int) app(WorkspaceManager::class)->ensureMain()->getKey();
        $path = \WBO_Query::path($workspace, ['from' => 'SA', 'to' => 'BH', 'date' => '2026-10-21']);

        $this->withHeaders(\WBO_Signature::headers('GET', $path, 'not-the-secret'))->get($path)->assertStatus(401);
    }

    public function test_the_plugin_only_lets_through_a_real_question(): void
    {
        $today = '2026-10-20';

        $this->assertSame('country', \WBO_Query::normalise('QA', 'BH', '2026-10-21', $today));
        $this->assertSame('country', \WBO_Query::normalise(['SA'], 'BH', '2026-10-21', $today));
        $this->assertSame('same_country', \WBO_Query::normalise('SA', 'SA', '2026-10-21', $today));
        $this->assertSame('date', \WBO_Query::normalise('SA', 'BH', '2026-02-30', $today));
        $this->assertSame('date', \WBO_Query::normalise('SA', 'BH', '2026-10-19', $today));
        $this->assertSame('date', \WBO_Query::normalise('SA', 'BH', '2027-01-19', $today));
        $this->assertSame('date', \WBO_Query::normalise('SA', 'BH', "2026-10-21'; drop", $today));
        $this->assertSame(['from' => 'SA', 'to' => 'BH', 'date' => '2027-01-18'], \WBO_Query::normalise(' sa ', 'BH', '2027-01-18', $today));
    }

    public function test_the_plugin_and_the_erp_offer_the_same_countries_and_window(): void
    {
        $this->assertSame(BorderOffers::COUNTRIES, \WBO_Query::COUNTRIES);
        $this->assertSame(BorderOffers::MAX_DAYS_AHEAD, \WBO_Query::MAX_DAYS_AHEAD);
        $this->assertSame(BorderOffers::COUNTRY_NAMES['SA']['ar'], \WBO_Strings::countries('ar')[1]['name']);
    }

    public function test_the_browser_gets_only_the_hours(): void
    {
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = ['hour' => $h, 'bookable' => true, 'discount' => $h === 3, 'cars' => 1, 'booking' => 'BK/1', 'customer' => 'X'];
        }

        $answer = \WBO_Query::public_answer([
            'enabled' => true, 'discount_percent' => 30, 'whatsapp' => '+973 3999 1869', 'secret' => 'leak',
            'query' => ['from' => 'SA', 'to' => 'BH', 'date' => '2026-10-21'],
            'hours' => $hours,
            'upcoming' => [['date' => '2026-10-21', 'hour' => 3], ['date' => 'bad', 'hour' => 1]],
        ]);

        $this->assertNotNull($answer);
        $this->assertStringNotContainsString('leak', (string) json_encode($answer));
        $this->assertSame(['hour', 'bookable', 'discount', 'cars'], array_keys($answer['hours'][0]));
        $this->assertSame('97339991869', $answer['whatsapp']);
        $this->assertCount(1, $answer['upcoming']);

        $this->assertNull(\WBO_Query::public_answer(['hours' => array_slice($hours, 0, 5)]));
        $this->assertNull(\WBO_Query::public_answer('nonsense'));
    }

    public function test_every_plugin_file_is_valid_php(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path(self::PLUGIN)));
        $checked = 0;

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, $file->getPathname() . ': ' . implode("\n", $out));
            $checked++;
        }

        $this->assertGreaterThanOrEqual(10, $checked);
    }
}
