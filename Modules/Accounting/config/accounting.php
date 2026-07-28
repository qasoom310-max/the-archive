<?php

declare(strict_types=1);

/*
 * Account-code mapping used by the automated posting listeners.
 *
 * Codes (NOT ids) — codes are stable across re-seeds, easy to read in
 * config, and survive a `migrate:fresh --seed`. The COA seeder creates
 * every account named here with these exact codes; override per-project
 * by publishing this file to `config/accounting.php` and changing values.
 *
 * `env()` is deliberately NOT used here — the file sits outside the
 * project `config/` directory (PHPStan rule larastan.noEnvCallsOutsideOfConfig)
 * and Laravel only reads env() reliably from cached config under `config/`.
 */
return [
    'accounts' => [
        // Asset — cash sales debit here (POS auto-posting default).
        'cash' => '1010',

        // Asset — bank deposits / card settlement (reserved for future
        // POS payment-method-aware routing).
        'bank' => '1020',

        // Asset — outstanding customer invoices.
        'accounts_receivable' => '1100',

        // Asset — delivery money collected for us but not yet in our account:
        // cash the delivery company is holding, or a bank transfer still in
        // flight. Cleared when the payout is received (see PosSettlement).
        'money_in_transit' => '1150',

        // Asset — inventory on hand. Debited when a purchase lands.
        'inventory' => '1200',

        // Liability — owed to vendors. Credited on a confirmed purchase invoice.
        'accounts_payable' => '2010',

        // Liability — sales tax collected on behalf of the tax authority.
        // Optional: if blank, POS auto-posting credits the full gross to
        // `sales_income` instead of splitting tax out.
        'sales_tax_payable' => '',

        // Income — recognised on a paid POS sale.
        'sales_income' => '4010',

        // Expense — generic purchase / cost-of-goods bucket used when a
        // purchase isn't tied to an inventory item.
        'purchase_expense' => '5010',

        // Expense — payroll. Debited when a salary slip is paid.
        'salaries' => '5020',

        // Expense — operating costs (rent, EWA, SIO, LMRA…). Debited when a
        // recurring expense is paid.
        'operating_expense' => '5030',
    ],

    // Default journal-number prefix used by SequenceGenerator. Miscellaneous
    // operations land under "MISC"; sales/purchases get their own prefix.
    'sequences' => [
        'misc' => 'MISC',
        'sales' => 'SALE',
        'purchase' => 'PURC',
        'salary' => 'SAL',
        'expense' => 'EXP',
    ],
];
