<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Notifications\VerifyNewEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Profile settings page — the logged-in user edits their own record.
 *
 * Field semantics:
 *   - Name / Avatar: written through immediately on save.
 *   - Email: NOT written through. A pending change parks in `new_email`
 *     and a signed-URL verification email goes to the new address
 *     ({@see VerifyNewEmail}). The swap to `email` happens only after
 *     the user clicks the link.
 *   - Password: optional. If `currentPassword` matches and `newPassword`
 *     + `newPasswordConfirmation` agree, the hash is updated.
 *   - Role: display-only. The form field is disabled so the user can
 *     read but not change. Role edits live in the admin User Resource
 *     (gated by is_admin); they're not part of self-service.
 */
#[Layout('components.layouts.app')]
#[Title('Profile')]
final class ProfilePage extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $avatar = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /** Latest success flash to render at the top of the page. */
    public ?string $flash = null;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->email = $user->email ?? '';
        // Carry over the session flash from the verification controller
        // (which redirects here with a `flash` value) so the success
        // message renders inline.
        $sessionFlash = session('flash');
        $this->flash = is_string($sessionFlash) ? $sessionFlash : null;
    }

    private function user(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    public function save(): void
    {
        $user = $this->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => [
                'nullable',
                'email',
                'max:200',
                // Skip the uniqueness check on the user's own current row
                // (saving without changing email mustn't trip "already taken").
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::unique('users', 'new_email')->ignore($user->id),
            ],
            // 4 MB cap matches the product image upload controller; .png
            // / .jpg / .webp / .gif covers what a browser's <input
            // type=file> will produce from any reasonable source.
            'avatar' => ['nullable', 'image', 'max:4096', 'mimes:png,jpg,jpeg,webp,gif'],
            'currentPassword' => ['nullable', 'string'],
            // Printable-ASCII whitelist mirrors the client-side
            // `beforeinput` filter on the password inputs — defence in
            // depth for JS-disabled clients and crafted payloads. Arabic
            // (or any non-Latin) character is rejected with a localised
            // message instead of a generic "format is invalid".
            'newPassword' => ['nullable', 'string', 'min:8', 'regex:/^[\x20-\x7E]*$/', 'confirmed:newPasswordConfirmation'],
        ], [
            'newPassword.regex' => __('Password may only contain English letters, digits, and symbols.'),
        ]);

        $user->name = trim($validated['name']);

        // ─── Avatar ─────────────────────────────────────────────────
        if ($this->avatar instanceof UploadedFile) {
            // Delete the previous file so we don't leave orphans on disk
            // every time a user re-uploads. Cheap idempotent op — disk
            // call no-ops if the file is already gone.
            if ($user->avatar_path !== null && $user->avatar_path !== '') {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $path = $this->avatar->store('avatars', 'public');
            $user->avatar_path = is_string($path) ? $path : null;
            $this->avatar = null;
        }

        // ─── Email (deferred — parks in new_email + verification mail) ────
        $newEmail = isset($validated['email']) && is_string($validated['email'])
            ? strtolower(trim($validated['email']))
            : null;

        $emailChanged = $newEmail !== null
            && $newEmail !== ''
            && $newEmail !== strtolower((string) ($user->email ?? ''));

        if ($emailChanged) {
            $user->new_email = $newEmail;
            $user->save();

            // Route to the pending address directly (anonymous notifiable)
            // — the default routing on $user would send to the OLD email.
            Notification::route('mail', $newEmail)
                ->notify(VerifyNewEmail::for($user, $newEmail));

            $this->flash = (string) __("We've sent a verification link to :email. Your email won't change until you click it.", ['email' => $newEmail]);
        } else {
            $user->save();
            $this->flash = (string) __('Profile updated.');
        }

        // ─── Password (optional) ────────────────────────────────────
        if ($this->newPassword !== '') {
            if ($this->currentPassword === '' || ! Hash::check($this->currentPassword, $user->password)) {
                $this->addError('currentPassword', 'Current password is incorrect.');

                return;
            }

            $user->password = Hash::make($this->newPassword);
            $user->save();

            $this->currentPassword = '';
            $this->newPassword = '';
            $this->newPasswordConfirmation = '';

            // The email branches above always set $this->flash, so PHPStan
            // narrows it to non-null here; just concatenate.
            $this->flash = $this->flash . ' ' . (string) __('Password changed.');
        }
    }

    /**
     * Cancel a pending email change without going through verification.
     * Useful if the user typed the wrong address and wants to retry.
     */
    public function cancelPendingEmailChange(): void
    {
        $user = $this->user();

        if ($user->new_email === null) {
            return;
        }

        $user->new_email = null;
        $user->save();

        $this->flash = (string) __('Pending email change cancelled.');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.profile-page', [
            'user' => $user,
            // Goes through User::avatarUrl() so a stale avatar_path
            // pointing at a missing file falls back to null (initial
            // letter) instead of rendering a broken-image icon.
            'avatarUrl' => $user->avatarUrl(),
            'roleLabel' => $user->roleLabel(),
            'pendingEmail' => $user->new_email,
        ]);
    }
}
