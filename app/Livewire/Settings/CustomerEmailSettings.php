<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Erp\Mail\CustomerDocument;
use App\Erp\Mail\CustomerMailer;
use App\Erp\Mail\EmailTemplates;
use App\Erp\Settings\Setting;
use App\Mail\CustomerDocumentMail;
use App\Models\Ir\IrModule;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Settings → Customer emails: everything about the emails customers receive,
 * on one page — whether they go out, the address they come from, the terms
 * PDF, the look and signature shared by all of them, and the wording of each
 * kind — with a live preview of the email being edited.
 *
 * The editor holds the full text (defaults filled in), so the admin edits real
 * wording rather than a blank box; {@see EmailTemplates::save()} stores only
 * what differs from the default. Admin-only, per database.
 */
#[Layout('components.layouts.app')]
#[Title('Customer emails')]
final class CustomerEmailSettings extends Component
{
    public bool $enabled = false;

    public string $fromAddress = '';

    public string $terms = '';

    /** @var array<string, string> */
    public array $layout = [];

    /** @var array<string, array<string, string>> */
    public array $types = [];

    /** 'design', 'delivery' or an email kind from EmailTemplates::TYPES. */
    public string $tab = 'design';

    /** Which email the preview shows while the Design tab is open. */
    public string $previewType = 'quotation';

    public string $testEmail = '';

    /** Said under the test box once a test email went out. */
    public string $testSent = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->load();

        $user = Auth::user();
        $this->testEmail = $user instanceof User ? (string) $user->email : '';
        $this->previewType = array_key_first($this->visibleTypes()) ?? 'quotation';
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $this->validate($this->rules());

        $templates = app(EmailTemplates::class);
        $templates->save($this->layout, $this->types);
        Setting::setMany([
            'mail.customer_documents' => $this->enabled,
            'mail.from_address' => trim($this->fromAddress),
            'company.customer_terms' => trim($this->terms),
        ]);

        app(ActivityLogger::class)->log('settings_updated', 'Customer emails', 'Customer email templates saved');
        $this->dispatch('record-saved');
        $this->load();
    }

    /** Put one email (or the shared design) back to the wording it shipped with. */
    public function resetToDefault(string $what): void
    {
        $this->authorizeAdmin();
        $templates = app(EmailTemplates::class);

        if ($what === 'design') {
            $this->layout = EmailTemplates::LAYOUT_DEFAULTS;

            return;
        }

        if (isset(EmailTemplates::TYPES[$what])) {
            foreach ($templates->editableFields($what) as $field) {
                $this->types[$what][$field] = $templates->defaultFor($what, $field);
            }
        }
    }

    public function openTab(string $tab): void
    {
        if ($tab === 'design' || $tab === 'delivery' || isset($this->visibleTypes()[$tab])) {
            $this->tab = $tab;
            if (isset(EmailTemplates::TYPES[$tab])) {
                $this->previewType = $tab;
            }
        }
    }

    /** Save, then send the email being previewed to the given address, filled with sample details. */
    public function sendTest(): void
    {
        $this->authorizeAdmin();
        $this->testSent = '';
        $this->validate(['testEmail' => ['required', 'email', 'max:200']]);
        $this->save();

        $mailer = app(CustomerMailer::class);
        $document = $this->sample($this->previewType, null);

        try {
            Mail::to(trim($this->testEmail))->send(new CustomerDocumentMail(
                document: $document,
                attachmentFiles: [],
                companyName: $mailer->companyName(),
                fromAddress: $mailer->fromAddress(),
                logo: $mailer->logo(),
            ));
        } catch (Throwable $e) {
            report($e);
            $this->addError('testEmail', __('Could not send: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $this->testSent = __('Test email sent to :email.', ['email' => trim($this->testEmail)]);
    }

    public function render(): View
    {
        $visible = $this->visibleTypes();
        if (! isset($visible[$this->previewType])) {
            $this->previewType = array_key_first($visible) ?? 'quotation';
        }

        return view('livewire.settings.customer-email-settings', [
            'kinds' => $visible,
            'templates' => app(EmailTemplates::class),
            'previewHtml' => $this->previewHtml(),
            'previewSubject' => $this->sample($this->previewType, $this->types[$this->previewType] ?? [])->subject,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $text = ['nullable', 'string', 'max:5000'];
        $rules = [
            'fromAddress' => ['nullable', 'email', 'max:200'],
            'terms' => ['nullable', 'string', 'max:50000'],
            'layout.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'layout.footer_bg' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'layout.show_logo' => ['nullable', 'in:0,1'],
        ];
        foreach (['greeting', 'closing', 'signature', 'footer', 'small_print'] as $field) {
            $rules['layout.' . $field] = $text;
        }
        foreach (EmailTemplates::TYPES as $type => $def) {
            foreach (app(EmailTemplates::class)->editableFields($type) as $field) {
                $rules['types.' . $type . '.' . $field] = in_array($field, ['subject', 'heading', 'button'], true)
                    ? ['nullable', 'string', 'max:250']
                    : $text;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'layout.accent' => __('Accent colour'),
            'layout.footer_bg' => __('Footer colour'),
            'fromAddress' => __('Send from'),
        ];
    }

    private function load(): void
    {
        $templates = app(EmailTemplates::class);

        $this->enabled = Setting::get('mail.customer_documents') === true;
        $this->fromAddress = trim((string) Setting::get('mail.from_address', ''));
        $this->terms = (string) Setting::get('company.customer_terms', '');
        $this->layout = $templates->layout();

        $this->types = [];
        foreach (array_keys(EmailTemplates::TYPES) as $type) {
            $this->types[$type] = $templates->template($type);
        }
    }

    /**
     * The kinds of email this database actually sends: Limousine's only where
     * Limousine runs, Rent A Car's only where it runs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function visibleTypes(): array
    {
        $apps = [];
        foreach (['limousine', 'rental'] as $app) {
            $installed = IrModule::query()->where('name', $app)->where('state', ModuleState::Installed)->exists();
            if ($installed && Features::moduleAllowed($app)) {
                $apps[] = $app;
            }
        }

        $out = [];
        foreach (EmailTemplates::TYPES as $type => $def) {
            if ($apps === [] || array_intersect($def['apps'], $apps) !== []) {
                $out[$type] = $def;
            }
        }

        return $out;
    }

    private function previewHtml(): string
    {
        $mailer = app(CustomerMailer::class);
        $logo = $mailer->logo();

        try {
            return view('emails.customer-document', [
                'document' => $this->sample($this->previewType, $this->types[$this->previewType] ?? []),
                'companyName' => $mailer->companyName(),
                'logo' => null,
                'logoSrc' => $logo !== null ? 'data:' . $logo['mime'] . ';base64,' . base64_encode($logo['data']) : null,
                'layout' => app(EmailTemplates::class)->mergeLayout($this->layout),
                'attachmentNames' => $this->sampleAttachments($this->previewType),
            ])->render();
        } catch (Throwable $e) {
            report($e);

            return '<p style="font-family:sans-serif;color:#b91c1c;padding:16px;">' . e(__('The preview could not be drawn.')) . '</p>';
        }
    }

    /**
     * One kind of email filled with made-up details, for the preview and the
     * test email. $over is the editor's unsaved wording (null = what is saved).
     *
     * @param  array<string, string>|null  $over
     */
    private function sample(string $type, ?array $over): CustomerDocument
    {
        [$vars, $facts, $callout, $button] = $this->sampleData($type);
        $mail = app(EmailTemplates::class)->compose($type, ['customer' => 'Ahmed Al Khalifa', ...$vars], $over);

        return new CustomerDocument(
            subject: $mail['subject'],
            heading: $mail['heading'],
            customerName: 'Ahmed Al Khalifa',
            paragraphs: $mail['intro'],
            facts: $facts,
            attachments: [],
            label: 'Test email',
            withTerms: false,
            outro: $mail['outro'],
            callout: $callout,
            button: $button !== null ? ['label' => $mail['button'] !== '' ? $mail['button'] : 'Open', 'url' => $button] : null,
        );
    }

    /**
     * @return array{0: array<string, string>, 1: list<array{0: string, 1: string}>, 2: array{title: string, lines: list<string>}|null, 3: string|null}
     */
    private function sampleData(string $type): array
    {
        return match ($type) {
            'quotation' => [
                ['reference' => 'QT/01695', 'total' => '120.00 BD', 'valid_until' => '30 Nov 2026'],
                [['Quotation no.', 'QT/01695'], ['Date', '16 Nov 2026'], ['Valid until', '30 Nov 2026'], ['Total', '120.00 BD']],
                null, null,
            ],
            'invoice' => [
                ['reference' => 'INV/01932', 'total' => '240.00 BD', 'paid' => '100.00 BD', 'balance' => '140.00 BD', 'payment_state' => 'part'],
                [['Invoice no.', 'INV/01932'], ['Booking', 'BK/15329'], ['Total', '240.00 BD'], ['Paid', '100.00 BD'], ['Balance due', '140.00 BD']],
                null, null,
            ],
            'receipt' => [
                ['reference' => 'RCP/26056', 'amount' => '100.00 BD', 'invoice' => 'INV/01932', 'balance' => '140.00 BD'],
                [['Receipt no.', 'RCP/26056'], ['Amount received', '100.00 BD'], ['Invoice no.', 'INV/01932'], ['Balance due', '140.00 BD']],
                null, null,
            ],
            'driver_assigned' => [
                ['reference' => '25512', 'pickup_time' => 'Thu 5 Nov 2026, 08:30', 'driver' => 'Habib Rahman', 'driver_phone' => '+973 3999 1830', 'car' => 'GMC Yukon (Black)', 'plate' => '363899'],
                [['Trip no.', '25512'], ['Pick-up', 'Thu 5 Nov 2026, 08:30'], ['Pick up', 'Bahrain International Airport'], ['Drop off', 'Four Seasons Hotel, Bahrain Bay'], ['Driver', 'Habib Rahman'], ["Driver's phone", '+973 3999 1830'], ['Car', 'GMC Yukon (Black)'], ['Plate no.', '363899']],
                null, null,
            ],
            'driver_on_the_way' => [
                ['reference' => '25512', 'pickup_time' => 'Thu 5 Nov 2026, 08:30', 'driver' => 'Habib Rahman', 'driver_phone' => '+973 3999 1830', 'car' => 'GMC Yukon (Black)', 'plate' => '363899'],
                [['Trip no.', '25512'], ['Pick-up', 'Thu 5 Nov 2026, 08:30'], ['Driver', 'Habib Rahman'], ["Driver's phone", '+973 3999 1830'], ['Car', 'GMC Yukon (Black)'], ['Plate no.', '363899']],
                null, null,
            ],
            'balance_reminder' => [
                ['reference' => '25512', 'booking' => 'BK/15329', 'pickup_time' => 'Thu 5 Nov 2026, 08:30', 'total' => '40.00 BD', 'paid' => '8.00 BD', 'balance' => '32.00 BD'],
                [['Trip no.', '25512'], ['Pick-up', 'Thu 5 Nov 2026, 08:30'], ['Pick up', 'Bahrain International Airport'], ['Drop off', 'Four Seasons Hotel, Bahrain Bay'], ['Total', '40.00 BD'], ['Paid', '8.00 BD'], ['Balance due', '32.00 BD']],
                null, null,
            ],
            'coupon' => [
                ['code' => 'WAN-7K2Q9', 'value' => '45.00 BD', 'valid_until' => '05-May-2027'],
                [],
                ['title' => 'WAN-7K2Q9', 'lines' => ['Value remaining: 45.00 BD', 'Valid until 05-May-2027']],
                null,
            ],
            'service_order' => [
                ['reference' => '25512'],
                [['Service date', '05-Nov-2026 08:30 AM'], ['Pick up', 'Bahrain International Airport'], ['Drop off', 'Four Seasons Hotel'], ['Vehicle', 'GMC Yukon'], ["Driver's Name", 'Habib Rahman']],
                null,
                url('/service-order/sample'),
            ],
            'service_order_company' => [
                ['reference' => '25512'],
                [['Service date', '05-Nov-2026 08:30 AM'], ['Pick up', 'Bahrain International Airport'], ['Drop off', 'Four Seasons Hotel'], ['Vehicle', 'GMC Yukon'], ["Driver's Name", 'Habib Rahman']],
                null, null,
            ],
            'agreement' => [
                ['reference' => 'RO/01677', 'car' => 'Nissan Sunny 2024', 'pickup_date' => '05-11-2026', 'return_date' => '12-11-2026'],
                [['Agreement no.', 'RO/01677'], ['Car', 'Nissan Sunny 2024'], ['Pick-up date', '05-11-2026'], ['Return date', '12-11-2026']],
                null, null,
            ],
            default => [[], [], null, null],
        };
    }

    /**
     * @return list<string>
     */
    private function sampleAttachments(string $type): array
    {
        $files = match ($type) {
            'quotation' => ['quotation-QT-01695.pdf'],
            'invoice' => ['invoice-INV-01932.pdf', 'receipt-RCP-26056.pdf'],
            'receipt' => ['receipt-RCP-26056.pdf', 'invoice-INV-01932.pdf'],
            'coupon' => ['coupon-WAN-7K2Q9.pdf'],
            'agreement' => ['agreement-ro01677.pdf'],
            default => [],
        };

        // The automatic emails add the terms PDF when terms are written.
        if (in_array($type, ['quotation', 'invoice', 'receipt'], true) && trim($this->terms) !== '') {
            $files[] = 'Terms-and-Conditions.pdf';
        }

        return $files;
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }
}
