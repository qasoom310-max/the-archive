<?php

declare(strict_types=1);

namespace App\Erp\StaffApp;

use App\Erp\Settings\Setting;
use App\Models\AppDeletionRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Taking a request to delete a staff-app account, from the public web page or
 * from inside the app. Nothing is deleted here: the request is kept for an
 * admin (Settings → Users), and the company's email is told, when it has one.
 */
final class AccountDeletion
{
    /**
     * @param  array{name: string, login: string, contact_email?: string|null, note?: string|null, user_id?: int|null, business?: string|null}  $details
     */
    public function request(array $details, string $source, ?string $ip): AppDeletionRequest
    {
        // Asked twice (a double tap, or again from the web page): one request.
        $open = AppDeletionRequest::query()->open()
            ->when(
                ($details['user_id'] ?? null) !== null,
                static fn ($q) => $q->where('user_id', $details['user_id'] ?? null),
                static fn ($q) => $q->where('login', $details['login']),
            )
            ->first();
        if ($open !== null) {
            return $open;
        }

        $request = AppDeletionRequest::query()->create([
            'name' => $details['name'],
            'login' => $details['login'],
            'contact_email' => $details['contact_email'] ?? null,
            'note' => $details['note'] ?? null,
            'source' => $source,
            'user_id' => $details['user_id'] ?? null,
            'business' => $details['business'] ?? null,
            'ip' => $ip,
        ]);

        $this->tellTheCompany($request);

        return $request;
    }

    /** An email to the company's address, after the page has answered. */
    private function tellTheCompany(AppDeletionRequest $request): void
    {
        $to = Setting::get('company.email');
        if (! is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $lines = [
            'Someone has asked for their Wanaan Staff app account to be deleted.',
            '',
            'Name: ' . $request->name,
            'Signs in as: ' . $request->login,
            'Confirm to: ' . ($request->contact_email ?? $request->login),
            'Business: ' . ($request->business ?? '—'),
            'Asked from: ' . ($request->source === AppDeletionRequest::SOURCE_APP ? 'the app' : 'the web page'),
            'Note: ' . ($request->note ?? '—'),
            '',
            'It is listed in the ERP under Settings → Users. The privacy policy promises it is done within 30 days.',
        ];

        dispatch(static function () use ($to, $lines): void {
            try {
                Mail::raw(implode("\n", $lines), static function ($message) use ($to): void {
                    $message->to($to)->subject('Staff app: account deletion request');
                });
            } catch (Throwable $e) {
                Log::warning('Staff-app deletion request email failed', ['error' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
