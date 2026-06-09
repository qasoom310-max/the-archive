<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Enums\ModuleState;
use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\ReportRecipient;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Pos\Services\DailyReport;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
final class Dashboard extends Component
{
    /** New recipient email being added (admin-only form). */
    #[Validate('required|email|max:200')]
    public string $newRecipientEmail = '';

    private function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    private function guardAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }

    /**
     * Whether the POS data the report cards read from exists yet.
     */
    private function posReady(): bool
    {
        return Schema::hasTable('pos_orders') && Schema::hasTable('pos_products');
    }

    public function addRecipient(): void
    {
        $this->guardAdmin();
        $this->validate();

        ReportRecipient::query()->firstOrCreate(
            ['email' => strtolower(trim($this->newRecipientEmail))],
            ['active' => true],
        );

        $this->newRecipientEmail = '';
    }

    public function removeRecipient(int $id): void
    {
        $this->guardAdmin();
        ReportRecipient::query()->whereKey($id)->delete();
    }

    /**
     * Send the report for the night that just closed to the recipient list
     * now — lets an admin verify delivery without waiting for 6 AM.
     */
    public function sendNow(): void
    {
        $this->guardAdmin();

        if (! $this->posReady()) {
            return;
        }

        $count = app(DailyReport::class)->sendLastClosedReport();

        session()->flash(
            'report_sent',
            $count > 0
                ? __('Report sent to :count recipient(s).', ['count' => $count])
                : __('Add at least one recipient first.'),
        );
    }

    public function render(): View
    {
        $isAdmin = $this->isAdmin();

        $dailySales = null;
        $stockSummary = null;
        $periodLabel = null;
        $recipients = [];

        if ($isAdmin && $this->posReady()) {
            $report = app(DailyReport::class);
            [$start, $end] = $report->currentWindow();
            $dailySales = $report->sales($start, $end);
            $stockSummary = $report->stock();
            $periodLabel = $start->isoFormat('MMM D, h:mm A') . ' – ' . $end->isoFormat('MMM D, h:mm A');
            $recipients = ReportRecipient::query()->orderBy('email')->get();
        }

        return view('livewire.pages.dashboard', [
            'ticket' => DemoTicket::query()->first(),
            'appCount' => IrModule::query()
                ->where('application', true)
                ->where('state', ModuleState::Installed)
                ->count(),
            'moduleCount' => IrModule::query()
                ->where('state', ModuleState::Installed)
                ->count(),
            'modelCount' => IrModel::query()->count(),
            'isAdmin' => $isAdmin,
            'dailySales' => $dailySales,
            'stockSummary' => $stockSummary,
            'reportPeriod' => $periodLabel,
            'recipients' => $recipients,
        ]);
    }
}
