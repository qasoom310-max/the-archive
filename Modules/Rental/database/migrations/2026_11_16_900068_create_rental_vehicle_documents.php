<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A car's papers, kept year by year.
 *
 * The car record holds ONE registration card, one insurance paper, one
 * invoice and one agreement. Uploading next year's replaces this year's, so
 * the history of a car's papers was lost every renewal. This table keeps
 * every paper with the year it belongs to, until someone deletes it on
 * purpose.
 *
 * The papers already on each car are copied in as rows for the current year,
 * pointing at the files where they already are (`disk` = public), so nothing
 * that exists today is left out of the new list. No file is moved or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_vehicle_documents')) {
            Schema::create('rental_vehicle_documents', function (Blueprint $table): void {
                $table->id();
                // A logical ref: the car refuses to be deleted while it has
                // history (GuardsDeletionWhenReferenced), papers included.
                $table->unsignedBigInteger('vehicle_id')->index();
                $table->unsignedSmallInteger('year')->index();
                $table->string('kind', 32);          // registration | insurance | invoice | agreement | other
                $table->string('title')->nullable(); // what it is, when "other" or worth saying
                $table->string('original_name');
                $table->string('disk', 16)->default('local');
                $table->string('path');
                $table->unsignedBigInteger('size')->default(0);
                $table->string('uploaded_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rental_vehicles')) {
            return;
        }

        $year = (int) Carbon::now()->year;
        $now = Carbon::now();

        foreach ([
            'registration_doc' => 'registration',
            'insurance_doc' => 'insurance',
            'purchase_invoice' => 'invoice',
            'agreement_copy' => 'agreement',
        ] as $column => $kind) {
            if (! Schema::hasColumn('rental_vehicles', $column)) {
                continue;
            }

            foreach (DB::table('rental_vehicles')->whereNotNull($column)->where($column, '!=', '')->get(['id', $column]) as $car) {
                $path = (string) $car->{$column};

                $exists = DB::table('rental_vehicle_documents')
                    ->where('vehicle_id', $car->id)->where('disk', 'public')->where('path', $path)->exists();
                if ($exists) {
                    continue;
                }

                DB::table('rental_vehicle_documents')->insert([
                    'vehicle_id' => $car->id,
                    'year' => $year,
                    'kind' => $kind,
                    'original_name' => basename($path),
                    'disk' => 'public',
                    'path' => $path,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_vehicle_documents');
    }
};
