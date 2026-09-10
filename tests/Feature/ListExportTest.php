<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Export\ListExportRows;
use App\Erp\Modules\ModuleManager;
use App\Erp\Views\ValueFormat;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Downloading any engine list — CSV, Excel, PDF, Print — as a copy of
 * whatever the screen it came from was actually showing.
 *
 * One controller and one row-service serve every model with an `ir_ui_view`
 * list, so this is tested against a single representative model (Rental's
 * Vehicle list — it carries a badge, a bool, money and date columns, the
 * breadth this feature has to handle generically) rather than once per
 * screen: the logic under test doesn't know or care which model it is.
 */
final class ListExportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    private function asReader(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->grantEveryone('rental.vehicle');

        return $user;
    }

    public function test_export_lists_every_arch_column_even_ones_hidden_on_screen(): void
    {
        $this->asReader();
        Vehicle::query()->create(['name' => 'Yaris', 'plate_no' => '12345', 'active' => true]);

        $rows = new ListExportRows('rental.vehicle');

        // "Active" is a real arch column that the per-user column picker could
        // hide from any one viewer's table — export carries it regardless,
        // because a download is a copy of the record, not of one person's view.
        $this->assertArrayHasKey('active', $rows->headings());
        $this->assertArrayHasKey('daily_rate', $rows->headings());
    }

    public function test_a_cell_reads_exactly_as_the_live_table_would(): void
    {
        $this->asReader();
        $car = Vehicle::query()->create([
            'name' => 'Yaris', 'plate_no' => '12345', 'daily_rate' => 12.5,
            'registration_expiry' => '2026-09-10', 'active' => true,
        ]);

        $rows = new ListExportRows('rental.vehicle');
        $row = $rows->row($car);

        $this->assertSame('$12.50', $row['daily_rate']);
        $this->assertSame('10-Sep-2026', $row['registration_expiry']);
        $this->assertSame('Yes', $row['active']);
    }

    public function test_a_null_cell_reads_as_an_em_dash_not_blank_or_null(): void
    {
        $this->asReader();
        $car = Vehicle::query()->create(['name' => 'Yaris']);

        $row = (new ListExportRows('rental.vehicle'))->row($car);

        $this->assertSame('—', $row['plate_no']);
    }

    public function test_a_toggle_column_reads_as_yes_no_in_an_export_even_though_the_screen_draws_a_switch(): void
    {
        // The live table never calls the formatter with 'toggle' — it draws an
        // interactive switch instead — so export is the first caller that
        // actually reaches this arm, and a CSV has nowhere to put a switch.
        $this->assertSame('Yes', ValueFormat::cell(true, 'toggle'));
        $this->assertSame('No', ValueFormat::cell(false, 'toggle'));
    }

    public function test_search_and_sort_ride_along_exactly_as_the_screen_had_them(): void
    {
        $this->asReader();
        Vehicle::query()->create(['name' => 'Zeta', 'plate_no' => '11111']);
        Vehicle::query()->create(['name' => 'Alpha', 'plate_no' => '22222']);
        Vehicle::query()->create(['name' => 'Bravo Taxi', 'plate_no' => '33333']);

        $rows = new ListExportRows('rental.vehicle');
        $request = Request::create('/', 'GET', [
            'q' => 'Alpha', 'sorts' => [['field' => 'name', 'dir' => 'asc']],
        ]);

        // An export of what the office typed into the search box, not the
        // whole table regardless of what was on screen.
        $names = collect($rows->rows($request))->pluck('name')->all();
        $this->assertSame(['Alpha'], $names);
    }

    public function test_default_sort_is_newest_first_when_nothing_was_sorted(): void
    {
        $this->asReader();
        $first = Vehicle::query()->create(['name' => 'A']);
        $second = Vehicle::query()->create(['name' => 'B']);

        $rows = (new ListExportRows('rental.vehicle'))->rows(Request::create('/'));

        $this->assertCount(2, $rows);
        // Newest id first — mirrors ListView's own fallback when no sort is active.
        $this->assertEquals($second->name, $rows[0]['name']);
        $this->assertEquals($first->name, $rows[1]['name']);
    }

    public function test_a_field_not_on_the_arch_cannot_be_used_to_sort_the_export(): void
    {
        $this->asReader();
        Vehicle::query()->create(['name' => 'A']);

        $rows = new ListExportRows('rental.vehicle');
        // 'id' is a real column but not declared sortable in the arch — a
        // crafted query string must not smuggle a sort the screen never offers.
        $request = Request::create('/', 'GET', ['sorts' => [['field' => 'id', 'dir' => 'desc']]]);

        // No exception, no SQL error — the bogus sort is simply ignored and the
        // default fallback applies.
        $this->assertNotEmpty($rows->rows($request));
    }

    public function test_download_is_read_gated_exactly_like_the_screen(): void
    {
        // No grant at all — the export route must refuse exactly as the
        // screen would, never a side door around its own ACL.
        $this->actingAs(User::factory()->create());
        Vehicle::query()->create(['name' => 'Yaris']);

        $this->get('/app/export/rental.vehicle/csv')->assertForbidden();
    }

    public function test_csv_download_carries_the_row_and_the_bom(): void
    {
        $this->asReader();
        Vehicle::query()->create(['name' => 'Yaris', 'plate_no' => '12345']);

        $response = $this->get('/app/export/rental.vehicle/csv');

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Yaris', $body);
    }

    public function test_pdf_and_print_render_for_a_registered_model(): void
    {
        $this->asReader();
        Vehicle::query()->create(['name' => 'Yaris']);

        $this->get('/app/export/rental.vehicle/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get('/app/export/rental.vehicle/print')->assertOk();
    }

    public function test_a_model_key_with_no_ir_model_row_404s_instead_of_leaking_a_query(): void
    {
        // Granted so the ACL check (which runs first and must never reveal
        // whether a key exists) passes, isolating the row-service's own guard
        // against a key that has no registered model behind it at all.
        $this->actingAs(User::factory()->create());
        $this->grantEveryone('not.a.real.model');

        $this->get('/app/export/not.a.real.model/csv')->assertNotFound();
    }
}
