<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;

/**
 * Adds the two expense accounts payroll + operating expenses post to:
 *   5020 Salaries & Wages
 *   5030 Operating Expenses
 *
 * Idempotent (firstOrNew by code) so it's safe on a chart that may already
 * have them from the seeder. Runs as a data migration — the deploy migrates
 * the Accounting module path, but does NOT re-run ChartOfAccountsSeeder, so
 * existing production gets these accounts here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        $parentId = Account::byCode('5000')?->id;

        $accounts = [
            ['5020', ['en' => 'Salaries & Wages', 'ar' => 'الرواتب والأجور']],
            ['5030', ['en' => 'Operating Expenses', 'ar' => 'المصروفات التشغيلية']],
        ];

        foreach ($accounts as [$code, $name]) {
            $account = Account::query()->firstOrNew(['code' => $code]);

            if ($account->exists) {
                continue;
            }

            $account->setTranslations('name', $name);
            $account->type = AccountType::Expense;
            $account->is_reconcilable = false;
            $account->active = true;
            $account->parent_id = $parentId;
            $account->save();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounts')) {
            Account::query()->whereIn('code', ['5020', '5030'])->delete();
        }
    }
};
