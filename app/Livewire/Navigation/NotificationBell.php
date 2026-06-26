<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Notifications\NotificationCenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Top-bar notification bell: aggregates alerts from every installed module via
 * the {@see NotificationCenter} and shows them in a dropdown with a count badge.
 * Polls so the count stays current without a page reload.
 */
final class NotificationBell extends Component
{
    public function render(): View
    {
        $items = app(NotificationCenter::class)->forUser(Auth::user());

        return view('livewire.navigation.notification-bell', [
            'items' => $items,
            'count' => count($items),
        ]);
    }
}
