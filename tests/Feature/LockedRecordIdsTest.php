<?php

declare(strict_types=1);

namespace Tests\Feature;

use Livewire\Attributes\Locked;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Record ids that the server decides are locked against the browser.
 *
 * A Livewire public property can be changed from the page unless it is
 * `#[Locked]`. For a form's `$id`, or the id behind an open dialog, that would
 * let a tampered request repoint a save or a delete at another record. Every
 * such property is listed here so a new one cannot quietly go back to open.
 */
final class LockedRecordIdsTest extends TestCase
{
    /** @var array<class-string, list<string>> */
    private const LOCKED = [
        \App\Livewire\Pages\EmployeeForm::class => ['id'],
        \App\Livewire\Pages\MonthlyProfit::class => ['payingExpenseId'],
        \App\Livewire\Settings\UserManager::class => ['editingId'],
        \App\Livewire\WorkspacesPage::class => ['editingId', 'deletingId'],
        \Modules\Contacts\Livewire\PartnerForm::class => ['id'],
        \Modules\Limousine\Livewire\Bookings::class => ['editingId', 'cancellingId', 'assigningId', 'collectingId'],
        \Modules\Limousine\Livewire\Coupons::class => ['usingId'],
        \Modules\Limousine\Livewire\CustomerForm::class => ['id'],
        \Modules\Limousine\Livewire\DriverForm::class => ['id'],
        \Modules\Limousine\Livewire\ExpenseForm::class => ['id'],
        \Modules\Limousine\Livewire\LocationForm::class => ['id'],
        \Modules\Limousine\Livewire\InvoiceForm::class => ['id', 'booking_id'],
        \Modules\Limousine\Livewire\QuotationForm::class => ['id', 'booking_id'],
        \Modules\Limousine\Livewire\ReceiptForm::class => ['id'],
        \Modules\Limousine\Livewire\BookingForm::class => ['id'],
        \Modules\Pos\Livewire\PosCondimentForm::class => ['id'],
        \Modules\Pos\Livewire\PosCustomerDiscountForm::class => ['id'],
        \Modules\Pos\Livewire\PosFloorForm::class => ['id'],
        \Modules\Pos\Livewire\PosIngredientCategoryForm::class => ['id'],
        \Modules\Pos\Livewire\PosIngredientForm::class => ['id'],
        \Modules\Pos\Livewire\PosTableForm::class => ['id'],
        \Modules\Pos\Livewire\ProductionForm::class => ['id'],
        \Modules\Pos\Livewire\PosFloorPlan::class => ['floorId', 'selectedId'],
        \Modules\Pos\Livewire\PosOrders::class => ['dateOrderId', 'deliveryOrderId'],
        \Modules\Pos\Livewire\PosSettlements::class => ['receivingId'],
        \Modules\Pos\Livewire\PosStockReport::class => ['adjustId'],
        \Modules\Pos\Livewire\PosTerminal::class => ['orderId', 'tableId', 'condimentLineId', 'receiptOrderId'],
        \Modules\Pos\Livewire\SplitOrderModal::class => ['orderId'],
        \Modules\Project\Livewire\ProjectForm::class => ['id'],
        \Modules\Project\Livewire\TaskForm::class => ['id'],
        \Modules\Purchases\Livewire\PurchaseForm::class => ['id'],
        \Modules\Rental\Livewire\BranchForm::class => ['id'],
        \Modules\Rental\Livewire\CustomerForm::class => ['id'],
        \Modules\Rental\Livewire\DriverForm::class => ['id'],
        \Modules\Rental\Livewire\MaintenanceForm::class => ['id'],
        \Modules\Rental\Livewire\QuotationForm::class => ['id', 'order_id'],
        \Modules\Rental\Livewire\VehicleForm::class => ['id'],
        \Modules\Rental\Livewire\InvoiceForm::class => ['id', 'order_id'],
        \Modules\Rental\Livewire\ReceiptForm::class => ['id'],
        \Modules\Rental\Livewire\ReplacementForm::class => ['id', 'order_id'],
        \Modules\Rental\Livewire\OrderForm::class => ['id'],
    ];

    public function test_every_server_set_record_id_is_locked(): void
    {
        $open = [];
        foreach (self::LOCKED as $class => $props) {
            foreach ($props as $prop) {
                $attributes = (new ReflectionProperty($class, $prop))->getAttributes(Locked::class);
                if ($attributes === []) {
                    $open[] = $class . '::$' . $prop;
                }
            }
        }

        $this->assertSame([], $open, 'These record ids can still be changed from the browser: ' . implode(', ', $open));
    }
}
