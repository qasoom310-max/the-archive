<?php

declare(strict_types=1);

namespace Modules\Pos\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosSessionManager;

#[Layout('components.layouts.app')]
#[Title('Point of Sale')]
final class PosTerminal extends Component
{
    public int $sessionId;

    public int $orderId;

    public string $search = '';

    public ?int $categoryId = null;

    public string $customerSearch = '';

    public bool $paying = false;

    public ?int $paymentMethodId = null;

    public string $tendered = '';

    public ?int $receiptOrderId = null;

    public function mount(int $session): void
    {
        $pos = PosSession::query()->findOrFail($session);

        // Single shared register: any ACL-authorised cashier may join the
        // open session — no per-user ownership gate.
        abort_unless($pos->state === SessionState::Opened, 403, 'The register is closed.');
        $this->guard(Permission::Create);

        $this->sessionId = $pos->id;
        $this->orderId = $this->resolveDraftOrder($pos)->id;

        app(PosSessionManager::class)->heartbeat($pos, $this->currentUserId());
    }

    /**
     * Polled by the terminal so the Manage view sees who is live.
     */
    public function heartbeat(): void
    {
        app(PosSessionManager::class)->heartbeat(
            PosSession::query()->findOrFail($this->sessionId),
            $this->currentUserId(),
        );
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.order', $permission);
    }

    private function currentUserId(): ?int
    {
        $userId = Auth::id();

        return is_int($userId) ? $userId : null;
    }

    private function resolveDraftOrder(PosSession $session): PosOrder
    {
        $order = PosOrder::query()
            ->where('pos_session_id', $session->id)
            ->where('state', OrderState::Draft)
            ->latest('id')
            ->first();

        if ($order !== null) {
            return $order;
        }

        $seq = PosOrder::query()->where('pos_session_id', $session->id)->count() + 1;

        // Audit: the cashier who opened the cart owns the order.
        return PosOrder::query()->create([
            'pos_session_id' => $session->id,
            'user_id' => $this->currentUserId(),
            'reference' => sprintf('POS/%d/%04d', $session->id, $seq),
            'state' => OrderState::Draft,
        ]);
    }

    private function order(): PosOrder
    {
        return PosOrder::query()->with('lines', 'partner')->findOrFail($this->orderId);
    }

    public function addProduct(int $productId): void
    {
        $this->guard(Permission::Write);
        $product = PosProduct::query()->findOrFail($productId);
        $order = $this->order();

        $line = $order->lines()
            ->where('pos_product_id', $product->id)
            ->where('discount', 0)
            ->first();

        if ($line !== null) {
            $line->qty += 1;
        } else {
            $line = $order->lines()->make([
                'pos_product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'unit_price' => $product->price,
                'discount' => 0,
                'tax_rate' => $product->tax_rate,
            ]);
        }

        $line->recompute();
        $line->save();
        $order->recalculate();
    }

    /**
     * Adjust a cart line's quantity by one step. `$increment` true = +1,
     * false = −1; reaching 0 removes the line (quantity never goes negative).
     *
     * Global / role-independent: gated only by the standard `pos.order`
     * Write permission every cashier already holds (same guard as
     * addProduct/removeLine) — no Admin-vs-Cashier branching.
     */
    public function updateQuantity(int $lineId, bool $increment): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $line = $order->lines()->whereKey($lineId)->first();

        if ($line === null) {
            return;
        }

        $line->qty = max(0.0, $line->qty + ($increment ? 1.0 : -1.0));

        if ($line->qty <= 0) {
            $line->delete();
        } else {
            $line->recompute();
            $line->save();
        }

        $order->recalculate();
    }

    public function setDiscount(int $lineId, float $percent): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $line = $order->lines()->whereKey($lineId)->first();

        if ($line === null) {
            return;
        }

        $line->discount = max(0.0, min(100.0, $percent));
        $line->recompute();
        $line->save();
        $order->recalculate();
    }

    public function removeLine(int $lineId): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $order->lines()->whereKey($lineId)->delete();
        $order->recalculate();
    }

    public function setCustomer(int $partnerId): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $order->partner_id = $partnerId;
        $order->save();
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $order = $this->order();
        $order->partner_id = null;
        $order->save();
    }

    public function startPayment(): void
    {
        $order = $this->order();

        if ($order->lines()->count() === 0 || $order->total <= 0) {
            return;
        }

        $this->paymentMethodId = PosPaymentMethod::query()
            ->where('active', true)->orderBy('sequence')->value('id');
        $this->tendered = number_format(max(0.0, $order->total - $order->paymentsTotal()), 2, '.', '');
        $this->paying = true;
    }

    public function addPayment(): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();
        $amount = round((float) $this->tendered, 2);

        if ($this->paymentMethodId === null || $amount <= 0) {
            return;
        }

        $method = PosPaymentMethod::query()->findOrFail($this->paymentMethodId);
        $order->registerPayment($method, $amount);

        $remaining = round($order->total - $order->paymentsTotal(), 2);
        $this->tendered = $remaining > 0
            ? number_format($remaining, 2, '.', '')
            : '';
    }

    public function validateOrder(): void
    {
        $this->guard(Permission::Write);
        $order = $this->order();

        // Already finalised — just show the receipt again (no re-consume).
        if ($order->state === OrderState::Done) {
            $this->receiptOrderId = $order->id;
            $this->paying = false;

            return;
        }

        if (! $order->isFullyPaid()) {
            return;
        }

        $order->finalizeSale();

        $this->receiptOrderId = $order->id;
        $this->paying = false;
    }

    public function newOrder(): void
    {
        $session = PosSession::query()->findOrFail($this->sessionId);
        $this->orderId = $this->resolveDraftOrder($session)->id;
        $this->receiptOrderId = null;
        $this->paying = false;
        $this->search = '';
    }

    /**
     * The selected category plus all its descendants — so clicking a
     * parent shows the whole subtree's products (Odoo-style).
     *
     * @return list<int>
     */
    private function categorySubtreeIds(): array
    {
        if ($this->categoryId === null) {
            return [];
        }

        $category = PosCategory::query()->find($this->categoryId);

        return $category !== null ? $category->subtreeIds() : [$this->categoryId];
    }

    /**
     * @return Collection<int, PosProduct>
     */
    private function products(): Collection
    {
        return PosProduct::query()
            ->with('recipeLines.component')
            ->where('active', true)
            ->when($this->categoryId !== null, fn ($q) => $q->whereIn('pos_category_id', $this->categorySubtreeIds()))
            ->when($this->search !== '', fn ($q) => $q->where(function ($w): void {
                $w->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('barcode', $this->search);
            }))
            ->orderBy('name')
            ->limit(60)
            ->get();
    }

    /**
     * @return Collection<int, Partner>
     */
    private function customerResults(): Collection
    {
        if (mb_strlen($this->customerSearch) < 2) {
            return new Collection();
        }

        return Partner::query()
            ->where('name', 'like', '%' . $this->customerSearch . '%')
            ->orderBy('name')
            ->limit(6)
            ->get();
    }

    public function render(): View
    {
        $order = $this->order();

        return view('pos::terminal', [
            'order' => $order,
            'session' => PosSession::query()->with('user')->findOrFail($this->sessionId),
            'cashier' => Auth::user(),
            'lines' => $order->lines()->latest('id')->get(),
            'products' => $this->products(),
            'categories' => PosCategory::query()->whereNull('parent_id')
                ->orderBy('sequence')->orderBy('name')->get(),
            'subCategories' => $this->categoryId !== null
                ? PosCategory::query()->where('parent_id', $this->categoryId)
                    ->orderBy('sequence')->orderBy('name')->get()
                : new Collection(),
            'paymentMethods' => PosPaymentMethod::query()->where('active', true)->orderBy('sequence')->get(),
            'customerResults' => $this->customerResults(),
            'receipt' => $this->receiptOrderId !== null
                ? PosOrder::query()->with('lines', 'payments.method', 'partner')->find($this->receiptOrderId)
                : null,
            'now' => Carbon::now(),
        ]);
    }
}
