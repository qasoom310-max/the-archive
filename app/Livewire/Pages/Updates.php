<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Updates\UpdateEntry;
use App\Erp\Updates\UpdateLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "What's new" — every change made to the system, newest first, for everyone
 * who is signed in. No permission beyond being signed in: it is news, and the
 * people at the desk are the ones who most need to know a button moved.
 *
 * The entries are files in the repository ({@see UpdateLog}); this page only
 * reads them, so there is nothing here to edit, save or delete.
 */
#[Layout('components.layouts.app')]
#[Title('What\'s new')]
final class Updates extends Component
{
    /** How many entries a "Show older updates" press adds. */
    private const STEP = 30;

    #[Url(except: '')]
    public string $app = '';

    #[Url(except: '')]
    public string $search = '';

    public int $limit = self::STEP;

    public function mount(): void
    {
        // The breadcrumb would otherwise print the address segment, in English.
        request()->attributes->set('breadcrumb_terminal_label', __("What's new"));
    }

    public function setApp(string $app): void
    {
        $this->app = in_array($app, UpdateLog::APPS, true) ? $app : '';
        $this->limit = self::STEP;
    }

    public function updatedSearch(): void
    {
        $this->limit = self::STEP;
    }

    public function showMore(): void
    {
        $this->limit += self::STEP;
    }

    /**
     * The name each app goes by on screen.
     *
     * @return array<string, string>
     */
    public static function appLabels(): array
    {
        return [
            'system' => __('System'),
            'limousine' => __('Limousine'),
            'rental' => __('Rent A Car'),
            'pos' => __('Point of Sale'),
            'accounting' => __('Accounting'),
            'purchases' => __('Purchases'),
            'inventory' => __('Inventory'),
            'contacts' => __('Contacts'),
            'project' => __('Projects'),
            'whatsapp' => __('WhatsApp'),
            'website' => __('Website'),
        ];
    }

    public function render(UpdateLog $log): View
    {
        $all = $log->all();

        // Only the apps that have news get a filter button.
        $used = array_values(array_unique(array_map(static fn (UpdateEntry $e): string => $e->app, $all)));
        $apps = array_values(array_filter(UpdateLog::APPS, static fn (string $a): bool => in_array($a, $used, true)));

        // The address bar can carry anything; an app nobody knows means "all".
        $app = in_array($this->app, UpdateLog::APPS, true) ? $this->app : '';

        $matching = array_values(array_filter($all, fn (UpdateEntry $e): bool => ($app === '' || $e->app === $app) && $e->matches($this->search)));

        $shown = array_slice($matching, 0, max(self::STEP, $this->limit));

        // One heading per day.
        $days = [];
        foreach ($shown as $entry) {
            $days[$entry->date->format('Y-m-d')][] = $entry;
        }

        return view('livewire.pages.updates', [
            'days' => $days,
            'apps' => $apps,
            'activeApp' => $app,
            'labels' => self::appLabels(),
            'total' => count($matching),
            'remaining' => count($matching) - count($shown),
            'latestKey' => $log->latestKey(),
            'locale' => app()->getLocale(),
        ]);
    }
}
