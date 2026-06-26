<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Shared "is the current user an admin?" check for Livewire page components
 * that gate themselves (Dashboard, Settings, Workspaces). Pairs with
 * `abort_unless($this->isAdmin(), 403)` in mount().
 */
trait HasAdminCheck
{
    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }
}
