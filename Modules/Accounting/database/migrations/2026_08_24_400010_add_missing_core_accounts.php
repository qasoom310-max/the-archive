<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;

/**
 * Add the core accounts the automatic postings need, if a database is missing
 * them.
 *
 * `ChartOfAccountsSeeder` is a MANUAL step that no deploy runs, so a database
 * set up before an account was added to it never got that account. The
 * delivery-cost and settlement postings look up "Money in Transit" (1150) and
 * "Operating Expenses" (5030) and quietly do nothing when either is absent —
 * so the books drifted with no warning.
 *
 * Only INSERTS what is missing: an account that already exists is left exactly
 * as it is, renames and all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        $rows = [
            ['code' => '1000', 'en' => 'Assets', 'ar' => 'الأصول', 'type' => AccountType::Asset, 'parent' => null, 'reconcilable' => false],
            ['code' => '5000', 'en' => 'Expenses', 'ar' => 'المصروفات', 'type' => AccountType::Expense, 'parent' => null, 'reconcilable' => false],
            ['code' => '1150', 'en' => 'Money in Transit (delivery)', 'ar' => 'أموال قيد التحصيل (التوصيل)', 'type' => AccountType::Asset, 'parent' => '1000', 'reconcilable' => true],
            ['code' => '5030', 'en' => 'Operating Expenses', 'ar' => 'المصروفات التشغيلية', 'type' => AccountType::Expense, 'parent' => '5000', 'reconcilable' => false],
        ];

        foreach ($rows as $row) {
            if (Account::query()->where('code', $row['code'])->exists()) {
                continue;
            }

            $account = new Account();
            $account->code = $row['code'];
            $account->setTranslations('name', ['en' => $row['en'], 'ar' => $row['ar']]);
            $account->type = $row['type'];
            $account->is_reconcilable = $row['reconcilable'];
            $account->active = true;

            if ($row['parent'] !== null) {
                $parentId = Account::query()->where('code', $row['parent'])->value('id');
                $account->parent_id = $parentId === null ? null : (int) $parentId;
            }

            $account->save();
        }
    }

    public function down(): void
    {
        // Deliberately not removed: by the time this is rolled back the
        // accounts may carry posted entries.
    }
};
