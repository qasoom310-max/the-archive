<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Views\RottingRule;
use App\Erp\Views\ViewResolver;
use App\Livewire\Views\KanbanView;
use App\Livewire\Views\ListView;
use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrUiView;
use App\Models\Mail\MessageType;
use App\Models\User;
use Database\Seeders\DemoViewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

final class ViewEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    // ---- ViewResolver -----------------------------------------------------

    public function test_resolver_generates_default_list_arch_from_fields(): void
    {
        $model = IrModel::query()->create([
            'model' => 'x.y', 'name' => 'XY', 'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        $model->fields()->createMany([
            ['name' => 'title', 'label' => 'Title', 'ttype' => 'char', 'sequence' => 1],
            ['name' => 'qty', 'label' => 'Qty', 'ttype' => 'integer', 'sequence' => 2],
            ['name' => 'blob', 'label' => 'Blob', 'ttype' => 'binary', 'sequence' => 3],
        ]);

        $arch = app(ViewResolver::class)->arch('x.y', 'list');

        $this->assertCount(2, $arch->columns); // binary excluded
        $qty = $arch->columns[1];
        $this->assertSame('qty', $qty->field);
        $this->assertTrue($qty->sum);
        $this->assertSame('right', $qty->align);
        $this->assertSame('number', $qty->format);
    }

    public function test_resolver_detects_kanban_group_by(): void
    {
        $model = IrModel::query()->create([
            'model' => 'x.z', 'name' => 'XZ', 'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        $model->fields()->createMany([
            ['name' => 'title', 'label' => 'Title', 'ttype' => 'char', 'sequence' => 1],
            ['name' => 'state', 'label' => 'State', 'ttype' => 'selection', 'sequence' => 2],
        ]);

        $arch = app(ViewResolver::class)->arch('x.z', 'kanban');

        $this->assertSame('state', $arch->groupBy);
        $this->assertNotNull($arch->card);
        $this->assertSame('title', $arch->card->title);
    }

    public function test_stored_view_takes_precedence_over_default(): void
    {
        IrModel::query()->create([
            'model' => 'x.q', 'name' => 'XQ', 'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        IrUiView::query()->create([
            'name' => 'Custom', 'model' => 'x.q', 'type' => 'list', 'priority' => 1,
            'arch' => ['columns' => [['field' => 'only', 'label' => 'Only']]],
        ]);

        $arch = app(ViewResolver::class)->arch('x.q', 'list');

        $this->assertCount(1, $arch->columns);
        $this->assertSame('only', $arch->columns[0]->field);
    }

    // ---- ListView ---------------------------------------------------------

    private function seedTickets(): void
    {
        $this->seed(DemoViewSeeder::class);

        DemoTicket::query()->create(['subject' => 'Alpha', 'stage' => 'New', 'amount' => 100]);
        DemoTicket::query()->create(['subject' => 'Bravo', 'stage' => 'Done', 'amount' => 250]);
        DemoTicket::query()->create(['subject' => 'Charlie', 'stage' => 'New', 'amount' => 50]);
    }

    public function test_list_view_default_sort_and_sort_cycle(): void
    {
        $this->seedTickets();

        Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->assertSet('sorts', [['field' => 'subject', 'dir' => 'asc']])
            ->call('sortBy', 'amount')->assertSet('sorts', [['field' => 'amount', 'dir' => 'asc']])
            ->call('sortBy', 'amount')->assertSet('sorts', [['field' => 'amount', 'dir' => 'desc']])
            ->call('sortBy', 'amount')->assertSet('sorts', []);
    }

    public function test_list_view_rejects_unknown_sort_field(): void
    {
        $this->seedTickets();

        Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->call('sortBy', 'evil_column; DROP TABLE')
            ->assertSet('sorts', [['field' => 'subject', 'dir' => 'asc']]);
    }

    public function test_list_view_aggregate_total(): void
    {
        $this->seedTickets();

        Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->assertSee(number_format(400, 2)); // 100 + 250 + 50
    }

    public function test_list_view_per_page_dropdown_defaults_to_arch_and_validates_selection(): void
    {
        // Mount uses the arch default (demo.ticket arch declares 8). The
        // dropdown surfaces 20/50/100 PLUS the arch default; a URL-
        // tampered value snaps back to the arch default rather than
        // blowing up the query.
        $this->seedTickets();

        $component = Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket']);
        $this->assertSame(8, $component->get('perPage'));

        // 50 is a canonical option — accepted.
        $component->set('perPage', 50)->assertSet('perPage', 50);
        // The arch default itself stays valid even though it's not in the canonical set.
        $component->set('perPage', 8)->assertSet('perPage', 8);
        // Anything else falls back to the arch default.
        $component->set('perPage', 999)->assertSet('perPage', 8);
    }

    public function test_list_view_pagination_always_shows_current_page_with_window_buffer(): void
    {
        // Sliding-window pagination: first 2, current ± 1, and last are
        // always rendered; "…" only ever appears between non-consecutive
        // entries. The current page is NEVER compressed into an ellipsis
        // (the bug the user hit on page 3 of 5 with the old layout).
        //
        // Seed 200 rows / 20 per page = 10 pages so the windows don't
        // collapse and we can assert ellipsis behaviour properly.
        $this->seed(DemoViewSeeder::class);
        for ($i = 1; $i <= 200; $i++) {
            DemoTicket::query()->create(['subject' => "T{$i}", 'stage' => 'New', 'amount' => $i]);
        }

        $component = Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->set('perPage', 20);

        // --- Page 1 (initial) — current is rendered as an aria-current span,
        // not a button. 1, 2 (start), and 10 (last) are always present.
        $html = $component->html();
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('gotoPage(2)', $html);
        $this->assertStringContainsString('gotoPage(10)', $html);
        $this->assertStringNotContainsString('Showing', $html); // no verbose default

        // --- Page 3 — the original bug: current must be rendered, NOT
        // hidden behind an ellipsis. Window: 1 2 [3] 4 … 10.
        $component->call('gotoPage', 3);
        $html = $component->html();
        $this->assertStringContainsString('gotoPage(4)', $html); // current+1 visible
        $this->assertStringContainsString('gotoPage(10)', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        // No gotoPage(3) — current is a span, not a clickable button.
        $this->assertStringNotContainsString('gotoPage(3)', $html);

        // --- Page 5 (middle) — both sides have ellipses: 1 2 … 4 [5] 6 … 10.
        $component->call('gotoPage', 5);
        $html = $component->html();
        $this->assertSame(2, substr_count($html, '…'));
        $this->assertStringContainsString('gotoPage(4)', $html); // window left
        $this->assertStringContainsString('gotoPage(6)', $html); // window right
        $this->assertStringContainsString('gotoPage(10)', $html);

        // --- Last page — current=10 anchored at the end, no trailing button.
        $component->call('gotoPage', 10);
        $html = $component->html();
        $this->assertStringContainsString('gotoPage(9)', $html); // current-1 visible
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_list_view_bulk_delete_and_select_page(): void
    {
        $this->seedTickets();
        $ids = DemoTicket::query()->pluck('id')->all();

        $component = Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->set('selectPage', true);

        $this->assertCount(3, $component->get('selected'));

        $component->set('selected', [$ids[0], $ids[1]])->call('bulkDelete');

        $this->assertSame(1, DemoTicket::query()->count());
    }

    // ---- KanbanView -------------------------------------------------------

    public function test_kanban_groups_and_move_transitions_state_with_chatter_log(): void
    {
        $this->seed(DemoViewSeeder::class);
        $ticket = DemoTicket::query()->create(['subject' => 'Lead', 'stage' => 'New', 'amount' => 0]);

        Livewire::test(KanbanView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->assertSee('In Progress')
            ->assertSee('Blocked')
            ->call('moveCard', $ticket->id, 'Done')
            ->assertDispatched('card-moved');

        $ticket->refresh();
        $this->assertSame('Done', $ticket->stage);
        $this->assertTrue(
            $ticket->messages()
                ->where('type', MessageType::Log)
                ->where('body', 'Stage: New → Done')
                ->exists(),
        );
    }

    public function test_rotting_rule_flags_stale_records(): void
    {
        $fresh = DemoTicket::query()->create(['subject' => 'Fresh', 'stage' => 'New']);
        $stale = DemoTicket::query()->create(['subject' => 'Stale', 'stage' => 'New']);
        DemoTicket::query()->whereKey($stale->id)->update(['updated_at' => Carbon::now()->subDays(21)]);

        $rule = new RottingRule('updated_at', 7);

        $this->assertFalse($rule->isRotting($fresh));
        $this->assertTrue($rule->isRotting($stale->fresh()));
        $this->assertGreaterThanOrEqual(20, $rule->staleDays($stale->fresh()));
    }

    // ---- Page -------------------------------------------------------------

    public function test_playground_route_renders(): void
    {
        $this->seed(DemoViewSeeder::class);
        DemoTicket::query()->create(['subject' => 'X', 'stage' => 'New']);

        $this->get('/playground')->assertOk()->assertSee('Dynamic View Engine');
    }
}
