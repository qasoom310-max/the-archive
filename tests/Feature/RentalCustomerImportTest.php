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
