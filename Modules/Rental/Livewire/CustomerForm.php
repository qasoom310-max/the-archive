<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;

/**
 * Customer 360: the editable details (via the engine form) PLUS everything the
 * customer has done — their rental orders and limousine bookings — so their
 * whole history lives on the customer record, not buried in the orders list.
 * Rental and Limousine share one customer store, so both apps' activity shows.
 */
#[Layout('components.layouts.app')]
#[Title('Customer')]
final class CustomerForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $customer = $this->id !== null ? RentalCustomer::query()->find($this->id) : null;

        $orders = collect();
        $limoBookings = collect();
        $rentalSpend = 0.0;
        $limoSpend = 0.0;

        if ($customer !== null) {
            $orders = RentalOrder::query()
                ->where('customer_id', $customer->id)
                ->with('vehicle:id,name,plate_no,color')
                ->orderByDesc('id')
                ->limit(100)
                ->get();
            $rentalSpend = (float) $orders->where('payment_status', RentalOrder::PAYMENT_PAID)->sum('total');

            // Limousine shares the same customer; query its bookings by raw table
            // so Rental keeps no hard dependency on the Limousine module.
            if (Schema::hasTable('limo_bookings')) {
                $limoBookings = DB::table('limo_bookings')
                    ->where('customer_id', $customer->id)
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(['id', 'reference', 'pickup_at', 'fare', 'status', 'payment_status']);
                $limoSpend = (float) $limoBookings->where('payment_status', 'paid')->sum('fare');
            }
        }

        return view('rental::customer-form', [
            'customer' => $customer,
            'orders' => $orders,
            'limoBookings' => $limoBookings,
            'stats' => [
                'rentals' => $orders->count(),
                'limo' => $limoBookings->count(),
                'spend' => $rentalSpend + $limoSpend,
            ],
        ]);
    }
}
