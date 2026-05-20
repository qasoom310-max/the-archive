<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Demo\DemoTicket;
use App\Models\Mail\MailActivityType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a Chatter-rich primary record plus a small board of demo tickets
 * (varied stages / amounts, one deliberately stale) so the List, Kanban
 * and Chatter views all look alive.
 */
final class DemoTicketSeeder extends Seeder
{
    public function run(): void
    {
        if (DemoTicket::query()->exists()) {
            return;
        }

        $ticket = DemoTicket::query()->create([
            'subject' => 'Onboarding: Acme Corp',
            'stage' => 'In Progress',
            'amount' => 12500,
        ]);

        $ticket->logChange('Record created.');
        $ticket->postNote('Customer requested a tailored ERP rollout plan.', 'Administrator');
        $ticket->postMessage('Thanks for reaching out — sending the proposal shortly.', author: 'Administrator');

        $todo = MailActivityType::query()->where('name', 'To Do')->first()
            ?? MailActivityType::query()->firstOrFail();
        $call = MailActivityType::query()->where('name', 'Call')->first() ?? $todo;

        $ticket->scheduleActivity($todo, 'Send signed proposal', Carbon::yesterday(), assignee: 'Administrator');
        $ticket->scheduleActivity($call, 'Kick-off call', Carbon::today(), assignee: 'Administrator');
        $ticket->scheduleActivity($todo, 'Provision tenant', Carbon::today()->addWeek(), assignee: 'Administrator');

        $done = $ticket->scheduleActivity($todo, 'Qualify lead', Carbon::today()->subWeek());
        $done->markDone('Administrator');

        $board = [
            ['Migrate legacy invoices', 'New', 4200.0],
            ['Configure tax rules', 'New', 0.0],
            ['Import product catalog', 'In Progress', 8800.0],
            ['Set up warehouse zones', 'In Progress', 15300.0],
            ['SSO integration', 'Blocked', 6000.0],
            ['Custom report: AR aging', 'Blocked', 2500.0],
            ['Train finance team', 'Done', 3100.0],
            ['Go-live checklist', 'Done', 0.0],
            ['Data backup policy', 'New', 1800.0],
        ];

        foreach ($board as [$subject, $stage, $amount]) {
            DemoTicket::query()->create([
                'subject' => $subject,
                'stage' => $stage,
                'amount' => $amount,
            ]);
        }

        // Make one card deliberately stale to exercise the rotting cue.
        $stale = DemoTicket::query()->where('subject', 'Custom report: AR aging')->first();

        if ($stale !== null) {
            DemoTicket::query()
                ->whereKey($stale->getKey())
                ->update(['updated_at' => Carbon::now()->subDays(21)]);
        }
    }
}
