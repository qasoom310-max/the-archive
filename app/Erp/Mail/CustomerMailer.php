<?php

declare(strict_types=1);

namespace App\Erp\Mail;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Settings\Setting;
use App\Erp\Tenancy\WorkspaceManager;
use App\Mail\CustomerDocumentMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emails quotations, invoices and receipts to customers.
 *
 * Per database and OFF unless `mail.customer_documents` is on (Settings →
 * General). Every email carries the company logo as its header and, when
 * `company.customer_terms` holds anything, a terms PDF built from it.
 *
 * Sending happens AFTER the response ({@see later()}): rendering three PDFs
 * and talking to the mail server must not keep the employee waiting on Save,
 * and a mail failure must never undo or block the document itself. Failures
 * are reported and written to the activity log, never thrown.
 */
final class CustomerMailer
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function enabled(): bool
    {
        return Setting::get('mail.customer_documents') === true;
    }

    /**
     * Run $send once the response has gone out — only when customer emails are
     * switched on for this database. The closure should re-read its records
     * (by id), since it runs after the request's own work has finished.
     *
     * $now runs it straight away instead, for a caller that is ITSELF already
     * running after the response (the WhatsApp assistant): work deferred from
     * inside deferred work is never run.
     *
     * @param  Closure(): void  $send
     */
    public function later(Closure $send, bool $now = false): void
    {
        if (! $this->enabled()) {
            return;
        }

        // The deferred work runs after the request, by which time a caller that
        // only borrowed a database (the online-payment callback runs inside
        // WorkspaceManager::runFor) has handed it back. So remember which
        // database this was, and go back into it.
        $tenantPath = DB::getDefaultConnection() === 'tenant'
            ? config('database.connections.tenant.database')
            : null;

        $job = static function () use ($send, $tenantPath, $now): void {
            // In English whatever language the employee works in: the email and
            // its PDFs go to the customer, not to the person who pressed Save.
            $locale = app()->getLocale();
            app()->setLocale('en');

            try {
                if ($now) {
                    $send();

                    return;
                }

                $workspaces = app(WorkspaceManager::class);
                is_string($tenantPath) && $tenantPath !== ''
                    ? $workspaces->withTenant($tenantPath, $send)
                    : $workspaces->withMain($send);
            } catch (Throwable $e) {
                report($e);
            } finally {
                app()->setLocale($locale);
            }
        };

        $now ? $job() : defer($job);
    }

    /**
     * Send one document email. Returns false (and sends nothing) when there is
     * no usable address; a failure is reported and logged, never thrown.
     */
    public function send(?string $to, CustomerDocument $document): bool
    {
        $to = trim((string) $to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $attachments = $document->attachments;
        $terms = $this->termsPdf();
        if ($terms !== null) {
            $attachments[] = $terms;
        }

        try {
            Mail::to($to)->send(new CustomerDocumentMail(
                document: $document,
                attachmentFiles: $attachments,
                companyName: $this->companyName(),
                fromAddress: $this->fromAddress(),
                logo: $this->logo(),
            ));
        } catch (Throwable $e) {
            report($e);
            $this->activity->log('updated', $document->label, 'Email to ' . $to . ' failed: ' . Str::limit($e->getMessage(), 150));

            return false;
        }

        $this->activity->log('emailed', $document->label, 'Emailed to ' . $to);

        return true;
    }

    public function companyName(): string
    {
        $name = Setting::get('company.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name');
    }

    /** The From address set for this database, or null to use the server's default. */
    private function fromAddress(): ?string
    {
        $from = trim((string) Setting::get('mail.from_address', ''));

        return filter_var($from, FILTER_VALIDATE_EMAIL) !== false ? $from : null;
    }

    /**
     * The terms PDF, or null when this database has no terms written.
     *
     * @return array{data: string, name: string}|null
     */
    public function termsPdf(): ?array
    {
        $terms = trim((string) Setting::get('company.customer_terms', ''));
        if ($terms === '') {
            return null;
        }

        $logo = $this->logo();

        $data = Pdf::loadView('pdf.customer-terms', [
            'companyName' => $this->companyName(),
            'html' => Str::markdown($terms, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'logoSrc' => $logo !== null ? 'data:' . $logo['mime'] . ';base64,' . base64_encode($logo['data']) : null,
        ])->setPaper('a4')->output();

        return ['data' => $data, 'name' => 'Terms-and-Conditions.pdf'];
    }

    /**
     * The company logo as PNG/JPEG bytes. WebP and AVIF are converted to PNG
     * when the server can, because several mail programs (Outlook among them)
     * show nothing for them.
     *
     * @return array{data: string, mime: string, name: string}|null
     */
    public function logo(): ?array
    {
        $rel = Setting::get('company.logo');
        if (! is_string($rel) || $rel === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($rel)) {
            return null;
        }

        $data = (string) $disk->get($rel);
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');

            return ['data' => $data, 'mime' => $mime, 'name' => 'logo.' . $ext];
        }

        $image = null;
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromstring($data);
        } elseif ($ext === 'avif' && function_exists('imagecreatefromavif')) {
            $image = @imagecreatefromstring($data);
        }

        if ($image === false || $image === null) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png === '' ? null : ['data' => $png, 'mime' => 'image/png', 'name' => 'logo.png'];
    }
}
