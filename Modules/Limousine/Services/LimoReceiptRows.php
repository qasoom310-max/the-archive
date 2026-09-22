<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Views\ValueFormat;
use Illuminate\Database\Eloquent\Builder;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Support\LiveEntry;

/**
 * The one definition of a receipts-list row, read by both the screen and its
 * export — sized to this screen's own tab + method + search state. The
 * accountant's grouped "to confirm" view is a presentation of these same
 * rows, so the export reads whichever ones the tab, method and search select.
 */
final class LimoReceiptRows
{
    /**
     * @return Builder<LimoReceipt>
     */
    public function query(string $tab = '', string $method = '', string $search = '', string $from = '', string $to = '', string $preparedBy = '', bool $onlyLive = false): Builder
    {
        $query = LimoReceipt::query()->with(['customer:id,name', 'invoice:id,reference'])->orderByDesc('id');

        if ($tab === 'to_confirm') {
            $query->whereNull('confirmed_at');
        } elseif ($tab === 'confirmed') {
            $query->whereNotNull('confirmed_at');
        }

        if (in_array($method, array_column(LimoReceipt::methodOptions(), 'value'), true)) {
            $query->where('method', $method);
        }

        // The receipt's OWN date — the day the money actually changed hands,
        // not the day the row was typed in — so this matches what the office
        // means by "receipts from last week".
        if ($from !== '') {
            $query->whereDate('date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('date', '<=', $to);
        }

        if ($preparedBy !== '') {
            $query->where('prepared_by', $preparedBy);
        }

        $term = trim($search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        // The historical CSV import keeps the old system's own receipt number
        // VERBATIM ("L-RCPT12968"), never the app's own auto-generated
        // "RCP/00042" shape — see LegacyReceiptImporter. That prefix is the
        // one reliable marker: anything else came from the register or the
        // ongoing Import button, i.e. was entered live.
        if ($onlyLive) {
            $query->where('reference', 'not like', 'L-RCPT%')
                ->where('created_at', '>=', LiveEntry::since());
        }

        return $query;
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(string $tab, string $method, string $search, string $from = '', string $to = '', string $preparedBy = '', bool $onlyLive = false): array
    {
        return $this->query($tab, $method, $search, $from, $to, $preparedBy, $onlyLive)->get()->map(fn (LimoReceipt $r): array => $this->row($r))->all();
    }

    /**
     * Everyone who has ever raised a receipt by hand or through the register,
     * for the "Created by" filter's options. Distinct names, not user ids — the
     * column is a NAME SNAPSHOT (see {@see LimoReceipt}), so a renamed or
     * deleted account still filters correctly by what is actually stored.
     *
     * @return list<string>
     */
    public function preparers(): array
    {
        return LimoReceipt::query()
            ->whereNotNull('prepared_by')
            ->where('prepared_by', '!=', '')
            ->distinct()
            ->orderBy('prepared_by')
            ->pluck('prepared_by')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function row(LimoReceipt $receipt): array
    {
        return [
            'reference' => (string) ($receipt->reference ?? ''),
            'customer' => (string) ($receipt->customer->name ?? ''),
            'invoice' => (string) ($receipt->invoice->reference ?? ''),
            'date' => $receipt->date?->isoFormat('DD-MMM-YYYY') ?? '',
            'method' => __(ucfirst((string) $receipt->method)),
            'amount' => ValueFormat::money($receipt->amount),
            'confirmed' => $receipt->isConfirmed() ? __('Confirmed') : __('Unconfirmed'),
            'prepared_by' => (string) ($receipt->prepared_by ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headings(): array
    {
        return [
            'reference' => __('Reference'), 'customer' => __('Customer'), 'invoice' => __('Invoice'),
            'date' => __('Date'), 'method' => __('Method'), 'amount' => __('Amount'), 'confirmed' => __('Confirmed'),
            'prepared_by' => __('Created by'),
        ];
    }
}
