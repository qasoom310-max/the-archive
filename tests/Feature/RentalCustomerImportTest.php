<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Support\CustomerImporter;
use Tests\TestCase;

/**
 * Importing a customer CSV (an export from a previous system) loads the shared
 * rental customer store, mapping individual/company and skipping duplicates.
 */
final class RentalCustomerImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cust') . '.csv';
        file_put_contents($path, "Name,Type,CPR / CR,Phone,E-mail\n" . $body);

        return $path;
    }

    public function test_it_imports_individuals_and_companies_with_the_right_id_field(): void
    {
        $path = $this->csv(
            "Qasim fuad salman,Individual,921000448,38467744,\n" .
            "Al Ahlia Contracting Company,Company,265,17737000,info@ahlia.bh\n"
        );

        $result = app(CustomerImporter::class)->import($path);

        $this->assertSame(2, $result['imported']);

        $person = RentalCustomer::query()->where('name', 'Qasim fuad salman')->sole();
        $this->assertSame('individual', $person->type);
        $this->assertSame('921000448', $person->cpr);
        $this->assertNull($person->cr_number);
        $this->assertSame('38467744', $person->phone);

        $company = RentalCustomer::query()->where('name', 'Al Ahlia Contracting Company')->sole();
        $this->assertSame('company', $company->type);
        $this->assertSame('265', $company->cr_number);   // CR, not CPR, for a company
        $this->assertNull($company->cpr);
        $this->assertSame('info@ahlia.bh', $company->email);
    }

    public function test_it_skips_duplicates_by_cpr_and_phone_within_a_file_and_against_existing(): void
    {
        RentalCustomer::query()->create(['name' => 'Existing', 'cpr' => '111', 'phone' => '500']);

        $path = $this->csv(
            "New One,Individual,222,38467744,\n" .            // new
            "Dup CPR,Individual,111,39000000,\n" .            // dup of existing by CPR
            "Same As New,Individual,222,40000000,\n" .        // dup of "New One" by CPR (within file)
            "Phone Dup,Individual,,500,\n"                    // no CPR; dup of existing by phone
        );

        $result = app(CustomerImporter::class)->import($path);

        $this->assertSame(1, $result['imported']);   // only "New One"
        $this->assertSame(3, $result['skipped']);
        $this->assertSame(2, RentalCustomer::query()->count()); // existing + the one new
    }

    public function test_it_reads_the_full_column_set_mapping_country_names_to_iso_codes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cust') . '.csv';
        file_put_contents(
            $path,
            "Customer Name,Customer Type,Country,Phone,Email,CPR / ID,Licence No.,Nationality,CR Number,Contact Person,Contact Person Phone,Address,Vehicle Type\n" .
            "Aaron Stewart,Individual,United Kingdom,+447399328404,a@b.uk,549819150,,,,,,US Navy,Mid range\n" .
            "AB Transportation,Company,USA / Canada,+15550100,ops@abt.us,,,,88221-1,Celima,+15550101,Dallas,\n"
        );

        $result = app(CustomerImporter::class)->import($path);
        $this->assertSame(2, $result['imported']);

        $person = RentalCustomer::query()->where('name', 'Aaron Stewart')->sole();
        $this->assertSame('GB', $person->country);
        $this->assertSame('549819150', $person->cpr);
        $this->assertSame('US Navy', $person->address);

        $company = RentalCustomer::query()->where('name', 'AB Transportation')->sole();
        $this->assertSame('company', $company->type);
        $this->assertSame('US', $company->country);
        $this->assertSame('88221-1', $company->cr_number);
        $this->assertSame('Celima', $company->contact_person);
        $this->assertSame('+15550101', $company->contact_phone);
        $this->assertSame('Dallas', $company->address);
    }

    public function test_a_matched_customer_has_blank_fields_enriched_but_filled_ones_kept(): void
    {
        $existing = RentalCustomer::query()->create([
            'name' => 'Qasim fuad salman', 'cpr' => '921000448', 'phone' => '38467744', 'email' => 'keep@me.bh',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'cust') . '.csv';
        file_put_contents(
            $path,
            "Customer Name,Customer Type,Country,Phone,Email,CPR / ID,Address\n" .
            "Qasim F Salman,Individual,Bahrain,+97338467744,new@mail.bh,921000448,Manama\n"
        );

        $result = app(CustomerImporter::class)->import($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, RentalCustomer::query()->count());

        $existing->refresh();
        $this->assertSame('BH', $existing->country);      // blank → filled
        $this->assertSame('Manama', $existing->address);  // blank → filled
        $this->assertSame('keep@me.bh', $existing->email); // filled → NEVER overwritten
        $this->assertSame('38467744', $existing->phone);   // filled → kept
    }

    public function test_a_jammed_double_phone_keeps_only_the_first_number(): void
    {
        $path = $this->csv("Jam Med,Individual,,+966599199992+44,\n");
        app(CustomerImporter::class)->import($path);

        $this->assertSame('+966599199992', RentalCustomer::query()->where('name', 'Jam Med')->sole()->phone);
    }

    public function test_a_local_number_matches_the_same_phone_stored_with_its_country_code(): void
    {
        // An export from another system carries local numbers where we hold the
        // international form. Same person — enrich them, never duplicate.
        $existing = RentalCustomer::query()->create(['name' => 'Abdelmalek Murad', 'phone' => '+97338381200']);

        $path = tempnam(sys_get_temp_dir(), 'cust') . '.csv';
        file_put_contents($path, "Name,CPR / CR,Phone,E-mail\nAbdelmalek Murad,,38381200,malek@example.com\n");

        $result = app(CustomerImporter::class)->import($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, RentalCustomer::query()->count());
        $this->assertSame('+97338381200', $existing->fresh()?->phone);       // ours kept
        $this->assertSame('malek@example.com', $existing->fresh()?->email);  // blank filled
    }

    public function test_a_short_number_is_never_matched_by_its_ending(): void
    {
        // Six digits is too little to be sure two people are one, so it creates
        // a separate customer rather than merging strangers.
        RentalCustomer::query()->create(['name' => 'Someone', 'phone' => '+973111222']);

        $path = tempnam(sys_get_temp_dir(), 'cust') . '.csv';
        file_put_contents($path, "Name,CPR / CR,Phone,E-mail\nAnother Person,,111222,\n");

        $this->assertSame(1, app(CustomerImporter::class)->import($path)['imported']);
        $this->assertSame(2, RentalCustomer::query()->count());
    }

    public function test_an_inactive_row_is_created_switched_off(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cust') . '.csv';
        file_put_contents($path, "Name,Phone,Status\nGone Away,39000111,Inactive\nStill Here,39000222,Active\n");

        app(CustomerImporter::class)->import($path);

        $this->assertFalse(RentalCustomer::query()->where('name', 'Gone Away')->sole()->active);
        $this->assertTrue(RentalCustomer::query()->where('name', 'Still Here')->sole()->active);
    }

    public function test_a_row_with_no_id_and_no_phone_dedupes_by_name(): void
    {
        RentalCustomer::query()->create(['name' => 'Aaysha', 'email' => 'alk@hotmail.com']);

        $path = $this->csv("Aaysha,Individual,,,alk@hotmail.com\n");
        $result = app(CustomerImporter::class)->import($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, RentalCustomer::query()->count());
    }

    public function test_the_command_refuses_an_unknown_workspace_id(): void
    {
        // Module commands register on the boot AFTER install, so add it by hand.
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        \assert($kernel instanceof \Illuminate\Foundation\Console\Kernel);
        $kernel->registerCommand($this->app->make(\Modules\Rental\Console\ImportCustomersCommand::class));

        $path = $this->csv("X,Individual,9,9,\n");

        $this->artisan('rental:import-customers', ['path' => $path, '--workspace' => '99'])
            ->expectsOutputToContain('Workspace 99 not found.')
            ->assertFailed();

        $this->assertSame(0, RentalCustomer::query()->count());
    }

    public function test_the_endpoint_redirects_back_to_whichever_customer_list_it_came_from(): void
    {
        // One shared customer store, one importer, reached from either app's
        // Customers page — the redirect must return to whichever asked.
        $file = new \Illuminate\Http\UploadedFile($this->csv("X,Individual,9,9,\n"), 'c.csv', 'text/csv', null, true);
        $controller = new \Modules\Rental\Http\Controllers\RentalCustomerImportController();

        $fromLimo = \Illuminate\Http\Request::create('/app/rental/customer/import', 'POST', ['redirect' => '/app/limousine/customer'], [], ['file' => $file]);
        $this->assertSame(url('/app/limousine/customer'), $controller($fromLimo, app(CustomerImporter::class))->getTargetUrl());
    }

    public function test_an_unrecognised_redirect_target_falls_back_to_the_rental_list(): void
    {
        // Defence in depth against an open redirect via a crafted form field.
        $file = new \Illuminate\Http\UploadedFile($this->csv("X,Individual,9,9,\n"), 'c.csv', 'text/csv', null, true);
        $controller = new \Modules\Rental\Http\Controllers\RentalCustomerImportController();

        $request = \Illuminate\Http\Request::create('/app/rental/customer/import', 'POST', ['redirect' => 'https://evil.example/'], [], ['file' => $file]);
        $this->assertSame(url('/app/rental/customer'), $controller($request, app(CustomerImporter::class))->getTargetUrl());
    }

    public function test_the_import_route_is_manager_gated(): void
    {
        // Direct controller invocation (module HTTP routes aren't registered in tests).
        $this->actingAs(User::factory()->create()); // plain staff
        $controller = new \Modules\Rental\Http\Controllers\RentalCustomerImportController();

        $file = new \Illuminate\Http\UploadedFile($this->csv("X,Individual,9,9,\n"), 'c.csv', 'text/csv', null, true);
        $request = \Illuminate\Http\Request::create('/app/rental/customer/import', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(CustomerImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalCustomer::query()->count());
    }
}
