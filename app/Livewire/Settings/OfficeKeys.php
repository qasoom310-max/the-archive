<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Office\OfficeTokens;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\OfficeToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → AI office: the owner creates and switches off the read-only keys the AI
 * office uses to read this ERP (see App\Http\Middleware\OfficeApi). Owner only: a key
 * reads every company it lists. A new key is shown once and never again.
 */
#[Layout('components.layouts.app')]
#[Title('AI office access')]
final class OfficeKeys extends Component
{
    public string $name = 'AI office';

    /** @var list<int> */
    public array $workspaceIds = [];

    /** The plain key just created, shown once. */
    public ?string $newKey = null;

    public function mount(WorkspaceManager $workspaces): void
    {
        $this->authorizeOwner();
        $this->workspaceIds = $workspaces->all()->map(static fn (Workspace $w): int => (int) $w->id)->values()->all();
    }

    private function authorizeOwner(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, __('Only the owner can manage AI office access.'));
    }

    public function create(OfficeTokens $tokens, ActivityLogger $activity, WorkspaceManager $workspaces): void
    {
        $this->authorizeOwner();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'workspaceIds' => ['required', 'array', 'min:1'],
            'workspaceIds.*' => ['integer'],
        ], ['workspaceIds.required' => __('Tick at least one company the key may read.')]);

        $known = $workspaces->all()->map(static fn (Workspace $w): int => (int) $w->id)->all();
        $ids = array_values(array_intersect(array_map('intval', $this->workspaceIds), $known));
        if ($ids === []) {
            $this->addError('workspaceIds', __('Tick at least one company the key may read.'));

            return;
        }

        [$token, $plain] = $tokens->issue(trim($this->name), $ids);
        $this->newKey = $plain;

        $activity->log('office_key_created', __('AI office access'), __('Created read-only key :name', ['name' => $token->name]));
    }

    public function revoke(int $id, OfficeTokens $tokens, ActivityLogger $activity): void
    {
        $this->authorizeOwner();

        $token = OfficeToken::query()->find($id);
        if (! $token instanceof OfficeToken || $token->isRevoked()) {
            return;
        }

        $tokens->revoke($token);
        $activity->log('office_key_revoked', __('AI office access'), __('Switched off read-only key :name', ['name' => $token->name]));
    }

    public function dismissKey(): void
    {
        $this->newKey = null;
    }

    public function render(WorkspaceManager $workspaces): View
    {
        $all = $workspaces->all();
        $names = $all->mapWithKeys(static fn (Workspace $w): array => [(int) $w->id => $w->name])->all();

        return view('livewire.settings.office-keys', [
            'workspaces' => $all,
            'names' => $names,
            'keys' => OfficeToken::query()->orderByDesc('id')->get(),
        ]);
    }
}
