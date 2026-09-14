<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Support\LegacyQuotationImporter;
use Tests\TestCase;

/**
 * Bringing quotations over from the previous system's own quotations export:
 * a bare register (number / date / customer / requested person / added by)
 * with no pricing or trip data. The old quote number is preserved in
 * `reference` rather than used as the row id, because the export itself
 * reuses numbers (same customer/date, a different "Requested Person") —
 * the old system's own numbering glitch, not a genuine duplicate quote.
 */
final class LimoLegacyQuotationImportTest extends TestCase
{
    use DatabaseMigrations;

    private const HEADER = '"Sl No.","#","Date","Customer","Requested Person","Added By","Actions"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lgq').'.csv';
        file_put_contents($path, self::HEADER."\n".$body);

        return $path;
    }

    private function row(string $number, string $date, string $customer, string $requestedBy, string $addedBy): string
    {
        return '"1","'.$number.'","'.$date.'","'.$customer.'","'.$requestedBy.'","'.$addedBy.'","  "'."\n";
    }

    public function test_a_new_quotation_is_imported_and_matched_to_an_existing_customer(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Turbo Engineering', 'active' => true]);

        $result = app(LegacyQuotationImporter::class)->import($this->csv(
            $this->row('0555', '10-08-2026', 'Turbo Engineering', 'Turbo Engineering', 'hassan'),
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertStringStartsWith('NEW', $result['lines'][0]);
        $this->assertStringContainsString('matched by name', $result['lines'][0]);

        $quotation = LimoQuotation::query()->where('reference', 'QT/0555')->firstOrFail();
        $this->assertSame($customer->id, $quotation->customer_id);
        $this->assertSame('Turbo Engineering', $quotation->requested_by);
        $this->assertSame('hassan', $quotation->prepared_by);
        $this->assertSame('2026-08-10', $quotation->quote_date?->format('Y-m-d'));
        $this->assertSame(LimoQuotation::STATUS_SENT, $quotation->status);
        $this->assertNotNull($quotation->sent_at);
        $this->assertEqualsWithDelta(0.0, $quotation->fare, 0.001);
    }

    public function test_a_customer_not_on_file_is_created(): void
    {
        app(LegacyQuotationImporter::class)->import($this->csv(
            $this->row('0559', '04-09-2026', 'Mahad Ahmad', 'Mahad Ahmad', 'hassan'),
        ));

        $quotation = LimoQuotation::query()->where('reference', 'QT/0559')->firstOrFail();
        $this->assertSame('Mahad Ahmad', $quotation->customer?->name);
    }

    public function test_re_running_the_same_row_is_skipped_as_already_on_file(): void
    {
        $importer = app(LegacyQuotationImporter::class);
        $csv = $this->csv($this->row('0555', '10-08-2026', 'Turbo Engineering', 'Turbo Engineering', 'hassan'));

        $importer->import($csv);
        $again = $importer->import($csv);

        $this->assertSame(0, $again['imported']);
        $this->assertSame(1, $again['skipped']);
        $this->assertStringStartsWith('EXISTS', $again['lines'][0]);
        $this->assertSame(1, LimoQuotation::query()->count());
    }

    /**
     * The export's own numbering glitch: two rows share "#0501" (same
     * customer, same date) but name a different Requested Person — these are
     * two different quotes and both must import, not collide into one.
     */
    public function test_a_reused_quote_number_for_the_same_customer_and_date_imports_both_rows(): void
    {
        $result = app(LegacyQuotationImporter::class)->import($this->csv(
            $this->row('0501', '03-12-2025', 'Asry', 'Wanaan', 'ali')
            .$this->row('0501', '03-12-2025', 'Al Salam Bank', 'Al Salam Bank', 'qassim'),
        ));

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, LimoQuotation::query()->where('reference', 'QT/0501')->count());

        $asry = LimoQuotation::query()->where('reference', 'QT/0501')->where('requested_by', 'Wanaan')->firstOrFail();
        $this->assertSame('Asry', $asry->customer?->name);

        $salamBank = LimoQuotation::query()->where('reference', 'QT/0501')->where('requested_by', 'Al Salam Bank')->firstOrFail();
        $this->assertSame('Al Salam Bank', $salamBank->customer?->name);
    }

    /**
     * Same customer AND date reusing a number, differing only by Requested
     * Person (e.g. "#0139": World Seas Shipping, "Mirasol" vs "wanaan") —
     * still two distinct quotes.
     */
    public function test_a_reused_number_differing_only_by_requested_person_imports_both(): void
    {
        $result = app(LegacyQuotationImporter::class)->import($this->csv(
            $this->row('0139', '06-09-2023', 'World Seas Shipping Services W.L.L', 'Mirasol', 'via')
            .$this->row('0139', '06-09-2023', 'World Seas Shipping Services W.L.L', 'wanaan', 'mariam'),
        ));

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, LimoQuotation::query()->where('reference', 'QT/0139')->count());
        $this->assertSame(1, LimoCustomer::query()->where('name', 'World Seas Shipping Services W.L.L')->count());
    }

    public function test_rows_missing_number_or_customer_are_skipped(): void
    {
        $result = app(LegacyQuotationImporter::class)->import($this->csv(
            $this->row('', '10-08-2026', 'Someone', 'Someone', 'hassan')
            .$this->row('0600', '10-08-2026', '', 'Someone', 'hassan'),
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        foreach ($result['lines'] as $line) {
            $this->assertStringStartsWith('SKIP', $line);
        }
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $result = app(LegacyQuotationImporter::class)->import(
            $this->csv($this->row('0555', '10-08-2026', 'Turbo Engineering', 'Turbo Engineering', 'hassan')),
            pretend: true,
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, LimoQuotation::query()->count());
        $this->assertSame(0, LimoCustomer::query()->count());
    }
}
