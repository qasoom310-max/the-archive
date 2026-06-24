<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoReceipt;

#[Layout('components.layouts.app')]
#[Title('Receipts')]
final class Receipts extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = LimoReceipt::query()
            ->with(['customer:id,name', 'invoice:id,reference'])
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($c) use ($term): void {
                        $c->where('name', 'like', "%{$term}%");
                    });
            });
        }

        return view('limousine::receipts', [
            'receipts' => $query->paginate(20),
            'collectedTotal' => (float) LimoReceipt::query()->sum('amount'),
        ]);
    }
}
