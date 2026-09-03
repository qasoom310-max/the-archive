<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Rental\Models\RentalOrder;

/**
 * The one definition of an orders-list row, read by both the screen and its
 * export — sized to this screen's tab + date-range + search state, the
 * richest of Rental's bespoke lists (closest in shape to the Limousine
 * booking queue, minus the per-leg detail).
 */
final class RentalOrderRows
{
    /**
     * @return Builder<RentalOrder>
     */
    public function query(string $tab = 'all', string $from = '', string $to = '', string $search = ''): Builder
    {
        $query = RentalOrder::query()
            ->with(['customer:id,name,country', 'vehicle:id,name,plate_no,color', 'createdBy:id,name'])
            ->orderByDesc('id');

        if ($tab === 'unpaid') {
            $query->whereIn('payment_status', [RentalOrder::PAYMENT_UNPAID, RentalOrder::PAYMENT_PARTIAL])
                ->whereIn('state', [RentalOrder::STATE_ACTIVE, RentalOrder::STATE_CLOSED]);
        } elseif (in_array($tab, [
            RentalOrder::STATE_DRAFT, RentalOrder::STATE_ACTIVE, RentalOrder::STATE_CLOSED, RentalOrder::STATE_CANCELLED,
        ], true)) {
            $query->where('state', $tab);
        }

        if ($from !== '') {
            $query->whereDate('start_date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('start_date', '<=', $to);
        }

        $term = trim($search);
        if ($term !== '') {
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', $like)->orWhere('plate_no', 'like', $like));
            });
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $tab, string $from, string $to, string $search): array
    {
        return $this->query($tab, $from, $to, $search)->get()->map(fn (RentalOrder $o): array => $this->row($o))->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(RentalOrder $order): array
    {
        return [
            'reference' => (string) ($order->reference ?? ''),
            'customer' => (string) ($order->customer->name ?? ''),
            'car' => (string) ($order->vehicle?->displayName() ?? ''),
            'pickup' => $order->start_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'return' => $order->end_date?->isoFormat('DD-MMM-YYYY') ?? '',
            'total' => ValueFormat::money($order->total),
            'status' => $order->state === RentalOrder::STATE_DRAFT ? __('Reservation') : __(ucfirst((string) $order->state)),
            'payment' => __(ucfirst((string) $order->payment_status)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'car' => __('Car'),
            'pickup' => __('Pick-up'), 'return' => __('Return'), 'total' => __('Total'),
            'status' => __('Status'), 'payment' => __('Payment'),
        ];
    }
}
