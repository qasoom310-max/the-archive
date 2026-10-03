<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\SettingManager;
use App\Livewire\Pages\AppFeatureSettings;
use App\Livewire\Pages\SettingsPage;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Http\Controllers\PosReceiptPrintController;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosReceiptImageRenderer;
use Modules\Pos\Support\ReceiptPrinter;
use Tests\TestCase;

/**
 * The network receipt printer: its IP is set on POS → Settings, and the till
 * sends each receipt straight to it (Epson ePOS-Print, from the browser).
 */
final class PosReceiptPrinterTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        (new SettingSeeder())->run();
        app(SettingManager::class)->flush();
    }

    public function test_no_address_means_no_network_printer(): void
    {
        $this->assertNull(ReceiptPrinter::config());
    }

    public function test_the_printer_ip_is_saved_from_the_pos_settings_tab(): void
    {
        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->assertSee(__('Receipt printer'))
            ->set('printerIp', ' 192.168.1.50 ')
            ->set('printerWidth', 576)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee(__('Print a test page'));

        $config = ReceiptPrinter::config();
        $this->assertNotNull($config);
        $this->assertSame('https://192.168.1.50/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000', $config['url']);
        $this->assertSame(576, $config['width']);
    }

    public function test_plain_http_and_58mm_paper_can_be_chosen(): void
    {
        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->set('printerIp', '10.0.0.7:8008')
            ->set('printerHttps', false)
            ->set('printerWidth', 384)
            ->call('save')
            ->assertHasNoErrors();

        $config = ReceiptPrinter::config();
        $this->assertSame('http://10.0.0.7:8008/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000', $config['url'] ?? null);
        $this->assertSame(384, $config['width'] ?? null);
    }

    public function test_a_bad_address_is_refused_and_nothing_is_saved(): void
    {
        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->set('printerIp', 'http://192.168.1.50/"><script>')
            ->call('save')
            ->assertHasErrors('printerIp');

        $this->assertNull(ReceiptPrinter::config());
    }

    public function test_clearing_the_address_turns_network_printing_off(): void
    {
        ReceiptPrinter::save('192.168.1.50', true, 576);

        Livewire::test(AppFeatureSettings::class, ['module' => 'pos'])
            ->assertSet('printerIp', '192.168.1.50')
            ->set('printerIp', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(ReceiptPrinter::config());
    }

    public function test_other_apps_settings_have_no_printer_box(): void
    {
        app(ModuleManager::class)->install('rental');

        Livewire::test(AppFeatureSettings::class, ['module' => 'rental'])
            ->assertDontSee(__('Receipt printer'));
    }

    public function test_the_printer_keys_stay_off_the_central_settings_page(): void
    {
        ReceiptPrinter::save('192.168.1.50', true, 576);
        app(SettingManager::class)->flush();

        Livewire::test(SettingsPage::class)->assertDontSee('pos.printer.ip');
    }

    public function test_the_receipt_comes_as_data_for_the_till_to_draw(): void
    {
        $session = PosSession::query()->create([
            'reference' => 'POS-S/0001', 'state' => SessionState::Opened, 'opening_cash' => 0, 'opened_at' => now(),
        ]);
        $product = PosProduct::query()->create(['name' => 'شيشه بلو بيري', 'price' => 1.6, 'tax_rate' => 0, 'active' => true]);
        $order = PosOrder::query()->create([
            'pos_session_id' => $session->id, 'reference' => 'POS/1/0001', 'state' => OrderState::Draft,
        ]);
        $line = $order->lines()->make(['pos_product_id' => $product->id, 'name' => $product->name, 'qty' => 1, 'unit_price' => 1.6, 'tax_rate' => 0]);
        $line->recompute();
        $line->save();
        $order->recalculate();
        $order->registerPayment(PosPaymentMethod::query()->create(['name' => 'Card', 'is_cash' => false, 'active' => true]), 1.6);
        $order->finalizeSale();

        $request = Request::create('/app/pos/order/' . $order->id . '/receipt', 'GET', ['format' => 'json']);
        $response = (new PosReceiptPrintController())(app(PosReceiptImageRenderer::class), (int) $order->id, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(true);
        $this->assertSame('POS/1/0001', $data['orderReference']);
        $this->assertSame('شيشه بلو بيري', $data['lines'][0]['name']);
        $this->assertSame('Card', $data['payments'][0]['method']);
        $this->assertSame(__('Total'), $data['labels']['total']);
        $this->assertArrayNotHasKey('logoPath', $data, 'A server file path never leaves the server.');
    }
}
