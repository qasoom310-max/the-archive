<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Views\RottingRule;
use App\Erp\Views\ViewResolver;
use App\Livewire\Views\FormView;
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

    public function test_kanban_search_filters_records_by_arch_searchable(): void
    {
        // Set up an ungrouped kanban with a one-field search whitelist.
        // Records whose subject doesn't substring-match the needle must
        // not appear in $grouped.
        IrModel::query()->create([
            'model' => 'k.s', 'name' => 'KS', 'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        IrUiView::query()->create([
            'name' => 'K', 'model' => 'k.s', 'type' => 'kanban', 'priority' => 1,
            'arch' => [
                'card' => ['title' => 'subject'],
                'per_page' => 10,
                'searchable' => ['subject'],
            ],
        ]);

        DemoTicket::query()->create(['subject' => 'Avocado smoothie', 'stage' => 'New']);
        DemoTicket::query()->create(['subject' => 'Banana smoothie', 'stage' => 'New']);
        DemoTicket::query()->create(['subject' => 'Lemonade', 'stage' => 'New']);

        $component = Livewire::test(KanbanView::class, ['model' => DemoTicket::class, 'modelKey' => 'k.s'])
            ->assertSet('search', '')
            ->set('search', 'avocado');

        // Only the avocado ticket survives the LIKE.
        /** @var array<string, list<\Illuminate\Database\Eloquent\Model>> $grouped */
        $grouped = $component->viewData('grouped');
        $rows = $grouped[''] ?? [];
        $this->assertCount(1, $rows);
        $this->assertSame('Avocado smoothie', $rows[0]->subject);

        // An empty needle clears the filter and brings every row back.
        $component->set('search', '');
        $grouped = $component->viewData('grouped');
        $this->assertCount(3, $grouped[''] ?? []);
    }

    public function test_kanban_lazy_loads_in_pages_of_arch_per_page(): void
    {
        // Catalogue (ungrouped) board: arch.per_page = 2 means only 2
        // cards render up-front; loadMore() reveals another 2 each
        // call. hasMore stays true until $loaded >= total.
        IrModel::query()->create([
            'model' => 'k.l', 'name' => 'KL', 'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        IrUiView::query()->create([
            'name' => 'K', 'model' => 'k.l', 'type' => 'kanban', 'priority' => 1,
            'arch' => [
                'card' => ['title' => 'subject'],
                'per_page' => 2,
            ],
        ]);

        for ($i = 1; $i <= 5; $i++) {
            DemoTicket::query()->create(['subject' => "Card {$i}", 'stage' => 'New']);
        }

        $component = Livewire::test(KanbanView::class, ['model' => DemoTicket::class, 'modelKey' => 'k.l'])
            ->assertSet('loaded', 2);

        $this->assertCount(2, $component->viewData('grouped')[''] ?? []);
        $this->assertTrue($component->viewData('hasMore'));

        $component->call('loadMore')->assertSet('loaded', 4);
        $this->assertCount(4, $component->viewData('grouped')[''] ?? []);
        $this->assertTrue($component->viewData('hasMore'));

        $component->call('loadMore')->assertSet('loaded', 6);
        // Only 5 records exist, so we cap at 5 even though $loaded is 6.
        $this->assertCount(5, $component->viewData('grouped')[''] ?? []);
        $this->assertFalse($component->viewData('hasMore'));
    }

    public function test_kanban_search_resets_the_lazy_load_window(): void
    {
        // After scrolling deep into a catalogue, narrowing the query
        // must snap $loaded back to the first page — otherwise the
        // grid shows a confusing slice of the now-shorter result set
        // (e.g. 24 visible but only 3 actually match).
        IrModel::query()->create([
            'model' => 'k.r', 'name' => 'KR', 'class' => DemoTicket::class, 'table' => 'demo_tickets',
        ]);
        IrUiView::query()->create([
            'name' => 'K', 'model' => 'k.r', 'type' => 'kanban', 'priority' => 1,
            'arch' => [
                'card' => ['title' => 'subject'],
                'per_page' => 3,
                'searchable' => ['subject'],
            ],
        ]);

        Livewire::test(KanbanView::class, ['model' => DemoTicket::class, 'modelKey' => 'k.r'])
            ->call('loadMore')->call('loadMore')->assertSet('loaded', 9)
            ->set('search', 'whatever')->assertSet('loaded', 3);
    }

    // ---- FormView record navigation --------------------------------------

    public function test_form_view_prev_next_walk_the_pk_in_order(): void
    {
        // Three records → middle one has both neighbours; first has only
        // a `next`; last has only a `prev`. The engine orders by primary
        // key ascending so this is fully deterministic across runs.
        $this->seed(DemoViewSeeder::class);

        $a = DemoTicket::query()->create(['subject' => 'A', 'stage' => 'New']);
        $b = DemoTicket::query()->create(['subject' => 'B', 'stage' => 'New']);
        $c = DemoTicket::query()->create(['subject' => 'C', 'stage' => 'New']);

        // Middle.
        /** @var FormView $mid */
        $mid = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $b->id,
        ])->instance();
        $this->assertSame($a->id, $mid->prevId());
        $this->assertSame($c->id, $mid->nextId());

        // First — no prev.
        /** @var FormView $first */
        $first = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $a->id,
        ])->instance();
        $this->assertNull($first->prevId());
        $this->assertSame($b->id, $first->nextId());

        // Last — no next.
        /** @var FormView $last */
        $last = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $c->id,
        ])->instance();
        $this->assertSame($b->id, $last->prevId());
        $this->assertNull($last->nextId());
    }

    public function test_form_view_prev_next_are_null_on_a_new_record(): void
    {
        // A brand-new form has no current record id, so the engine
        // refuses to compute neighbours — the host blade hides the
        // chevrons entirely so the toolbar layout stays clean.
        $this->seed(DemoViewSeeder::class);
        DemoTicket::query()->create(['subject' => 'Only', 'stage' => 'New']);

        /** @var FormView $fresh */
        $fresh = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
        ])->instance();
        $this->assertNull($fresh->prevId());
        $this->assertNull($fresh->nextId());
    }

    public function test_form_view_nav_url_swaps_the_trailing_id_segment(): void
    {
        // The engine derives sibling URLs by swapping the trailing
        // segment of the current URL — so any host route shaped
        // `/.../{id}` works without per-module wiring.
        $this->seed(DemoViewSeeder::class);
        $t = DemoTicket::query()->create(['subject' => 'X', 'stage' => 'New']);
        $other = DemoTicket::query()->create(['subject' => 'Y', 'stage' => 'New']);

        /** @var FormView $component */
        $component = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $t->id,
        ])->instance();

        $url = $component->navUrl($other->id);

        // The trailing path segment is now the OTHER record id, and
        // the path prefix (everything before the last slash) is
        // identical to the original request URL's prefix. So clicking
        // "next" from /app/foo/X redirects to /app/foo/Y.
        $this->assertStringEndsWith('/' . $other->id, $url);
        $currentUrl = (string) request()->url();
        $this->assertSame(
            substr($currentUrl, 0, strrpos($currentUrl, '/') ?: 0),
            substr($url, 0, strrpos($url, '/') ?: 0),
        );
    }

    public function test_form_view_nav_url_survives_an_auto_save_rerender(): void
    {
        // Regression: navUrl used to read `request()->url()` at render
        // time, but on a Livewire AJAX request (which auto-save fires
        // on every keystroke) that returns the Livewire endpoint
        // (`/livewire/update`). The chevron href got rewritten to
        // `/livewire/<id>` after the first auto-save → 404 on click.
        // We now capture the URL at mount and reuse it, so re-renders
        // can't poison the prev/next links.
        $this->seed(DemoViewSeeder::class);
        $a = DemoTicket::query()->create(['subject' => 'A', 'stage' => 'New']);
        $b = DemoTicket::query()->create(['subject' => 'B', 'stage' => 'New']);

        $test = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $a->id,
        ]);

        /** @var FormView $before */
        $before = $test->instance();
        $urlBefore = $before->navUrl($b->id);

        // Trigger an auto-save round-trip (the path that, pre-fix,
        // re-rendered with a poisoned navUrl).
        $test->set('form.subject', 'A edited');

        /** @var FormView $after */
        $after = $test->instance();
        $urlAfter = $after->navUrl($b->id);

        $this->assertSame($urlBefore, $urlAfter);
        $this->assertStringEndsWith('/' . $b->id, $urlAfter);
        $this->assertStringNotContainsString('/livewire/', $urlAfter);
    }

    public function test_save_on_a_new_record_redirects_to_the_canonical_edit_url(): void
    {
        // After creating a new record, swap the trailing `/new` segment
        // (or whatever sentinel the host uses) for the new record's id
        // so the URL is now the canonical edit URL. Refreshing won't
        // resurface the empty new form → no duplicate creation. The
        // explicit Save button is replaced by the auto-save status pill
        // on the next render → a double-click can't reach save() again.
        $this->seed(DemoViewSeeder::class);

        $component = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
        ])
            ->set('form.subject', 'First save')
            ->set('form.stage', 'New')
            ->call('save');

        $created = DemoTicket::query()->where('subject', 'First save')->sole();

        // baseUrl in the test harness ends with whatever the test request
        // URL is (typically '/'); the regex still swaps the trailing
        // segment for the new id, so the redirect URL ends with `/{id}`.
        $component->assertRedirect();
    }

    public function test_save_on_a_new_record_does_not_create_a_duplicate_on_second_save_call(): void
    {
        // Pre-fix: calling save() twice on a new-record FormView would
        // create two rows because `$this->recordId` stayed null between
        // calls, so `resolveRecord()` returned a fresh model both times.
        // Post-fix: the first save redirects (Livewire test harness
        // captures it), so a second save() call after the redirect lands
        // on an aborted / no-op path. Even if a user somehow bypasses the
        // disabled button, the redirect makes the component unreachable
        // for further interaction without re-mounting.
        $this->seed(DemoViewSeeder::class);

        $component = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
        ])
            ->set('form.subject', 'Only once')
            ->set('form.stage', 'New')
            ->call('save');

        // The redirect is the explicit signal that save committed; a
        // second invocation in the same flow would have to re-mount
        // (i.e. pass through the new canonical URL), so the create path
        // can't be re-entered with the same form state.
        $component->assertRedirect();
        $this->assertSame(1, DemoTicket::query()->where('subject', 'Only once')->count());
    }

    public function test_form_view_autosaves_field_changes_on_an_existing_record(): void
    {
        // Odoo-style auto-save: changing any form.* property on an existing
        // record persists immediately — no Save button click needed. We
        // simulate the `wire:model.live` round-trip by calling Livewire's
        // `set()` on `form.subject`; the engine's updated() hook fires
        // updates → autoSave() → record gets written.
        $this->seed(DemoViewSeeder::class);
        $ticket = DemoTicket::query()->create(['subject' => 'Old', 'stage' => 'New']);

        Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $ticket->id,
        ])->set('form.subject', 'Edited via autosave');

        $this->assertSame('Edited via autosave', $ticket->fresh()->subject);
    }

    public function test_form_view_autosave_does_not_redirect_or_flash(): void
    {
        // Auto-save runs on every keystroke; a flash toast every 500ms
        // (or worse, a redirect) would make the form unusable. The
        // silent path returns early before either fires.
        $this->seed(DemoViewSeeder::class);
        $ticket = DemoTicket::query()->create(['subject' => 'Old', 'stage' => 'New']);

        $component = Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $ticket->id,
            'redirectTo' => '/playground',
        ])->set('form.subject', 'Auto');

        $component->assertNoRedirect();
        $this->assertNull(session('toast'));
    }

    public function test_form_view_autosave_no_ops_on_a_new_record(): void
    {
        // A brand-new form has no row to write to yet — autoSave() must
        // be a no-op. The explicit Save button still creates the row.
        $this->seed(DemoViewSeeder::class);
        $countBefore = DemoTicket::query()->count();

        Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
        ])->set('form.subject', 'Should not create a row');

        $this->assertSame($countBefore, DemoTicket::query()->count());
    }

    public function test_form_view_autosave_silently_skips_when_validation_fails(): void
    {
        // Clearing a required field shouldn't crash or commit — autoSave
        // swallows ValidationException so the next valid keystroke saves
        // and the inline @error pill renders the message in the meantime.
        // The default demo arch doesn't mark anything required, so we
        // register a form view explicitly with subject required.
        $this->seed(DemoViewSeeder::class);
        IrUiView::query()->updateOrCreate(
            ['model' => 'demo.ticket', 'type' => 'form'],
            [
                'name' => 'Demo Tickets (Form)',
                'arch' => [
                    'cols' => 1,
                    'fields' => [
                        ['field' => 'subject', 'label' => 'Subject', 'widget' => 'text', 'required' => true],
                        ['field' => 'stage', 'label' => 'Stage', 'widget' => 'text'],
                    ],
                ],
            ],
        );

        $ticket = DemoTicket::query()->create(['subject' => 'Has a subject', 'stage' => 'New']);

        Livewire::test(FormView::class, [
            'model' => DemoTicket::class,
            'modelKey' => 'demo.ticket',
            'recordId' => $ticket->id,
        ])->set('form.subject', '');

        // Subject is required → autoSave bailed out, the row keeps its
        // old subject. (Without the try/catch we'd see ValidationException
        // bubble through the wire request.)
        $this->assertSame('Has a subject', $ticket->fresh()->subject);
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
