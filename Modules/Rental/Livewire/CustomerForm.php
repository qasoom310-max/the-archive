<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;

/**
 * Customer record + 360 history. A customer is an Individual (name, CPR,
 * licence) or a Company (company name, CR number + CR document, company phone,
 * and a contact person with their own phone). A country is captured so phones
 * read with a dial code and a flag shows wherever the customer appears. Below
 * the details, the customer's whole rental + limousine history is listed.
 */
#[Layout('components.layouts.app')]
#[Title('Customer')]
final class CustomerForm extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'rental.customer';
    }

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    public string $type = RentalCustomer::TYPE_INDIVIDUAL;

    public string $name = '';

    public string $country = 'BH';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public bool $active = true;

    // Individual
    public string $cpr = '';

    public string $license_no = '';

    public string $nationality = '';

    // Company
    public string $cr_number = '';

    public string $contact_person = '';

    public string $contact_phone = '';

    /** Stored CR document path from the direct-upload controller (Hostinger-safe). */
    public string $crDocumentPath = '';

    public ?string $existingCrDocument = null;

    public function mount(?int $id = null): void
    {
        $this->guardAccess(Permission::Read);
        if ($id === null) {
            return;
        }

        $customer = RentalCustomer::query()->find($id);
        if ($customer === null) {
            return;
        }

        $this->id = $customer->id;
        $this->type = $customer->type;
        $this->name = $customer->name;
        $this->country = $customer->country ?? 'BH';
        $this->phone = $customer->phone ?? '';
        $this->email = $customer->email ?? '';
        $this->address = $customer->address ?? '';
        $this->active = $customer->active;
        $this->cpr = $customer->cpr ?? '';
        $this->license_no = $customer->license_no ?? '';
        $this->nationality = $customer->nationality ?? '';
        $this->cr_number = $customer->cr_number ?? '';
        $this->contact_person = $customer->contact_person ?? '';
        $this->contact_phone = $customer->contact_phone ?? '';
        $this->existingCrDocument = $customer->cr_document;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', 'in:individual,company'],
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'cpr' => ['nullable', 'string', 'max:50'],
            'license_no' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'cr_number' => ['nullable', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'crDocumentPath' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->guardSave($this->id === null);
        $this->validate();

        $customer = $this->id !== null ? RentalCustomer::query()->find($this->id) : new RentalCustomer();
        if ($customer === null) {
            return;
        }

        $wasNew = ! $customer->exists;

        // The individual/company type is set once at creation. After that only a
        // super-admin may change it — for everyone else the saved type wins,
        // even if the property was tampered with client-side.
        if ($customer->exists && ! $this->isSuperAdmin()) {
            $this->type = $customer->type;
        }

        $isCompany = $this->type === RentalCustomer::TYPE_COMPANY;

        $customer->type = $this->type;
        $customer->name = trim($this->name);
        $customer->country = $this->country !== '' ? strtoupper($this->country) : null;
        $customer->phone = $this->trimOrNull($this->phone);
        $customer->email = $this->trimOrNull($this->email);
        $customer->address = $this->trimOrNull($this->address);
        $customer->active = $this->active;

        // Identity fields by type — keep the other type's fields clear.
        $customer->cpr = $isCompany ? null : $this->trimOrNull($this->cpr);
        $customer->license_no = $isCompany ? null : $this->trimOrNull($this->license_no);
        $customer->nationality = $isCompany ? null : $this->trimOrNull($this->nationality);
        $customer->cr_number = $isCompany ? $this->trimOrNull($this->cr_number) : null;
        $customer->contact_person = $isCompany ? $this->trimOrNull($this->contact_person) : null;
        $customer->contact_phone = $isCompany ? $this->trimOrNull($this->contact_phone) : null;

        if ($isCompany && $this->crDocumentPath !== '') {
            $customer->cr_document = $this->crDocumentPath;
        }

        $customer->save();

        app(ActivityLogger::class)->logFor($customer, $wasNew ? 'created' : 'updated');

        $this->id = $customer->id;
        $this->existingCrDocument = $customer->cr_document;
        $this->crDocumentPath = '';
        session()->flash('toast', __('Customer saved.'));
    }

    private function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
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

            if (Schema::hasTable('limo_bookings')) {
                $limoBookings = DB::table('limo_bookings')
                    ->where('customer_id', $customer->id)
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(['id', 'reference', 'pickup_at', 'fare', 'status', 'payment_status']);
                $limoSpend = (float) $limoBookings->where('payment_status', 'paid')->sum('fare');
            }
        }

        // Aggregate every document the customer has — the company CR plus the
        // CPR / licence images captured on each of their orders — so they're
        // all downloadable from the customer record, not buried in the order.
        // One of each document — the customer's latest CPR and latest licence
        // (orders are newest-first), plus the company CR. No per-order repeats.
        $documents = [];
        if ($customer !== null) {
            if ($customer->cr_document !== null) {
                $documents[] = ['label' => __('CR document'), 'ref' => '', 'url' => Storage::disk('public')->url($customer->cr_document), 'kind' => 'pdf'];
            }
            $haveCpr = false;
            $haveLicence = false;
            foreach ($orders as $o) {
                if (! $haveCpr && $o->cpr_image_path !== null) {
                    $documents[] = ['label' => __('CPR / ID'), 'ref' => (string) $o->reference, 'url' => Storage::disk('public')->url($o->cpr_image_path), 'kind' => 'image'];
                    $haveCpr = true;
                }
                if (! $haveLicence && $o->license_image_path !== null) {
                    $documents[] = ['label' => __('Licence'), 'ref' => (string) $o->reference, 'url' => Storage::disk('public')->url($o->license_image_path), 'kind' => 'image'];
                    $haveLicence = true;
                }
                if ($haveCpr && $haveLicence) {
                    break;
                }
            }
        }

        return view('rental::customer-form', [
            'isEditing' => $this->id !== null,
            // Type is chosen at creation; afterwards only a super-admin may change it.
            'canChangeType' => $this->id === null || $this->isSuperAdmin(),
            'countries' => RentalCustomer::countries(),
            'typeOptions' => RentalCustomer::typeOptions(),
            'customer' => $customer,
            'documents' => $documents,
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
