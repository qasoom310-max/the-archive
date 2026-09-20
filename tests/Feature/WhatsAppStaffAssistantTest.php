<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Pricing\FareCalculator;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Events\LimoPaymentLinkPaid;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Models\LimoQuotation;
use Modules\WhatsApp\Assistant\Brain\Brain;
use Modules\WhatsApp\Assistant\Brain\BrainReply;
use Modules\WhatsApp\Assistant\NotifyStaffOfPayment;
use Modules\WhatsApp\Livewire\AssistantSettings;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Modules\WhatsApp\Models\AssistantStaff;
use Modules\WhatsApp\Models\Conversation;
use Modules\WhatsApp\Models\ConversationMessage;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Tests\TestCase;

/**
 * The WhatsApp staff assistant, against the brief's acceptance checks. The AI
 * is replaced by a scripted brain, Meta and the payment portal by Http::fake —
 * what is under test is everything the ERP decides.
 */
final class WhatsAppStaffAssistantTest extends TestCase
{
    use DatabaseMigrations;

    private const APP_SECRET = 'meta-app-secret';

    private const VERIFY = 'verify-me';

    private const STAFF = '97338467744';

    private ScriptedBrain $brain;

    private User $owner;

    private int $ws;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
        Route::middleware('web')->group(base_path('Modules/WhatsApp/routes/web.php'));
        $provider = new \Modules\WhatsApp\Providers\WhatsAppServiceProvider($this->app);
        // `register()` binds `ReplySink` (and `Brain`, immediately overridden
        // below) — installing a module mid-test does not itself re-run a
        // provider's register(), only its schema/manifest side.
        $provider->register();
        $provider->boot();

        Artisan::call('pricing:seed');

        $this->ws = (int) app(WorkspaceManager::class)->ensureMain()->id;
        $this->owner = User::factory()->create(['name' => 'Qassim', 'is_admin' => true, 'is_super_admin' => true]);

        WhatsAppConfiguration::query()->create([
            'phone_number_id' => '555', 'access_token' => 'token', 'app_secret' => self::APP_SECRET,
            'webhook_verify_token' => self::VERIFY, 'enabled' => true,
        ]);
        AssistantConfiguration::query()->create(['ai_api_key' => 'sk-test', 'ai_model' => 'claude-opus-5', 'enabled' => true]);
        AssistantStaff::query()->create(['phone' => self::STAFF, 'user_id' => $this->owner->id, 'active' => true]);

        $this->brain = new ScriptedBrain();
        $this->app->instance(Brain::class, $this->brain);

        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'media-1']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]]),
            'wanaan-bh.com/*' => Http::response(['url' => 'https://wanaan-bh.com/service-order/tok123', 'token' => 'tok123']),
        ]);

        $this->withoutDefer();
    }

    /* ── Webhook ──────────────────────────────────────────────────────── */

    public function test_meta_verification_echoes_the_challenge(): void
    {
        $this->get("/integrations/whatsapp/{$this->ws}/webhook?hub.mode=subscribe&hub.verify_token=" . self::VERIFY . '&hub.challenge=abc123')
            ->assertOk()->assertSee('abc123');

        $this->get("/integrations/whatsapp/{$this->ws}/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc123")
            ->assertForbidden();
    }

    public function test_an_unknown_database_is_not_found(): void
    {
        $this->get('/integrations/whatsapp/999/webhook?hub.mode=subscribe&hub.verify_token=' . self::VERIFY . '&hub.challenge=x')
            ->assertNotFound();
    }

    public function test_a_bad_signature_is_rejected_and_nothing_runs(): void
    {
        $body = $this->payload(self::STAFF, 'wamid.1', 'hello');

        $this->call('POST', "/integrations/whatsapp/{$this->ws}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=nope',
        ], $body)->assertForbidden();

        $this->assertSame(0, $this->brain->calls);
        $this->assertSame(0, ConversationMessage::query()->count());
    }

    public function test_a_duplicate_message_id_is_processed_once(): void
    {
        $this->brain->queue(new BrainReply('end_turn', 'Hi!', [], []));

        $this->deliver(self::STAFF, 'wamid.dup', 'hello')->assertOk();
        $this->deliver(self::STAFF, 'wamid.dup', 'hello')->assertOk();

        $this->assertSame(1, $this->brain->calls);
        $this->assertSame(1, ConversationMessage::query()->where('wa_message_id', 'wamid.dup')->count());
    }

    /* ── Authorisation + kill-switch ───────────────────────────────────── */

    public function test_an_unknown_number_gets_one_not_authorized_reply_then_silence(): void
    {
        $this->deliver('97300000000', 'wamid.a', 'quote sedan airport');
        $this->deliver('97300000000', 'wamid.b', 'hello?');

        $this->assertSame(0, $this->brain->calls);
        $this->assertSame(1, $this->sentTexts()->filter(fn (string $t): bool => str_contains($t, "isn't authorized"))->count());
        $this->assertSame(1, $this->sentTexts()->count());
    }

    public function test_the_kill_switch_silences_the_bot(): void
    {
        AssistantConfiguration::current()->update(['enabled' => false]);

        $this->deliver(self::STAFF, 'wamid.k', 'hello');

        $this->assertSame(0, $this->brain->calls);
        Http::assertNothingSent();
    }

    /* ── Language ─────────────────────────────────────────────────────── */

    public function test_arabic_in_arabic_out(): void
    {
        $this->deliver('97300000001', 'wamid.ar', 'كم سعر المطار؟');

        $this->assertTrue($this->sentTexts()->contains(fn (string $t): bool => str_contains($t, 'هذا الرقم غير مخوّل')));
    }

    /* ── Fares ────────────────────────────────────────────────────────── */

    public function test_the_fare_comes_from_the_tables_and_a_missing_one_is_not_guessed(): void
    {
        $fares = app(FareCalculator::class);

        $sedan = $fares->quote('airport', 'sedan', 'zone_main');
        $this->assertTrue($sedan->found);
        $this->assertSame(15.0, $sedan->total);

        $this->assertFalse($fares->quote('airport', 'sedan', 'no_such_zone')->found);
        $this->assertFalse($fares->quote('airport', 'hiace', 'zone_main')->found); // bus not offered on airport
    }

    public function test_the_quote_tool_result_carries_the_table_fare(): void
    {
        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 't1', 'name' => 'get_fare', 'input' => ['service' => 'airport', 'car' => 'sedan', 'option' => 'zone_main']]], [['type' => 'tool_use']]));
        $this->brain->queue(new BrainReply('end_turn', 'Sedan airport: 15 BHD. Reply YES to book.', [], []));

        $this->deliver(self::STAFF, 'wamid.q', 'quote sedan airport pickup to Seef');

        $toolResult = $this->brain->lastMessages[count($this->brain->lastMessages) - 1]['content'][0]['content'];
        $this->assertSame(15, json_decode($toolResult, true)['total']);
        $this->assertTrue(ActivityLog::query()->where('action', 'quoted')->where('user_id', $this->owner->id)->exists());
    }

    /* ── Confirm before create ───────────────────────────────────────── */

    public function test_nothing_is_booked_until_an_explicit_yes(): void
    {
        $this->proposeBooking();

        $this->assertSame(0, LimoBooking::query()->count());
        $this->assertTrue($this->sentTexts()->contains(fn (string $t): bool => str_contains($t, 'Reply YES to book')));

        $this->deliver(self::STAFF, 'wamid.yes', 'YES');

        $booking = LimoBooking::query()->firstOrFail();
        $leg = $booking->legs()->firstOrFail();
        $this->assertSame(15.0, (float) $booking->fare);
        $this->assertSame('online', $booking->payment_method);
        $this->assertSame('Qassim', $booking->prepared_by);
        $this->assertSame(LimoLeg::STATUS_QUEUE, $leg->status);
        $this->assertNull($leg->driver_id);
        $this->assertNull($leg->car_id);
        $this->assertTrue($this->sentTexts()->contains(fn (string $t): bool => str_contains($t, 'Booked ✅')));
        $this->assertTrue(ActivityLog::query()->where('action', 'created')->where('user_id', $this->owner->id)->exists());
    }

    public function test_a_no_cancels_the_proposal(): void
    {
        $this->proposeBooking();
        $this->deliver(self::STAFF, 'wamid.no', 'no');
        $this->deliver(self::STAFF, 'wamid.late-yes', 'yes'); // no proposal left: goes to the AI

        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_a_yes_to_a_stale_proposal_creates_nothing(): void
    {
        $this->proposeBooking();
        $this->travel(Conversation::DRAFT_TTL_MINUTES + 1)->minutes();
        $this->brain->queue(new BrainReply('end_turn', 'What would you like to do?', [], []));

        $this->deliver(self::STAFF, 'wamid.stale', 'yes');

        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_the_booking_fare_is_reread_not_taken_from_the_ai(): void
    {
        $this->proposeBooking();
        // The fare changes between the proposal and the YES.
        \App\Models\Pricing\PricingRate::query()
            ->whereIn('option_id', \App\Models\Pricing\PricingOption::query()->where('service_id', 'airport')->where('code', 'zone_main')->pluck('id'))
            ->where('car_id', 'sedan')->update(['amount' => 18]);

        $this->deliver(self::STAFF, 'wamid.yes2', 'نعم');

        $this->assertSame(18.0, (float) LimoBooking::query()->firstOrFail()->fare);
    }

    /* ── Permissions ──────────────────────────────────────────────────── */

    public function test_a_staff_member_without_booking_rights_cannot_book(): void
    {
        $clerk = User::factory()->create(['is_admin' => false]);
        AssistantStaff::query()->create(['phone' => '97333333333', 'user_id' => $clerk->id, 'active' => true]);

        $this->brain->queue($this->proposeBookingReply());
        $this->brain->queue(new BrainReply('end_turn', "Your ERP permissions don't allow that.", [], []));

        $this->deliver('97333333333', 'wamid.c1', 'book sedan airport');
        $this->deliver('97333333333', 'wamid.c2', 'yes');

        $this->assertSame(0, LimoBooking::query()->count());
        $this->assertNull(Conversation::query()->where('wa_id', '97333333333')->firstOrFail()->pendingAction());
    }

    /* ── Documents + payment links ───────────────────────────────────── */

    public function test_a_quotation_pdf_is_prepared_and_sent_after_yes(): void
    {
        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'q1', 'name' => 'propose_quotation', 'input' => $this->tripInput()]], [['type' => 'tool_use']]));
        $this->deliver(self::STAFF, 'wamid.qp', 'quotation for Ahmed');
        $this->assertSame(0, LimoQuotation::query()->count());

        $this->deliver(self::STAFF, 'wamid.qy', 'yes');

        $this->assertSame(1, LimoQuotation::query()->count());
        Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/media'));
        Http::assertSent(fn (HttpRequest $r): bool => ($r->data()['type'] ?? null) === 'document');
    }

    public function test_the_invoice_pdf_of_a_booking_is_sent_after_yes(): void
    {
        $booking = $this->bookThroughTheBot();

        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'd1', 'name' => 'propose_document', 'input' => ['reference' => (string) $booking->reference, 'document' => 'invoice']]], [['type' => 'tool_use']]));
        $this->deliver(self::STAFF, 'wamid.inv', 'invoice please');
        $this->deliver(self::STAFF, 'wamid.invy', 'ok');

        Http::assertSent(fn (HttpRequest $r): bool => ($r->data()['type'] ?? null) === 'document');
    }

    public function test_a_payment_link_is_created_after_yes_and_the_staff_member_hears_when_it_is_paid(): void
    {
        LimoPortalConfiguration::query()->create(['portal_url' => 'https://wanaan-bh.com', 'shared_secret' => 'portal', 'enabled' => true]);
        $booking = $this->bookThroughTheBot();

        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'p1', 'name' => 'propose_payment_link', 'input' => ['reference' => (string) $booking->reference]]], [['type' => 'tool_use']]));
        $this->deliver(self::STAFF, 'wamid.pl', 'payment link');
        $this->assertSame(0, LimoPaymentLink::query()->count());

        $this->deliver(self::STAFF, 'wamid.ply', 'yes');

        $link = LimoPaymentLink::query()->firstOrFail();
        $this->assertSame(15.0, (float) $link->amount);
        $this->assertTrue($this->sentTexts()->contains(fn (string $t): bool => str_contains($t, 'https://wanaan-bh.com/service-order/tok123')));

        // The customer pays: the booking is settled and the staff member told.
        $link->forceFill(['status' => LimoPaymentLink::STATUS_PAID])->save();
        $booking->advance = 15;
        $booking->save();
        app(NotifyStaffOfPayment::class)->handle(new LimoPaymentLinkPaid($link->id));
        app(NotifyStaffOfPayment::class)->handle(new LimoPaymentLinkPaid($link->id)); // redelivery

        $this->assertSame(1, $this->sentTexts()->filter(fn (string $t): bool => str_contains($t, 'Paid ✅'))->count());
    }

    /* ── Settings ─────────────────────────────────────────────────────── */

    public function test_settings_are_admin_only_and_authorise_a_number(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(AssistantSettings::class)->assertForbidden();

        $this->actingAs($this->owner);
        Livewire::test(AssistantSettings::class)
            ->set('newPhone', '+973 3999 1869')
            ->set('newUserId', $this->owner->id)
            ->call('addStaff')
            ->assertHasNoErrors();

        $this->assertTrue(AssistantStaff::query()->where('phone', '97339991869')->exists());
        $this->assertStringNotContainsString('sk-test', Livewire::test(AssistantSettings::class)->html());
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function tripInput(): array
    {
        return [
            'service' => 'airport', 'car' => 'sedan', 'option' => 'zone_main',
            'pickup_at' => now('Asia/Bahrain')->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'),
            'from' => 'Bahrain Airport', 'to' => 'Seef', 'customer_name' => 'Ahmed Ali', 'customer_phone' => '33112233',
        ];
    }

    private function proposeBookingReply(): BrainReply
    {
        return new BrainReply('tool_use', '', [['id' => 'b1', 'name' => 'propose_booking', 'input' => $this->tripInput()]], [['type' => 'tool_use']]);
    }

    private function proposeBooking(): void
    {
        $this->brain->queue($this->proposeBookingReply());
        $this->deliver(self::STAFF, 'wamid.prop-' . uniqid(), 'book sedan airport tomorrow 9am to Seef, Ahmed Ali 33112233');
    }

    private function bookThroughTheBot(): LimoBooking
    {
        $this->proposeBooking();
        $this->deliver(self::STAFF, 'wamid.book-' . uniqid(), 'yes');

        return LimoBooking::query()->firstOrFail();
    }

    private function payload(string $from, string $id, string $text): string
    {
        return (string) json_encode(['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]],
        ]]]]]]]);
    }

    private function deliver(string $from, string $id, string $text): \Illuminate\Testing\TestResponse
    {
        $body = $this->payload($from, $id, $text);

        return $this->call('POST', "/integrations/whatsapp/{$this->ws}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET),
        ], $body);
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function sentTexts(): \Illuminate\Support\Collection
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): mixed => $pair[0]->data()['text']['body'] ?? null)
            ->filter(fn (mixed $body): bool => is_string($body))
            ->reject(fn (string $body): bool => in_array($body, ['One moment…', 'لحظة…'], true))
            ->values();
    }
}

/** A brain that answers from a script, and remembers what it was asked. */
final class ScriptedBrain implements Brain
{
    public int $calls = 0;

    /** @var list<array<string, mixed>> */
    public array $lastMessages = [];

    /** @var list<BrainReply> */
    private array $script = [];

    public function queue(BrainReply $reply): void
    {
        $this->script[] = $reply;
    }

    public function respond(string $apiKey, string $model, string $system, array $messages, array $tools): BrainReply
    {
        $this->calls++;
        $this->lastMessages = $messages;

        return array_shift($this->script) ?? new BrainReply('end_turn', 'OK', [], []);
    }
}
