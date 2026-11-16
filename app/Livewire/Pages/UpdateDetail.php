<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Updates\UpdateLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One "What's new" entry in full: what changed and why, the before and after
 * pictures, and the step-by-step guide. Open to everyone signed in, like the
 * list it hangs off.
 */
#[Layout('components.layouts.app')]
#[Title('What\'s new')]
final class UpdateDetail extends Component
{
    #[Locked]
    public string $key = '';

    public function mount(UpdateLog $log, string $key): void
    {
        $entry = $log->find($key);
        abort_if($entry === null, 404);

        $this->key = $key;

        // The breadcrumb would otherwise print the file name.
        request()->attributes->set('breadcrumb_terminal_label', $entry->titleIn(app()->getLocale()));
    }

    public function render(UpdateLog $log): View
    {
        $entry = $log->find($this->key);
        abort_if($entry === null, 404);

        return view('livewire.pages.update-detail', [
            'entry' => $entry,
            'labels' => Updates::appLabels(),
            'locale' => app()->getLocale(),
        ]);
    }
}
