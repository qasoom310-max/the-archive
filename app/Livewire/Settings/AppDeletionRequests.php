<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AppDeletionRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

/**
 * Staff-app account deletion requests still waiting — on top of Settings →
 * Users, where the account itself is deleted. Marking one done only closes the
 * request; deleting the account is the admin's own step, as for any user.
 * Empty (and invisible) when nothing is waiting.
 */
final class AppDeletionRequests extends Component
{
    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function markDone(int $id): void
    {
        $this->authorizeAdmin();

        $request = AppDeletionRequest::query()->open()->find($id);
        if ($request === null) {
            return;
        }

        $user = Auth::user();
        $request->forceFill([
            'handled_at' => Carbon::now(),
            'handled_by' => $user instanceof User ? $user->name : null,
        ])->save();
    }

    public function render(): View
    {
        $requests = Schema::connection(Workspace::$landlordConnection)->hasTable('app_deletion_requests')
            ? AppDeletionRequest::query()->open()->latest()->limit(50)->get()
            : collect();

        return view('livewire.settings.app-deletion-requests', ['requests' => $requests]);
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }
}
