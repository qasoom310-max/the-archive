<?php

declare(strict_types=1);

namespace App\Erp\Business;

/**
 * A toggleable capability of the ERP. Each {@see BusinessType} enables a
 * subset of these; the active database's type therefore decides which apps,
 * menus and on-form widgets a user sees. Adding a feature is one case here
 * plus a mapping in {@see Features} (module and/or model).
 */
enum Feature: string
{
    case Pos = 'pos';
    case Recipes = 'recipes';
    case Inventory = 'inventory';
    case Purchases = 'purchases';
    case Hr = 'hr';
    case Accounting = 'accounting';
    case Bookings = 'bookings';
    case Limousine = 'limousine';
    case Projects = 'projects';
    // Floor plan + tables (table-service ordering). Separate from the kitchen &
    // shisha display screens below, so a shop can run one without the other.
    case Restaurant = 'restaurant';
    // The kitchen & shisha display screens (KDS) and the buttons that open them.
    case Kitchen = 'kitchen';

    // POS sub-features (toggled from POS → Settings).
    case Condiments = 'condiments';
    case CustomerDiscounts = 'customer_discounts';
    case Damage = 'damage';
    case BarcodeScanning = 'barcode_scanning';
    case DailyReportCards = 'daily_report_cards';
    // Split the bill: move items off an order onto a new one (a second table /
    // separate payer). Off hides the split action everywhere and refuses it.
    case SplitOrder = 'split_order';
    // Manufacturing: mix raw materials into finished stock held in a store, then
    // move it to the shop for sale. Opt-in — see Features::DEFAULT_OFF.
    case Production = 'production';
    // Postpaid dine-in: fire items to the kitchen on add and pay at the end.
    // OFF (the default) = prepaid: pay first, then the order fires to the
    // kitchen. Opt-in — see Features::DEFAULT_OFF.
    case Postpaid = 'postpaid';
    // Remote / delivery sales: ring an order up on the same register but tag it
    // as remote (phone / WhatsApp / delivery), capture the delivery details,
    // and work it through a fulfillment queue. Opt-in — see DEFAULT_OFF.
    case RemoteSales = 'remote_sales';
    // Let a cashier (not just a manager) count the drawer and close the shared
    // register. OFF by default = manager-only. Opt-in — see DEFAULT_OFF.
    case CashierClose = 'cashier_close';
    // Attach a proof-of-payment photo (a Benefit / bank-transfer screenshot) to
    // an order from the payment popup so the owner can verify it. Opt-in — see
    // DEFAULT_OFF.
    case PaymentProof = 'payment_proof';

    // Accounting sub-features.
    case ChartOfAccounts = 'chart_of_accounts';
    case JournalEntries = 'journal_entries';

    // Rent A Car sub-features.
    case RentalMaintenance = 'rental_maintenance';
    case RentalDrivers = 'rental_drivers';
    case RentalQuotations = 'rental_quotations';
    case RentalReplacements = 'rental_replacements';

    // Limousine sub-features.
    case LimoExpenses = 'limo_expenses';
    case LimoQuotations = 'limo_quotations';
    case LimoLocations = 'limo_locations';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'Point of Sale',
            self::Recipes => 'Recipes & ingredients',
            self::Inventory => 'Inventory',
            self::Purchases => 'Purchases',
            self::Hr => 'HR & payroll',
            self::Accounting => 'Accounting',
            self::Bookings => 'Bookings & rentals',
            self::Limousine => 'Limousine',
            self::Projects => 'Projects',
            self::Restaurant => 'Floor plan & tables',
            self::Kitchen => 'Kitchen & shisha display',
            self::Condiments => 'Condiments & add-ons',
            self::CustomerDiscounts => 'Customer discounts',
            self::Damage => 'Damage / waste log',
            self::BarcodeScanning => 'Barcode scanning (camera)',
            self::DailyReportCards => 'Daily sale & stock cards on dashboard',
            self::SplitOrder => 'Split order',
            self::Production => 'Production & store (mixing)',
            self::Postpaid => 'Postpaid (kitchen first, pay later)',
            self::RemoteSales => 'Remote / delivery sales',
            self::CashierClose => 'Cashiers can close the register',
            self::PaymentProof => 'Proof of payment',
            self::ChartOfAccounts => 'Chart of accounts',
            self::JournalEntries => 'Journal entries',
            self::RentalMaintenance => 'Car maintenance',
            self::RentalDrivers => 'Drivers',
            self::RentalQuotations => 'Rental quotations',
            self::RentalReplacements => 'Replacement vehicles',
            self::LimoExpenses => 'Trip expenses',
            self::LimoQuotations => 'Limousine quotations',
            self::LimoLocations => 'Saved locations',
        };
    }

    /**
     * One-line explanation shown under the label in an app's Settings tab.
     * Empty for app-level master features (never listed in a Settings tab).
     */
    public function description(): string
    {
        return match ($this) {
            self::Restaurant => 'Floor plan and tables for table-service ordering.',
            self::Kitchen => 'The kitchen and shisha display screens (KDS) and the buttons that open them.',
            self::Recipes => "Build products from ingredients and consume their stock on each sale.",
            self::Condiments => 'Let cashiers attach paid or free add-ons (extra cheese, no ice) to a cart line.',
            self::CustomerDiscounts => "Apply a percentage discount to an order from the customer's phone number.",
            self::Damage => 'Record damaged or wasted stock so on-hand counts stay accurate.',
            self::BarcodeScanning => 'Show the camera scan button in the terminal search bar (USB scanners always work).',
            self::DailyReportCards => 'Show the Daily sale and Daily stock report cards on the main dashboard.',
            self::SplitOrder => 'Let staff move items off an order onto a new one — splitting a bill between payers or tables.',
            self::Production => 'Mix raw materials into finished bottles held in a store, then move them to the shop for sale.',
            self::Postpaid => 'Send items to the kitchen as they are added and take payment at the end. Off = pay first, then the order fires to the kitchen.',
            self::RemoteSales => 'Ring up phone / WhatsApp / delivery orders on the register, capture the delivery details, and track them through a fulfillment queue.',
            self::CashierClose => 'Allow any cashier to count the cash drawer and close the shared register. Off = only a manager (admin) can close.',
            self::PaymentProof => 'Let the cashier attach a photo (a Benefit / bank-transfer screenshot) as proof of payment on the payment screen, so the owner can verify it later.',
            self::ChartOfAccounts => 'The list of ledger accounts (assets, income, expenses…).',
            self::JournalEntries => 'Manual and automatic double-entry journal postings.',
            self::RentalMaintenance => 'Log and schedule vehicle servicing and repairs.',
            self::RentalDrivers => 'Manage drivers that can be assigned to rentals.',
            self::RentalQuotations => 'Draft rental price quotes before they become orders.',
            self::RentalReplacements => 'Track replacement vehicles issued during a rental.',
            self::LimoExpenses => 'Record per-trip costs (fuel, tolls, driver…).',
            self::LimoQuotations => 'Draft limousine price quotes before booking.',
            self::LimoLocations => 'Saved pickup / drop-off locations for quick selection.',
            default => '',
        };
    }
}
