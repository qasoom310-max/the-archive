<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The logins a business runs on, kept in one place instead of a spreadsheet.
 *
 * Per database, so each business holds its own and one can never see another's.
 * Per entry, so the bank portal can stay the owner's while the social account
 * is shared with whoever needs to post.
 *
 * Three rules this screen keeps, all of which are easy to lose in a refactor:
 *
 *  - A password is NEVER in the page the browser first receives. It is
 *    fetched one entry at a time, by a deliberate click.
 *  - Every reveal is written to the activity log. If a credential ever leaks,
 *    the question "who looked at it" has an answer.
 *  - Visibility is a QUERY scope, not a view filter. An entry a viewer may not
 *    see never reaches their browser at all.
 */
#[Layout('components.layouts.app')]
#[Title('Saved logins')]
final class Vault extends Component
{
    public string $search = '';

    /**
     * Plaintext for the entries revealed in THIS request, keyed by id.
     *
     * @var array<int, string>
     */
    public array $revealed = [];

    public bool $editing = false;

    /** The entry being edited. Server-set from a click, never a binding. */
    #[Locked]
    public ?int $editingId = null;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public string $url = '';

    #[Validate('nullable|string|max:191')]
    public string $username = '';

    #[Validate('nullable|string|max:255')]
    public string $password = '';

    #[Validate('nullable|string|max:4000')]
    public string $note = '';

    public bool $ownerOnly = true;

    public function mount(): void
    {
        $this->guard();
    }

    /**
     * Admins only, and the owner tier decides what they can see once inside.
     *
     * Re-called by every action: Livewire dispatches straight to a method, so
     * a check that only runs on mount is not a check.
     */
    private function guard(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    private function isOwner(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * Show one password, once, and write down who asked.
     *
     * The entry is re-fetched through the visibility scope rather than trusted
     * from the list the browser was sent, so a crafted id cannot reach an
     * owner-only entry a regular admin was never shown.
     */
    public function reveal(int $id): void
    {
        $this->guard();

        $entry = VaultEntry::query()->visibleTo($this->isOwner())->find($id);

        if ($entry === null || ! $entry->hasPassword()) {
            return;
        }

        $this->revealed[$id] = (string) $entry->password;

        app(ActivityLogger::class)->log('vault_revealed', $entry->name, __('Password revealed'));
    }

    public function hide(int $id): void
    {
        unset($this->revealed[$id]);
    }

    /** Nothing stays on screen once the page is left alone. */
    public function hideAll(): void
    {
        $this->revealed = [];
    }

    public function create(): void
    {
        $this->guard();

        $this->reset(['editingId', 'name', 'url', 'username', 'password', 'note']);
        $this->ownerOnly = true;
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $this->guard();

        $entry = VaultEntry::query()->visibleTo($this->isOwner())->find($id);

        if ($entry === null) {
            return;
        }

        $this->editingId = $entry->id;
        $this->name = $entry->name;
        $this->url = (string) $entry->url;
        $this->username = (string) $entry->username;
        // Deliberately blank: opening the form is not a reveal, and leaving it
        // blank on save keeps whatever password is already stored.
        $this->password = '';
        $this->note = (string) $entry->note;
        $this->ownerOnly = $entry->owner_only;
        $this->resetErrorBag();
        $this->editing = true;
    }

    /** Only the owner may change who else can see an entry. */
    public function save(): void
    {
        $this->guard();
        $this->validate();

        $who = (string) (Auth::user()?->getAttribute('name') ?? '');
        $entry = $this->editingId === null
            ? null
            : VaultEntry::query()->visibleTo($this->isOwner())->find($this->editingId);

        if ($this->editingId !== null && $entry === null) {
            return;
        }

        $entry ??= new VaultEntry(['created_by' => $who]);

        $entry->name = $this->name;
        $entry->url = $this->url === '' ? null : $this->url;
        $entry->username = $this->username === '' ? null : $this->username;
        $entry->note = $this->note === '' ? null : $this->note;
        $entry->updated_by = $who;

        // A blank password box on an existing entry means "leave it alone",
        // so a quick note edit cannot wipe the credential by accident.
        if ($this->password !== '') {
            $entry->password = $this->password;
        }

        // A regular admin can maintain a shared entry but cannot make one
        // private, nor widen one - who sees a credential is the owner's call.
        if ($this->isOwner()) {
            $entry->owner_only = $this->ownerOnly;
        } elseif (! $entry->exists) {
            $entry->owner_only = false;
        }

        $wasNew = ! $entry->exists;
        $entry->save();

        app(ActivityLogger::class)->log(
            $wasNew ? 'vault_created' : 'vault_updated',
            $entry->name,
            $this->password !== '' && ! $wasNew ? __('Password changed') : null,
        );

        $this->editing = false;
        $this->reset(['editingId', 'name', 'url', 'username', 'password', 'note']);
        session()->flash('vault-saved', __('Saved.'));
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->reset(['editingId', 'name', 'url', 'username', 'password', 'note']);
        $this->resetErrorBag();
    }

    public function delete(int $id): void
    {
        $this->guard();

        $entry = VaultEntry::query()->visibleTo($this->isOwner())->find($id);

        if ($entry === null) {
            return;
        }

        $name = $entry->name;
        $entry->delete();
        unset($this->revealed[$id]);

        app(ActivityLogger::class)->log('vault_deleted', $name);
        session()->flash('vault-saved', __('Deleted.'));
    }

    /**
     * A strong password to paste into whatever site is being set up.
     *
     * No symbols: it has to survive being copied into a form, read down a
     * phone line and typed on a keyboard set to another language. Sixteen
     * letters and digits is ample without any of that trouble.
     */
    public function generate(): void
    {
        $this->guard();

        $this->password = Str::password(16, symbols: false);
    }

    public function updatedSearch(): void
    {
        // A revealed password must not survive into a different list.
        $this->revealed = [];
    }

    public function render(): View
    {
        $this->guard();

        $search = trim($this->search);

        $entries = VaultEntry::query()
            ->visibleTo($this->isOwner())
            ->when($search !== '', function ($query) use ($search): void {
                // Only the fields that are not secret: searching a password
                // would mean comparing against decrypted text, and searching a
                // note would leak whether a phrase is in one.
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return view('livewire.pages.vault', [
            'entries' => $entries,
            'isOwner' => $this->isOwner(),
        ]);
    }
}
