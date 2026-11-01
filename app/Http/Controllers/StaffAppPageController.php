<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\StaffApp\AccountDeletion;
use App\Models\AppDeletionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The staff app's public account-deletion form (Google Play: it must work
 * for someone who no longer has the app).
 */
final class StaffAppPageController
{
    public function requestDeletion(Request $request, AccountDeletion $deletion): RedirectResponse
    {
        $done = redirect()->route('staff-app.account-deletion')->with('deletion_requested', true);

        // A field people never see: a robot filling in everything is thanked
        // and nothing is kept.
        if ($request->string('website')->trim()->isNotEmpty()) {
            return $done;
        }

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'login' => ['required', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $contact = $request->string('contact_email')->trim()->toString();
        $note = $request->string('note')->trim()->toString();

        $deletion->request([
            'name' => $request->string('name')->trim()->toString(),
            'login' => $request->string('login')->trim()->toString(),
            'contact_email' => $contact !== '' ? $contact : null,
            'note' => $note !== '' ? $note : null,
        ], AppDeletionRequest::SOURCE_WEB, $request->ip());

        return $done;
    }
}
