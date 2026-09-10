<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Targets\RevenueTargets;
use App\Erp\Views\ValueFormat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The owner's monthly + yearly revenue goals on an app dashboard.
 *
 * Both the figures and the ability to change them are SUPER-ADMIN ONLY. A
 * target box states a percentage of the whole business's income, which gives
 * the income away to anyone who can do the arithmetic - so gating the revenue
 * card while leaving "68% of 40,000" on screen beside it would gate nothing.
 *
 * The host component supplies {@see targetsApp()} ('rental' or 'limousine').
 */
trait EditsRevenueTargets
{
    public bool $editingTargets = false;

    /** Free text while editing: blank is a real answer meaning "no target". */
    public string $targetMonthly = '';

    public string $targetYearly = '';

    /** Which app's targets this dashboard owns. */
    abstract protected function targetsApp(): string;

    public function openTargets(): void
    {
        $this->guardTargets();

        $targets = app(RevenueTargets::class);
        $app = $this->targetsApp();

        $this->targetMonthly = $this->asInput($targets->monthly($app));
        $this->targetYearly = $this->asInput($targets->yearly($app));
        $this->resetErrorBag();
        $this->editingTargets = true;
    }

    public function closeTargets(): void
    {
        $this->editingTargets = false;
        $this->resetErrorBag();
    }

    public function saveTargets(): void
    {
        // Re-checked here, not only in openTargets(): Livewire dispatches
        // straight to a method, so a mount-time gate is not a gate.
        $this->guardTargets();

        $this->validate([
            'targetMonthly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'targetYearly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [], [
            'targetMonthly' => __('Monthly target'),
            'targetYearly' => __('Yearly target'),
        ]);

        $app = $this->targetsApp();
        $monthly = $this->asFloat($this->targetMonthly);
        $yearly = $this->asFloat($this->targetYearly);

        app(RevenueTargets::class)->set($app, $monthly, $yearly);

        app(ActivityLogger::class)->log(
            'settings_updated',
            __('Revenue targets'),
            sprintf(
                '%s - %s: %s, %s: %s',
                $app,
                __('Monthly target'),
                $monthly === null ? __('Not set') : ValueFormat::money($monthly),
                __('Yearly target'),
                $yearly === null ? __('Not set') : ValueFormat::money($yearly),
            ),
        );

        $this->editingTargets = false;
        session()->flash('targets-saved', __('Targets saved.'));
    }

    /** Whether the viewer is the owner - drives every target box in the view. */
    protected function viewerIsSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    private function guardTargets(): void
    {
        abort_unless($this->viewerIsSuperAdmin(), 403);
    }

    /** A cleared box means "no target", so an empty string round-trips to null. */
    private function asFloat(string $value): ?float
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value > 0.0 ? (float) $value : null;
    }

    private function asInput(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
