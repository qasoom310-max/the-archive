<?php

declare(strict_types=1);

use App\Models\Ir\IrModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder the topbar app bar to: Contacts · Inventory · Point of Sale ·
 * Purchases · Accounting · Project. The bar sorts installed application
 * modules by `ir_module.sequence`, which is set from each manifest only at
 * install time — so an already-installed production needs its sequences
 * updated here (the manifests are also changed for fresh installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ir_module')) {
            return;
        }

        $order = [
            'contacts' => 10,
            'inventory' => 20,
            'pos' => 30,
            'purchases' => 40,
            'accounting' => 50,
            'project' => 60,
        ];

        foreach ($order as $name => $sequence) {
            IrModule::query()->where('name', $name)->update(['sequence' => $sequence]);
        }
    }

    public function down(): void
    {
        // One-way data fix — no meaningful rollback.
    }
};
