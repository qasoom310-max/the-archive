<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Pages\SettingsPage;
use App\Models\ReportRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Mail\DailyReportMail;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\DailyReport;
use Tests\TestCase;

/**
 * Automated daily sales + stock report: the cafe trades noon → 6 AM, so the
 * report sent just after 6 AM covers yesterday 12:00 → today 06:00, lists
 * full stock with low/out highlighted, and is emailed as a PDF to the
 * admin-managed recipient list.
 */
final class PosDailyReportTest extends TestCase
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

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 50.0,
            'opened_at' => now(),
        ]);
    }

    private function doneOrder(PosSession $session, float $total, string $orderedAt, float $tax = 0.0): PosOrder
    {
        return PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/' . uniqid(),
            'state' => OrderState::Done,
            'total' => $total,
            'tax_total' => $tax,
            'ordered_at' => Carbon::parse($orderedAt, 'UTC'),
        ]);
    }

    public function test_last_closed_window_is_noon_yesterday_to_six_am_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 06:10:00', 'UTC'));

        [$start, $end] = app(DailyReport::class)->lastClosedWindow();

        $this->assertSame('2026-06-08 12:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-09 06:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_sales_counts_only_done_orders_inside_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 06:10:00', 'UTC'));
        $session = $this->openSession();

        $this->doneOrder($session, 10.0, '2026-06-08 20:00:00', tax: 1.0); // in
        $this->doneOrder($session, 20.0, '2026-06-09 02:00:00');           // in (after midnight)
        $this->doneOrder($session, 99.0, '2026-06-08 10:00:00');           // before noon — out
        $this->doneOrder($session, 99.0, '2026-06-09 07:00:00');           // after 6am — out
        // A draft inside the window must not count.
        PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'reference' => 'POS/draft',
            'state' => OrderState::Draft,
            'total' => 50.0,
            'ordered_at' => Carbon::parse('2026-06-08 21:00:00', 'UTC'),
        ]);

        $report = app(DailyReport::class);
        [$start, $end] = $report->lastClosedWindow();
        $sales = $report->sales($start, $end);

        $this->assertSame(2, $sales['orders']);
        $this->assertSame(30.0, $sales['revenue']);
        $this->assertSame(15.0, $sales['aov']);
        $this->assertSame(1.0, $sales['tax_total']);
    }

    public function test_stock_flags_low_and_out_and_lists_them_first(): void
    {
        PosProduct::query()->create(['name' => 'Plenty', 'price' => 1, 'stock_on_hand' => 50, 'active' => true]);
        PosProduct::query()->create(['name' => 'Running low', 'price' => 1, 'stock_on_hand' => 5, 'active' => true]);
        PosProduct::query()->create(['name' => 'Empty', 'price' => 1, 'stock_on_hand' => 0, 'active' => true]);

        $stock = app(DailyReport::class)->stock();

        $this->assertSame(3, $stock['total']);
        $this->assertSame(1, $stock['out_count']);
        $this->assertSame(1, $stock['low_count']);
        // Out-of-stock sorts to the very top.
        $this->assertSame('Empty', $stock['rows'][0]['name']);
        $this->assertTrue($stock['rows'][0]['out']);
    }

    public function test_send_emails_the_pdf_to_active_recipients_only(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-09 06:10:00', 'UTC'));

        ReportRecipient::query()->create(['email' => 'owner@cafe.test', 'active' => true]);
        ReportRecipient::query()->create(['email' => 'manager@cafe.test', 'active' => true]);
        ReportRecipient::query()->create(['email' => 'former@cafe.test', 'active' => false]);

        $count = app(DailyReport::class)->sendLastClosedReport();

        $this->assertSame(2, $count);
        Mail::assertSent(DailyReportMail::class, 1);
        Mail::assertSent(DailyReportMail::class, fn (DailyReportMail $m): bool =>
            $m->hasTo('owner@cafe.test')
            && $m->hasTo('manager@cafe.test')
            && ! $m->hasTo('former@cafe.test'));
    }

    public function test_send_with_no_recipients_sends_nothing(): void
    {
        Mail::fake();

        $this->assertSame(0, app(DailyReport::class)->sendLastClosedReport());
        Mail::assertNothingSent();
    }

    public function test_admin_can_add_and_remove_recipients_from_settings(): void
    {
        $component = Livewire::test(SettingsPage::class)
            ->set('newRecipientEmail', 'New@Cafe.Test')
            ->call('addRecipient')
            ->assertHasNoErrors();

        // Stored lower-cased.
        $this->assertDatabaseHas('report_recipients', ['email' => 'new@cafe.test']);

        $id = ReportRecipient::query()->where('email', 'new@cafe.test')->value('id');
        $component->call('removeRecipient', $id);

        $this->assertDatabaseMissing('report_recipients', ['email' => 'new@cafe.test']);
    }

    public function test_invalid_recipient_email_is_rejected(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('newRecipientEmail', 'not-an-email')
            ->call('addRecipient')
            ->assertHasErrors('newRecipientEmail');

        $this->assertSame(0, ReportRecipient::query()->count());
    }

    public function test_non_admin_cannot_manage_recipients(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(SettingsPage::class)
            ->set('newRecipientEmail', 'sneaky@cafe.test')
            ->call('addRecipient')
            ->assertForbidden();

        $this->assertSame(0, ReportRecipient::query()->count());
    }

    public function test_send_now_action_emails_the_recipients(): void
    {
        Mail::fake();
        ReportRecipient::query()->create(['email' => 'owner@cafe.test', 'active' => true]);

        Livewire::test(SettingsPage::class)->call('sendNow');

        Mail::assertSent(DailyReportMail::class, 1);
    }
}
