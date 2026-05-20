<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * System configuration hub. Renders every `ir_config_parameter` grouped
 * into tabs, with the control type driven by each parameter's `type`.
 * Admin-only (Odoo's Settings = Administration).
 *
 * `$form` is an index-keyed list (not keyed by the dotted setting key)
 * so Livewire's dot-path `wire:model` binding doesn't misread keys like
 * `company.name` as nested arrays.
 */
#[Layout('components.layouts.app')]
#[Title('Settings')]
final class SettingsPage extends Component
{
    /** @var list<array{key: string, label: string, type: string, group: string, description: string|null, value: mixed}> */
    public array $form = [];

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeAdmin();

        foreach (app(SettingManager::class)->grouped() as $params) {
            foreach ($params as $param) {
                $this->form[] = [
                    'key' => $param->key,
                    'label' => $param->label,
                    'type' => $param->type,
                    'group' => $param->group,
                    'description' => $param->description,
                    'value' => Setting::get($param->key),
                ];
            }
        }
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403, 'Settings are administrator-only.');
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        /** @var array<string, mixed> $values */
        $values = [];
        foreach ($this->form as $row) {
            $values[$row['key']] = $row['value'];
        }

        app(SettingManager::class)->setMany($values);

        // Reflect the persisted+re-cast values back into the form.
        foreach ($this->form as $i => $row) {
            $this->form[$i]['value'] = Setting::get($row['key']);
        }

        $this->saved = true;
    }

    public function updated(): void
    {
        $this->saved = false;
    }

    public function render(): View
    {
        /** @var array<string, list<int>> $tabs */
        $tabs = [];
        foreach ($this->form as $i => $row) {
            $tabs[$row['group']][] = $i;
        }

        return view('livewire.pages.settings', ['tabs' => $tabs]);
    }
}
