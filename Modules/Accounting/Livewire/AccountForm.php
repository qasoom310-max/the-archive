<?php

declare(strict_types=1);

namespace Modules\Accounting\Livewire;

use App\Livewire\Concerns\EditsRecordViaFormView;
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
    use EditsRecordViaFormView;

    protected function formModel(): string
    {
        return Account::class;
    }

    protected function formView(): string
    {
        return 'accounting::account-form';
    }

    protected function formVar(): string
    {
        return 'account';
    }
}
