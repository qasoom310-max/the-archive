<?php

declare(strict_types=1);

namespace Modules\Rental\Console;

use Illuminate\Console\Command;
use Modules\Rental\Support\CarImporter;

/**
 * Backend fleet import: `php artisan rental:import-cars path/to/file.csv`.
 * Reads a cars CSV (Name, Reg.No, Year, Color, Type, Status) into the rental
 * vehicles store, skipping duplicate plates. Safe to re-run.
 */
final class ImportCarsCommand extends Command
{
    protected $signature = 'rental:import-cars {path : Path to the cars CSV}';

    protected $description = 'Import rental cars from a CSV (skips duplicate plates).';

    public function handle(CarImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $result = $importer->import($path);

        $this->info("Imported {$result['imported']} cars, skipped {$result['skipped']} duplicates.");

        return self::SUCCESS;
    }
}
