<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Rental\Models\RentalOrder;

/**
 * Builds the self-contained Car Hire Agreement PDF (company header, customer +
 * vehicle, charges, terms, signature) — the document emailed to a customer.
 * Terms come from the editable `rental.agreement_terms` setting.
 */
final class RentalAgreementPdf
{
    /**
     * @return array<string, mixed>
     */
    public function viewData(RentalOrder $order): array
    {
        $order->loadMissing('customer', 'vehicle', 'branch');

        return [
            'order' => $order,
            'companyName' => (string) Setting::get('company.name', 'OpenERP'),
            'logoPath' => $this->logoPath(),
            'terms' => (string) Setting::get('rental.agreement_terms', ''),
        ];
    }

    /** Rendered PDF bytes. */
    public function render(RentalOrder $order): string
    {
        return Pdf::loadView('rental::agreement-pdf', $this->viewData($order))
            ->setPaper('a4')
            ->output();
    }

    /** Filesystem path to the company logo, or null when absent/missing. */
    private function logoPath(): ?string
    {
        $rel = Setting::get('company.logo');
        if (! is_string($rel) || $rel === '') {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($rel) ? $disk->path($rel) : null;
    }
}
