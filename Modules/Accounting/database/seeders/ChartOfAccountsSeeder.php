<?php

declare(strict_types=1);

namespace Modules\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Models\Account;

/**
 * Minimal opinionated Chart of Accounts — enough to make POS auto-posting
 * work out of the box, and enough surface area to demo all three
 * financial statements meaningfully.
 *
 * Codes line up with the defaults in `config/accounting.php`:
 *   1010 Cash · 1020 Bank · 1100 AR · 1200 Inventory
 *   2010 AP
 *   3010 Owner's Equity
 *   4010 Sales Income
 *   5010 Purchases / COGS
 *
 * Idempotent — each row is upserted by `code`, so re-running the seeder
 * on top of edits to `name` / `is_reconcilable` re-asserts the canonical
 * values without duplicating.
 */
final class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            // Assets (1xxx)
            ['code' => '1000', 'name' => ['en' => 'Assets', 'ar' => 'الأصول'], 'type' => AccountType::Asset, 'parent' => null, 'reconcilable' => false],
            ['code' => '1010', 'name' => ['en' => 'Cash on Hand', 'ar' => 'النقد في الصندوق'], 'type' => AccountType::Asset, 'parent' => '1000', 'reconcilable' => true],
            ['code' => '1020', 'name' => ['en' => 'Bank', 'ar' => 'البنك'], 'type' => AccountType::Asset, 'parent' => '1000', 'reconcilable' => true],
            ['code' => '1100', 'name' => ['en' => 'Accounts Receivable', 'ar' => 'الذمم المدينة'], 'type' => AccountType::Asset, 'parent' => '1000', 'reconcilable' => true],
            ['code' => '1200', 'name' => ['en' => 'Inventory', 'ar' => 'المخزون'], 'type' => AccountType::Asset, 'parent' => '1000', 'reconcilable' => false],

            // Liabilities (2xxx)
            ['code' => '2000', 'name' => ['en' => 'Liabilities', 'ar' => 'الالتزامات'], 'type' => AccountType::Liability, 'parent' => null, 'reconcilable' => false],
            ['code' => '2010', 'name' => ['en' => 'Accounts Payable', 'ar' => 'الذمم الدائنة'], 'type' => AccountType::Liability, 'parent' => '2000', 'reconcilable' => true],

            // Equity (3xxx)
            ['code' => '3000', 'name' => ['en' => 'Equity', 'ar' => 'حقوق الملكية'], 'type' => AccountType::Equity, 'parent' => null, 'reconcilable' => false],
            ['code' => '3010', 'name' => ['en' => "Owner's Capital", 'ar' => 'رأس المال'], 'type' => AccountType::Equity, 'parent' => '3000', 'reconcilable' => false],

            // Income (4xxx)
            ['code' => '4000', 'name' => ['en' => 'Income', 'ar' => 'الإيرادات'], 'type' => AccountType::Income, 'parent' => null, 'reconcilable' => false],
            ['code' => '4010', 'name' => ['en' => 'Product Sales Income', 'ar' => 'إيرادات بيع المنتجات'], 'type' => AccountType::Income, 'parent' => '4000', 'reconcilable' => false],

            // Expense (5xxx)
            ['code' => '5000', 'name' => ['en' => 'Expenses', 'ar' => 'المصروفات'], 'type' => AccountType::Expense, 'parent' => null, 'reconcilable' => false],
            ['code' => '5010', 'name' => ['en' => 'Purchases / COGS', 'ar' => 'المشتريات / تكلفة البضاعة'], 'type' => AccountType::Expense, 'parent' => '5000', 'reconcilable' => false],
        ];

        // First pass: insert/update without parent links so a child can't
        // race ahead of its parent. Cache id-by-code as we go.
        $idByCode = [];
        foreach ($groups as $row) {
            /** @var Account $account */
            $account = Account::query()->firstOrNew(['code' => $row['code']]);
            $account->setTranslations('name', $row['name']);
            $account->type = $row['type'];
            $account->is_reconcilable = $row['reconcilable'];
            $account->active = true;
            $account->save();

            $idByCode[$row['code']] = (int) $account->id;
        }

        // Second pass: wire up parent_id now that every node exists.
        foreach ($groups as $row) {
            $parentCode = $row['parent'];
            if ($parentCode === null) {
                continue;
            }

            Account::query()
                ->where('code', $row['code'])
                ->update(['parent_id' => $idByCode[$parentCode]]);
        }
    }
}
