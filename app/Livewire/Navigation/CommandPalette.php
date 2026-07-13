<?php

declare(strict_types=1);

namespace App\Livewire\Navigation;

use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Models\Ir\IrModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Global ⌘K / Ctrl+K command palette: fuzzy-jump to any app this database
 * exposes, or a navigation target. Designed to grow into record search &
 * server actions.
 */
final class CommandPalette extends Component
{
    public string $query = '';

    /**
     * The searchable corpus (apps + core navigation targets).
     *
     * @return list<array{label: string, group: string, hint: string, url: string}>
     */
    private function corpus(): array
    {
        $items = [[
            'label' => 'Dashboard',
            'group' => 'Navigation',
            'hint' => 'Home',
            'url' => url('/'),
        ]];

        // Only the apps this database's business type exposes — the same gate as
        // the top app bar. Otherwise ⌘K would happily jump you into an app the
        // database doesn't run (e.g. Rent A Car in a perfume shop).
        $apps = IrModule::query()
            ->where('application', true)
            ->where('state', ModuleState::Installed)
            ->orderBy('sequence')
            ->get()
            ->filter(static fn (IrModule $app): bool => Features::moduleAllowed((string) $app->name));

        foreach ($apps as $app) {
            $items[] = [
                'label' => $app->display_name,
                'group' => 'Apps',
                'hint' => $app->summary ?? 'Open application',
                'url' => url('/app/' . $app->name),
            ];
        }

        return $items;
    }

    /**
     * Subsequence fuzzy match: every query char must appear in order.
     * Lower score = better (earlier first match, tighter span).
     */
    private function score(string $haystack, string $needle): ?int
    {
        if ($needle === '') {
            return 0;
        }

        $haystack = Str::lower($haystack);
        $needle = Str::lower($needle);

        $pos = -1;
        $first = null;

        foreach (str_split($needle) as $char) {
            $found = strpos($haystack, $char, $pos + 1);

            if ($found === false) {
                return null;
            }

            $first ??= $found;
            $pos = $found;
        }

        return ($first ?? 0) * 100 + ($pos - ($first ?? 0));
    }

    /**
     * @return list<array{label: string, group: string, hint: string, url: string}>
     */
    private function results(): array
    {
        $scored = [];

        foreach ($this->corpus() as $item) {
            $score = $this->score($item['label'] . ' ' . $item['group'], trim($this->query));

            if ($score !== null) {
                $scored[] = ['score' => $score, 'item' => $item];
            }
        }

        usort($scored, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        return array_map(fn (array $row): array => $row['item'], array_slice($scored, 0, 8));
    }

    public function render(): View
    {
        return view('livewire.navigation.command-palette', [
            'results' => $this->results(),
        ]);
    }
}
