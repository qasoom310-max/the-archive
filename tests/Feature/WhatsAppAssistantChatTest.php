<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoQuotation;
use Modules\WhatsApp\Assistant\Brain\Brain;
use Modules\WhatsApp\Assistant\Brain\BrainReply;
use Modules\WhatsApp\Livewire\AssistantChat;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Modules\WhatsApp\Models\AssistantStaff;
use Modules\WhatsApp\Models\Conversation;
use Tests\TestCase;

/**
 * The in-ERP assistant test chat — the same {@see \Modules\WhatsApp\Assistant\StaffAssistant}
 * WhatsApp uses, driven through a {@see \Modules\WhatsApp\Assistant\WebReplySink}
 * instead of Meta. Built so the bot could be tried and reviewed before
 * Meta/WhatsApp Business was set up.
 *
 * What matters here is that it is genuinely the SAME bot (a booking really
 * gets created, the confirm-before-YES rule still holds, no Meta HTTP call
 * is ever made) and that a document turn hands back a real, downloadable
 * PDF scoped to the right conversation — not a re-test of the assistant's
 * own tool/fare/permission logic, which `WhatsAppStaffAssistantTest` already
 * covers end to end.
 */
final class WhatsAppAssistantChatTest extends TestCase
{
    use DatabaseMigrations;

    private const STAFF_PHONE = '97338467744';

    private ScriptedChatBrain $brain;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
        Route::middleware('web')->group(base_path('Modules/WhatsApp/routes/web.php'));

        Artisan::call('pricing:seed');

        $this->owner = User::factory()->create(['name' => 'Qassim', 'is_admin' => true]);
        AssistantConfiguration::query()->create(['ai_api_key' => 'sk-test', 'ai_model' => 'claude-opus-5', 'enabled' => true]);
        AssistantStaff::query()->create(['phone' => self::STAFF_PHONE, 'user_id' => $this->owner->id, 'active' => true]);

        $this->brain = new ScriptedChatBrain();
        $this->app->instance(Brain::class, $this->brain);

        Storage::fake('local');
        Http::fake(); // nothing in this channel should ever reach the network
    }

    /* ── Who may open it ─────────────────────────────────────────────── */

    public function test_an_admin_can_open_the_chat(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AssistantChat::class)->assertOk();
    }

    /**
     * Enter sends on a desktop only; a phone keeps Enter as a new line, and
     * Shift+Enter is a new line everywhere.
     */
    public function test_enter_sends_on_a_desktop_but_not_on_a_phone(): void
    {
        $this->actingAs($this->owner);

        $html = Livewire::test(AssistantChat::class)->html();

        $this->assertStringContainsString('data-enter-sends-on-desktop', $html);
        $this->assertStringContainsString('x-on:keydown.enter=', $html);
        $this->assertStringContainsString("(pointer: fine) and (hover: hover)", $html);
        $this->assertStringContainsString('! $event.shiftKey', $html);
        $this->assertStringContainsString('requestSubmit()', $html);
    }

    public function test_a_mapped_staff_member_can_open_the_chat(): void
    {
        $clerk = User::factory()->create(['is_admin' => false]);
        AssistantStaff::query()->create(['phone' => '97300000001', 'user_id' => $clerk->id, 'active' => true]);

        $this->actingAs($clerk);

        Livewire::test(AssistantChat::class)->assertOk();
    }

    public function test_an_unrelated_account_is_forbidden(): void
    {
        $stranger = User::factory()->create(['is_admin' => false]);
        $this->actingAs($stranger);

        Livewire::test(AssistantChat::class)->assertForbidden();
    }

    public function test_a_paused_or_inactive_mapping_is_forbidden(): void
    {
        $clerk = User::factory()->create(['is_admin' => false]);
        AssistantStaff::query()->create(['phone' => '97300000002', 'user_id' => $clerk->id, 'active' => false]);
        $this->actingAs($clerk);

        Livewire::test(AssistantChat::class)->assertForbidden();
    }

    /* ── The same conversation ───────────────────────────────────────── */

    public function test_a_plain_message_gets_a_reply_with_no_meta_call_made(): void
    {
        $this->actingAs($this->owner);
        $this->brain->queue(new BrainReply('end_turn', 'Hi! Where to and when?', [], []));

        Livewire::test(AssistantChat::class)
            ->set('text', 'hi')
            ->call('send')
            ->assertSee('Hi! Where to and when?');

        Http::assertNothingSent();
        $history = Conversation::query()->where('wa_id', 'web:' . $this->owner->id)->firstOrFail()->history ?? [];
        $this->assertSame('Hi! Where to and when?', $history[1]['text'] ?? null);
    }

    public function test_a_confirmed_booking_actually_books_it(): void
    {
        $this->actingAs($this->owner);
        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'b1', 'name' => 'propose_booking', 'input' => $this->tripInput()]], [['type' => 'tool_use']]));

        $component = Livewire::test(AssistantChat::class)
            ->set('text', 'book sedan airport tomorrow 9am to Seef, Ahmed Ali 33112233')
            ->call('send')
            ->assertSee('Reply YES to book');

        $this->assertSame(0, LimoBooking::query()->count());

        $component->set('text', 'yes')->call('send')->assertSee('Booked ✅');

        $booking = LimoBooking::query()->firstOrFail();
        $this->assertSame(15.0, (float) $booking->fare);
        $this->assertSame('online', $booking->payment_method);
        $this->assertSame('Qassim', $booking->prepared_by);
        Http::assertNothingSent();
    }

    public function test_a_no_cancels_the_proposal(): void
    {
        $this->actingAs($this->owner);
        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'b1', 'name' => 'propose_booking', 'input' => $this->tripInput()]], [['type' => 'tool_use']]));

        $component = Livewire::test(AssistantChat::class)->set('text', 'book it')->call('send');
        $component->set('text', 'no')->call('send')->assertSee('Cancelled');

        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_disabled_config_shows_the_same_unavailable_wording_as_whatsapp(): void
    {
        AssistantConfiguration::current()->update(['enabled' => false]);
        $this->actingAs($this->owner);

        Livewire::test(AssistantChat::class)
            ->set('text', 'hi')
            ->call('send')
            ->assertSee("isn't available right now");
    }

    /* ── Documents ────────────────────────────────────────────────────── */

    public function test_a_confirmed_document_offers_a_real_downloadable_pdf(): void
    {
        $this->actingAs($this->owner);
        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'q1', 'name' => 'propose_quotation', 'input' => $this->tripInput()]], [['type' => 'tool_use']]));

        $component = Livewire::test(AssistantChat::class)->set('text', 'quotation please')->call('send');
        $component->set('text', 'yes')->call('send');

        $this->assertSame(1, LimoQuotation::query()->count());
        Http::assertNothingSent(); // no Meta media upload for this channel

        $downloadPath = $component->get('pendingDownloadPath');
        $this->assertIsString($downloadPath);
        $this->assertTrue(Storage::disk('local')->exists($downloadPath));
        // A real PDF, not an empty placeholder.
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($downloadPath));

        $conversation = Conversation::query()->where('wa_id', 'web:' . $this->owner->id)->firstOrFail();
        $response = $this->get('/app/settings/whatsapp-assistant/chat/download?' . http_build_query([
            'path' => $downloadPath,
            'filename' => 'quotation.pdf',
        ]));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
        $this->assertSame($conversation->id, Conversation::query()->where('wa_id', 'web:' . $this->owner->id)->firstOrFail()->id);
    }

    public function test_a_document_cannot_be_downloaded_from_another_users_conversation(): void
    {
        $this->actingAs($this->owner);
        $this->brain->queue(new BrainReply('tool_use', '', [['id' => 'q1', 'name' => 'propose_quotation', 'input' => $this->tripInput()]], [['type' => 'tool_use']]));

        $component = Livewire::test(AssistantChat::class)->set('text', 'quotation please')->call('send');
        $component->set('text', 'yes')->call('send');
        $path = (string) $component->get('pendingDownloadPath');

        $stranger = User::factory()->create(['is_admin' => true]);
        $this->actingAs($stranger);

        $this->get('/app/settings/whatsapp-assistant/chat/download?' . http_build_query(['path' => $path, 'filename' => 'x.pdf']))
            ->assertNotFound();
    }

    public function test_a_made_up_path_is_refused(): void
    {
        $this->actingAs($this->owner);

        $this->get('/app/settings/whatsapp-assistant/chat/download?' . http_build_query([
            'path' => 'whatsapp-assistant-chat/../../../.env',
            'filename' => 'x.pdf',
        ]))->assertNotFound();
    }

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
}

/** A brain that answers from a script — same double `WhatsAppStaffAssistantTest` uses. */
final class ScriptedChatBrain implements Brain
{
    /** @var list<BrainReply> */
    private array $script = [];

    public function queue(BrainReply $reply): void
    {
        $this->script[] = $reply;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     */
    public function respond(string $apiKey, string $model, string $system, array $messages, array $tools): BrainReply
    {
        return array_shift($this->script) ?? new BrainReply('end_turn', 'OK', [], []);
    }
}
