<?php

declare(strict_types=1);

namespace Modules\Rental\Console;

use Illuminate\Console\Command;
use Modules\Rental\Support\CustomerImporter;

/**
 * Backend customer import: `php artisan rental:import-customers path/to/file.csv`.
 * Reads a customer CSV (Name, Type, CPR / CR, Phone, E-mail) into the shared
 * rental customer store, skipping duplicates. Safe to re-run.
 */
final class ImportCustomersCommand extends Command
{
    protected $signature = 'rental:import-customers {path : Path to the customer CSV}';

    protected $description = 'Import rental customers from a CSV (skips duplicates by CPR/CR then phone).';

    public function handle(CustomerImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $result = $importer->import($path);

        $this->info("Imported {$result['imported']} customers, skipped {$result['skipped']} duplicates.");

        return self::SUCCESS;
    }
}
