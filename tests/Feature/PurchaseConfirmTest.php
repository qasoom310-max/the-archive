<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\InventorySeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Providers\AccountingServiceProvider;
use Modules\Contacts\Models\Partner;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Models\StockQuant;
use Modules\Pos\Models\PosProduct;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Livewire\PurchaseForm;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Services\PurchaseConfirmer;
use Tests\TestCase;

/**
 * The whole point of the Purchases module: confirming a vendor bill must
 * move POS stock, warehouse stock AND the accounting books together.
 */
final class PurchaseConfirmTest extends TestCase
{
    use DatabaseMigrations;

    private int $stockLocationId;

    private int $vendorLocationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        // Installing purchases pulls contacts + pos + inventory + accounting.
        app(ModuleManager::class)->install('purchases');

        // Module providers boot at app-boot — before this setUp installed the
        // modules — so the Purchase→Accounting event listener wasn't wired yet
        // (the known "module listeners register on the boot AFTER install" gap).
        // Re-registering the provider now runs its boot() and the Event::listen,
        // exactly as production does (where modules are installed before boot).
        $this->app->register(AccountingServiceProvider::class);

        (new ChartOfAccountsSeeder())->run();
        (new InventorySeeder())->run();

        $receipt = StockOperationType::query()->where('code', 'incoming')->firstOrFail();
        $this->stockLocationId = (int) $receipt->default_dest_location_id;
        $this->vendorLocationId = (int) $receipt->default_source_location_id;
    }

    private function coal(float $stock = 0.0): PosProduct
    {
        return PosProduct::query()->create([
            'name' => 'Coal',
            'price' => 1.0,
            'cost_price' => 0.5,
            'stock_on_hand' => $stock,
            'active' => true,
        ]);
    }

    private function quantAt(int $locationId, int $productId): float
    {
        return (float) (StockQuant::query()
            ->where('stock_location_id', $locationId)
            ->where('product_id', $productId)
            ->value('quantity') ?? 0.0);
    }

    private function draftBill(PosProduct $product, float $qty, float $unitCost, bool $stockPurchase = true): Purchase
    {
        $purchase = Purchase::query()->create([
            'date' => '2026-06-08',
            'is_stock_purchase' => $stockPurchase,
        ]);
        $purchase->lines()->create([
            'pos_product_id' => $product->id,
            'description' => 'Coal',
            'quantity' => $qty,
            'unit_cost' => $unitCost,
        ]);

        return $purchase;
    }

    public function test_confirm_raises_pos_stock_warehouse_stock_and_posts_accounting(): void
    {
        $coal = $this->coal(stock: 4.0);
        $stockBefore = $this->quantAt($this->stockLocationId, (int) $coal->id);
        $vendorBefore = $this->quantAt($this->vendorLocationId, (int) $coal->id);

        $purchase = $this->draftBill($coal, qty: 10.0, unitCost: 0.5);
        app(PurchaseConfirmer::class)->confirm($purchase);

        // 1) POS stock.
        $this->assertSame(14.0, (float) $coal->fresh()?->stock_on_hand);

        // 2) Warehouse stock — Stock location up 10, Vendor location down 10.
        $this->assertSame($stockBefore + 10.0, $this->quantAt($this->stockLocationId, (int) $coal->id));
        $this->assertSame($vendorBefore - 10.0, $this->quantAt($this->vendorLocationId, (int) $coal->id));

        // 3) Accounting — a posted entry: Dr Inventory 5.00 / Cr A/P 5.00.
        $purchase->refresh();
        $this->assertTrue($purchase->state === PurchaseState::Confirmed);
        $this->assertSame(5.0, (float) $purchase->total);

        $entry = JournalEntry::query()->where('reference', $purchase->reference)->firstOrFail();
        $this->assertTrue($entry->isPosted());

        $inventory = Account::byCode('1200');
        $payable = Account::byCode('2010');
        $this->assertNotNull($inventory);
        $this->assertNotNull($payable);

        $this->assertSame(5.0, (float) $entry->items()->where('account_id', $inventory->id)->sum('debit'));
        $this->assertSame(5.0, (float) $entry->items()->where('account_id', $payable->id)->sum('credit'));
    }

    public function test_confirm_is_idempotent(): void
    {
        $coal = $this->coal(stock: 0.0);
        $purchase = $this->draftBill($coal, qty: 10.0, unitCost: 0.5);

        $confirmer = app(PurchaseConfirmer::class);
        $confirmer->confirm($purchase);
        $confirmer->confirm($purchase->fresh() ?? $purchase); // second call — no double count

        $this->assertSame(10.0, (float) $coal->fresh()?->stock_on_hand);
        $this->assertSame(1, JournalEntry::query()->where('reference', $purchase->reference)->count());
    }

    public function test_non_stock_purchase_debits_expense_not_inventory(): void
    {
        $coal = $this->coal(stock: 0.0);
        $purchase = $this->draftBill($coal, qty: 4.0, unitCost: 2.0, stockPurchase: false);

        app(PurchaseConfirmer::class)->confirm($purchase);

        $entry = JournalEntry::query()->where('reference', $purchase->reference)->firstOrFail();
        $expense = Account::byCode('5010');
        $inventory = Account::byCode('1200');
        $this->assertNotNull($expense);
        $this->assertNotNull($inventory);

        $this->assertSame(8.0, (float) $entry->items()->where('account_id', $expense->id)->sum('debit'));
        $this->assertSame(0.0, (float) $entry->items()->where('account_id', $inventory->id)->sum('debit'));
    }

    public function test_form_confirm_button_syncs_stock_end_to_end(): void
    {
        $coal = $this->coal(stock: 2.0);

        Livewire::test(PurchaseForm::class)
            ->set('form.date', '2026-06-08')
            ->set('lines.0.pos_product_id', (int) $coal->id)
            ->set('lines.0.quantity', 7)
            ->set('lines.0.unit_cost', 0.5)
            ->call('confirm')
            ->assertSet('state', 'confirmed');

        $this->assertSame(9.0, (float) $coal->fresh()?->stock_on_hand);
        $this->assertSame(1, Purchase::query()->where('state', 'confirmed')->count());
    }

    public function test_inline_vendor_create_makes_a_partner_and_selects_it(): void
    {
        Livewire::test(PurchaseForm::class)
            ->call('openVendorModal')
            ->assertSet('addingVendor', true)
            ->set('newVendor.name', 'Gulf Coal & Supplies')
            ->set('newVendor.phone', '+973 1700 0000')
            ->set('newVendor.email', 'sales@gulfcoal.test')
            ->call('saveVendor')
            ->assertHasNoErrors()
            ->assertSet('addingVendor', false);

        $vendor = Partner::query()->where('name', 'Gulf Coal & Supplies')->firstOrFail();
        $this->assertTrue((bool) $vendor->is_company);
        $this->assertSame('sales@gulfcoal.test', $vendor->email);

        // The new vendor is selected on the bill without a trip to Contacts.
        Livewire::test(PurchaseForm::class)
            ->call('openVendorModal')
            ->set('newVendor.name', 'Second Vendor')
            ->call('saveVendor')
            ->assertSet('form.partner_id', (string) Partner::query()->where('name', 'Second Vendor')->value('id'));
    }

    public function test_inline_vendor_requires_a_name_and_validates_email(): void
    {
        Livewire::test(PurchaseForm::class)
            ->call('openVendorModal')
            ->set('newVendor.name', '')
            ->call('saveVendor')
            ->assertHasErrors(['newVendor.name' => 'required']);

        Livewire::test(PurchaseForm::class)
            ->call('openVendorModal')
            ->set('newVendor.name', 'Has Bad Email')
            ->set('newVendor.email', 'not-an-email')
            ->call('saveVendor')
            ->assertHasErrors(['newVendor.email']);

        $this->assertSame(0, Partner::query()->where('name', 'Has Bad Email')->count());
    }

    public function test_inline_product_create_makes_a_pos_product_and_selects_it_on_the_line(): void
    {
        Livewire::test(PurchaseForm::class)
            ->call('openProductModal', 0, 'Arabica Beans')
            ->assertSet('addingProduct', true)
            ->assertSet('newProduct.name', 'Arabica Beans') // typed search prefills the name
            ->set('newProduct.price', '3.5')
            ->set('newProduct.cost_price', '1.25')
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSet('addingProduct', false)
            // `name` is translatable JSON, so look it up via the locale path.
            ->assertSet('lines.0.pos_product_id', (string) PosProduct::query()->where('name->en', 'Arabica Beans')->value('id'))
            ->assertSet('lines.0.description', 'Arabica Beans')
            ->assertSet('lines.0.unit_cost', 1.25); // cost prefilled from the new product

        $product = PosProduct::query()->where('name->en', 'Arabica Beans')->firstOrFail();
        $this->assertTrue((bool) $product->active);
        $this->assertSame(1.25, (float) $product->cost_price);
        $this->assertSame(0.0, (float) $product->stock_on_hand);
    }

    public function test_inline_product_persists_the_full_pos_field_set(): void
    {
        Livewire::test(PurchaseForm::class)
            ->call('openProductModal', 0, 'Full Beans')
            ->set('newProduct.price', '4')
            ->set('newProduct.cost_price', '2')
            ->set('newProduct.tax_rate', '10')
            ->set('newProduct.stock_on_hand', '25')
            ->set('newProduct.unit', 'kg')
            ->set('newProduct.reorder_point', '8')
            ->set('newProduct.barcode', 'BEAN-001')
            ->set('newProduct.active', false)
            ->call('saveProduct')
            ->assertHasNoErrors();

        $product = PosProduct::query()->where('name->en', 'Full Beans')->firstOrFail();
        $this->assertSame(4.0, (float) $product->price);
        $this->assertSame(2.0, (float) $product->cost_price);
        $this->assertSame(10.0, (float) $product->tax_rate);
        $this->assertSame(25.0, (float) $product->stock_on_hand);
        $this->assertSame('kg', $product->unit);
        $this->assertSame(8.0, (float) $product->reorder_point);
        $this->assertSame('BEAN-001', $product->barcode);
        $this->assertFalse((bool) $product->active);
    }

    public function test_inline_product_requires_a_name(): void
    {
        Livewire::test(PurchaseForm::class)
            ->call('openProductModal', 0, '')
            ->set('newProduct.name', '')
            ->call('saveProduct')
            ->assertHasErrors(['newProduct.name' => 'required']);

        $this->assertSame(0, PosProduct::query()->count());
    }

    public function test_purchases_app_lands_on_its_tile_dashboard(): void
    {
        // The sidebar is gone: /app/purchases now renders the engine tile
        // dashboard (ModuleHome) with a Purchase tile linking to the list,
        // instead of redirecting straight to /app/purchases/purchase.
        $this->get('/app/purchases')
            ->assertOk()
            ->assertSeeHtml('href="' . url('/app/purchases/purchase') . '"');
    }
}
