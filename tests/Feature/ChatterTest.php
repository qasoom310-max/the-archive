<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Chatter\ActivityBucket;
use App\Livewire\Chatter;
use App\Models\Demo\DemoTicket;
use App\Models\Mail\MailActivity;
use App\Models\Mail\MailActivityType;
use App\Models\Mail\MessageType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

final class ChatterTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(): DemoTicket
    {
        return DemoTicket::query()->create(['subject' => 'T1', 'stage' => 'New']);
    }

    private function activityType(): MailActivityType
    {
        return MailActivityType::query()->create([
            'name' => 'To Do',
            'icon' => 'clipboard-document-check',
        ]);
    }

    public function test_log_note_and_message_create_typed_entries(): void
    {
        $ticket = $this->ticket();

        Livewire::test(Chatter::class, ['record' => $ticket])
            ->set('mode', 'note')->set('body', 'Internal note')->call('postEntry')
            ->set('mode', 'message')->set('body', 'Public message')->call('postEntry');

        $this->assertSame(MessageType::Note, $ticket->messages()->where('body', 'Internal note')->sole()->type);
        $this->assertSame(MessageType::Comment, $ticket->messages()->where('body', 'Public message')->sole()->type);
    }

    public function test_empty_entry_is_rejected(): void
    {
        Livewire::test(Chatter::class, ['record' => $this->ticket()])
            ->set('body', '')
            ->call('postEntry')
            ->assertHasErrors('body');
    }

    public function test_schedule_activity_and_complete_moves_to_history(): void
    {
        $ticket = $this->ticket();
        $type = $this->activityType();

        $component = Livewire::test(Chatter::class, ['record' => $ticket])
            ->set('activityTypeId', $type->id)
            ->set('activitySummary', 'Call the customer')
            ->set('activityDue', Carbon::today()->toDateString())
            ->call('scheduleActivity');

        $activity = $ticket->activities()->sole();
        $this->assertFalse((bool) $activity->done);
        $this->assertSame(ActivityBucket::Today, $activity->bucket());

        $component->call('completeActivity', $activity->id);

        $activity->refresh();
        $this->assertTrue((bool) $activity->done);
        $this->assertSame(ActivityBucket::Done, $activity->bucket());
        $this->assertTrue(
            $ticket->messages()->where('type', MessageType::Log)
                ->where('body', 'like', '%Call the customer%')->exists(),
        );
    }

    public function test_activity_bucket_boundaries(): void
    {
        $ticket = $this->ticket();
        $type = $this->activityType();

        $make = fn (Carbon $due): MailActivity => $ticket->scheduleActivity($type, 's', $due);

        $this->assertSame(ActivityBucket::Overdue, $make(Carbon::yesterday())->bucket());
        $this->assertSame(ActivityBucket::Today, $make(Carbon::today())->bucket());
        $this->assertSame(ActivityBucket::Tomorrow, $make(Carbon::tomorrow())->bucket());
        $this->assertSame(ActivityBucket::Planned, $make(Carbon::today()->addDays(10))->bucket());
    }
}
