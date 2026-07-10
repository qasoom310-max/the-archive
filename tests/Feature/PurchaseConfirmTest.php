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
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
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

    public function test_confirming_an_ingredient_line_raises_its_stock_and_sets_its_cost(): void
    {
        // A perfume oil bought for the first time, valued at 0.
        $oil = PosIngredient::query()->create(['name' => 'Rose Oil', 'stock_on_hand' => 2, 'cost_price' => 0]);

        $purchase = Purchase::query()->create(['date' => '2026-06-08', 'is_stock_purchase' => true]);
        $purchase->lines()->create([
            'pos_ingredient_id' => $oil->id,
            'description' => 'Rose Oil',
            'quantity' => 3,
            'unit_cost' => 12.5,
        ]);

        app(PurchaseConfirmer::class)->confirm($purchase);

        $oil->refresh();
        $this->assertSame(5.0, (float) $oil->stock_on_hand);   // 2 + 3
        $this->assertSame(12.5, (float) $oil->cost_price);     // adopts the purchase price
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
            ->set('lines.0.component', 'p:' . $coal->id)
            ->set('lines.0.quantity', 7)
            ->set('lines.0.unit_cost', 0.5)
            ->call('confirm')
            ->assertSet('state', 'confirmed');

        $this->assertSame(9.0, (float) $coal->fresh()?->stock_on_hand);
        $this->assertSame(1, Purchase::query()->where('state', 'confirmed')->count());
    }

    public function test_confirming_a_condiment_line_raises_the_condiment_stock(): void
    {
        $cheese = PosCondiment::query()->create(['name' => 'Extra cheese', 'price' => 0.5, 'stock_on_hand' => 3.0, 'active' => true]);

        Livewire::test(PurchaseForm::class)
            ->set('form.date', '2026-06-23')
            ->set('lines.0.component', 'c:' . $cheese->id)
            ->set('lines.0.quantity', 12)
            ->set('lines.0.unit_cost', 0.2)
            ->call('confirm')
            ->assertSet('state', 'confirmed');

        // The condiment's on-hand went up; it's NOT on the warehouse ledger.
        $this->assertSame(15.0, (float) $cheese->fresh()?->stock_on_hand);
    }

    public function test_crafted_products_are_hidden_from_the_purchase_picker(): void
    {
        // Pepsi: a resale product (no recipe) — bought from a vendor.
        $pepsi = PosProduct::query()->create(['name' => 'Pepsi', 'price' => 1, 'tax_rate' => 0, 'active' => true]);
        // Egg Sandwich: a crafted product (has a recipe) — assembled, not bought.
        $sandwich = PosProduct::query()->create(['name' => 'Egg Sandwich', 'price' => 3, 'tax_rate' => 0, 'active' => true]);
        $bread = PosIngredient::query()->create(['name' => 'Bread', 'cost_price' => 0.2, 'stock_on_hand' => 10]);
        PosProductRecipe::query()->create([
            'parent_product_id' => $sandwich->id,
            'component_ingredient_id' => $bread->id,
            'quantity_consumed' => 1,
        ]);

        Livewire::test(PurchaseForm::class)->assertViewHas('components', function ($components) use ($pepsi, $sandwich, $bread): bool {
            $keys = collect($components)->pluck('key')->all();

            return in_array('p:' . $pepsi->id, $keys, true)       // resale product stays
                && ! in_array('p:' . $sandwich->id, $keys, true)  // crafted product hidden
                && in_array('i:' . $bread->id, $keys, true);      // its ingredient is buyable
        });
    }

    public function test_purchase_name_and_expiry_date_persist(): void
    {
        $coal = $this->coal(stock: 0.0);

        // confirm() persists the record (name/expiry) without the new-bill
        // redirect that save() would trigger (the module's named route isn't
        // registered in the test harness — the known module-boot gap).
        Livewire::test(PurchaseForm::class)
            ->set('form.date', '2026-06-23')
            ->set('form.name', 'Weekly coffee restock')
            ->set('form.expiry_date', '2026-12-31')
            ->set('lines.0.component', 'p:' . $coal->id)
            ->set('lines.0.quantity', 2)
            ->set('lines.0.unit_cost', 1.0)
            ->call('confirm')
            ->assertSet('state', 'confirmed');

        $purchase = Purchase::query()->latest('id')->firstOrFail();
        $this->assertSame('Weekly coffee restock', $purchase->name);
        $this->assertSame('2026-12-31', $purchase->expiry_date?->toDateString());
    }

    public function test_inline_vendor_saves_the_location_to_the_partner(): void
    {
        Livewire::test(PurchaseForm::class)
            ->call('openVendorModal')
            ->set('newVendor.name', 'Najaf Bakery')
            ->set('newVendor.location', 'Manama')
            ->call('saveVendor')
            ->assertHasNoErrors();

        $vendor = Partner::query()->where('name', 'Najaf Bakery')->firstOrFail();
        $this->assertSame('Manama', $vendor->city);
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
            ->assertSet('lines.0.component', 'p:' . PosProduct::query()->where('name->en', 'Arabica Beans')->value('id'))
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

    public function test_buy_shortcut_prefills_the_purchase_name_and_product_line(): void
    {
        $coal = $this->coal(stock: 0.0);

        // The Stock Report "Buy" link opens the new bill with ?product={id};
        // the form pre-picks the product, its name + recorded cost.
        Livewire::withQueryParams(['product' => $coal->id])
            ->test(PurchaseForm::class)
            ->assertSet('form.name', 'Coal')
            ->assertSet('lines.0.component', 'p:' . $coal->id)
            ->assertSet('lines.0.description', 'Coal')
            ->assertSet('lines.0.unit_cost', 0.5);
    }

    public function test_new_bill_without_a_product_query_starts_blank(): void
    {
        Livewire::test(PurchaseForm::class)
            ->assertSet('form.name', '')
            ->assertSet('lines.0.component', '');
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
