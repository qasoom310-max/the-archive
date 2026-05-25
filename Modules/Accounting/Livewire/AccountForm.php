<?php

declare(strict_types=1);

namespace Modules\Accounting\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Accounting\Models\Account;

/**
 * Create / edit a Chart-of-Accounts row via the engine FormView.
 */
#[Layout('components.layouts.app')]
#[Title('Account')]
final class AccountForm extends Component
{
    public ?int $id = null;

    public function mount(?int $id = null): void
    {
        $this->id = $id;
    }

    public function render(): View
    {
        $account = $this->id !== null ? Account::query()->find($this->id) : null;

        if ($account !== null) {
            request()->attributes->set('breadcrumb_terminal_label', (string) $account->name);
        }

        return view('accounting::account-form', [
            'account' => $account,
        ]);
    }
}
