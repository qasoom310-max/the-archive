<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Quotations;
use Modules\Limousine\Mail\QuotationMail;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;
use Tests\TestCase;

/**
 * The quotation the customer gets without a total on it.
 *
 * A quote for several journeys priced one by one is often a choice, not a
 * shopping list — and a Total at the bottom tells the customer they are buying
 * all of it. So the office can hand over, or send, the same sheet with that box
 * left off. The trips and their rates stay: what goes is the figure that reads
 * as a commitment to the whole list.
 *
 * Which copy goes out is decided per customer at the moment of sending, never
 * once for the whole desk.
 */
final class LimoQuotationWithoutTotalTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');

        // Module routes register at boot only while the module is installed,
        // and it is installed above — so the download route is mounted here.
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    /** Three journeys, priced one by one — exactly the shape this is for. */
    private function quote(): LimoQuotation
    {
        $customer = LimoCustomer::query()->create([
            'name' => 'Turbo Engineering',
            'phone' => '38467744',
            'email' => 'ops@turbo.example',
        ]);

        $quote = LimoQuotation::query()->create([
            'reference' => 'QT/01695',
            'customer_id' => $customer->id,
            'quote_date' => now(),
            'valid_until' => now()->addWeek(),
            'requested_by' => 'Office',
            'prepared_by' => 'Qassim',
            'status' => LimoQuotation::STATUS_DRAFT,
        ]);

        foreach ([[0, 'Bahrain Airport', 'Manama', 40.0], [1, 'Manama', 'Khobar', 12.0], [2, 'Khobar', 'Bahrain Airport', 96.0]] as [$i, $from, $to, $rate]) {
            LimoLeg::query()->create([
                'legable_type' => LimoQuotation::class,
                'legable_id' => $quote->id,
                'sequence' => $i,
                'reference' => (string) (90001 + $i),
                'from_location' => $from,
                'to_location' => $to,
                'start_at' => now()->addDays(3 + $i),
                'rate' => $rate, 'net_amount' => $rate,
            ]);
        }

        $quote->recalcTotal();
        $quote->save();

        return $quote->refresh();
    }

    /* ── The sheet itself ────────────────────────────────────────────────── */

    public function test_the_ordinary_sheet_still_carries_its_total(): void
    {
        $data = app(QuotationPdf::class)->viewData($this->quote());

        $this->assertFalse($data['withoutTotal']);
        $this->assertSame(148.0, $data['total']);
    }

    /**
     * The total is still WORKED OUT and simply not printed, so the two copies
     * can never disagree about the trips behind them.
     */
    public function test_the_other_sheet_knows_the_total_and_does_not_print_it(): void
    {
        $data = app(QuotationPdf::class)->viewData($this->quote(), withoutTotal: true);

        $this->assertTrue($data['withoutTotal']);
        $this->assertSame(148.0, $data['total']);
        // The journeys and what each costs are the substance of a quote; they stay.
        $this->assertCount(3, $data['lines']);
        $this->assertSame(40.0, $data['lines'][0]['amount']);
    }

    public function test_the_two_copies_are_named_apart(): void
    {
        $pdf = app(QuotationPdf::class);
        $quote = $this->quote();

        $this->assertSame('quotation-QT-01695.pdf', $pdf->filename($quote));
        $this->assertSame('quotation-QT-01695-no-total.pdf', $pdf->filename($quote, withoutTotal: true));
    }

    /**
     * The view data is only half the claim — this is the sheet itself, so a
     * blade that forgot the flag could not pass.
     */
    public function test_the_rendered_sheet_drops_the_box_and_keeps_the_trips(): void
    {
        $quote = $this->quote();
        $pdf = app(QuotationPdf::class);

        $ordinary = view('limousine::quotation-pdf', $pdf->viewData($quote))->render();
        $this->assertStringContainsString('Subtotal', $ordinary);
        $this->assertStringContainsString('Total', $ordinary);

        $without = view('limousine::quotation-pdf', $pdf->viewData($quote, withoutTotal: true))->render();
        $this->assertStringNotContainsString('Subtotal', $without);
        $this->assertStringNotContainsString('VAT', $without);
        // The journeys and their rates are the quote; they stay on the page.
        $this->assertStringContainsString('Bahrain Airport', $without);
        $this->assertStringContainsString('Rate per Unit', $without);
    }

    /* ── Handing it over ─────────────────────────────────────────────────── */

    public function test_the_download_gives_the_ordinary_copy_by_default(): void
    {
        $quote = $this->quote();

        $this->get('/app/limousine/quotation/' . $quote->id . '/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="quotation-QT-01695.pdf"');
    }

    public function test_the_download_gives_the_total_free_copy_when_asked(): void
    {
        $quote = $this->quote();

        $this->get('/app/limousine/quotation/' . $quote->id . '/download?without_total=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="quotation-QT-01695-no-total.pdf"');
    }

    /* ── Sending it ──────────────────────────────────────────────────────── */

    public function test_sending_carries_the_ordinary_copy_unless_ticked(): void
    {
        Mail::fake();
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->call('sendQuotation')
            ->assertHasNoErrors();

        Mail::assertSent(QuotationMail::class, function (QuotationMail $mail): bool {
            return $mail->withoutTotal === false
                && $mail->filename === 'quotation-QT-01695.pdf';
        });
    }

    public function test_ticking_the_box_sends_the_copy_with_no_total(): void
    {
        Mail::fake();
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->set('sendWithoutTotal', true)
            ->call('sendQuotation')
            ->assertHasNoErrors();

        Mail::assertSent(QuotationMail::class, function (QuotationMail $mail): bool {
            // The BODY has to know as well: a total-free attachment under an
            // e-mail that summarises the total hands the figure over anyway.
            return $mail->withoutTotal === true
                && $mail->filename === 'quotation-QT-01695-no-total.pdf';
        });
    }

    /**
     * Not remembered between sends. A quote that went out without a total to
     * one customer must not silently do the same for the next.
     */
    public function test_the_tick_is_cleared_for_the_next_customer(): void
    {
        Mail::fake();
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->set('sendWithoutTotal', true)
            ->call('sendQuotation')
            ->assertHasNoErrors()
            // Opening it again for the next send starts clean.
            ->call('openSend', $quote->id)
            ->assertSet('sendWithoutTotal', false);
    }

    public function test_closing_the_dialog_clears_the_tick(): void
    {
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->call('openSend', $quote->id)
            ->set('sendWithoutTotal', true)
            ->call('closeSend')
            ->assertSet('sendWithoutTotal', false);
    }

    /* ── The button is on the row ────────────────────────────────────────── */

    public function test_the_list_offers_both_downloads(): void
    {
        $quote = $this->quote();

        Livewire::test(Quotations::class)
            ->assertSee('Download quotation')
            ->assertSee('Download without the total')
            ->assertSee('without_total=1', escape: false);

        $this->assertNotNull($quote->fresh());
    }
}
