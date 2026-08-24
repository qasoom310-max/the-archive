<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Add / edit an employee profile, including the uploaded signed agreement
 * (synchronous upload via FormFileUploadController → `employee_agreements`
 * bucket, set into {@see $agreementPath}). Admin-only.
 */
#[Layout('components.layouts.app')]
#[Title('Employee')]
final class EmployeeForm extends Component
{
    public ?int $id = null;

    /** @var array<string, mixed> */
    public array $form = [
        'name' => '',
        'position' => '',
        'phone' => '',
        'email' => '',
        'cpr' => '',
        'basic_salary' => '',
        'join_date' => '',
        'active' => true,
        'notes' => '',
    ];

    /** Relative path of the uploaded agreement (set by the JS uploader). */
    public ?string $agreementPath = null;

    /**
     * Payroll and company expenses are admin-only. Re-checked on EVERY action:
     * mount() runs once and Livewire then dispatches straight to methods, so a
     * mount-only gate leaves a page that is already open fully usable by someone
     * who has since been demoted.
     */
    private function guardAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    public function mount(?int $id = null): void
    {
        $this->guardAdmin();

        $this->id = $id;

        if ($id !== null) {
            $e = Employee::query()->findOrFail($id);
            $this->form = [
                'name' => (string) $e->name,
                'position' => (string) ($e->position ?? ''),
                'phone' => (string) ($e->phone ?? ''),
                'email' => (string) ($e->email ?? ''),
                'cpr' => (string) ($e->cpr ?? ''),
                'basic_salary' => (string) $e->basic_salary,
                'join_date' => $e->join_date?->toDateString() ?? '',
                'active' => (bool) $e->active,
                'notes' => (string) ($e->notes ?? ''),
            ];
            $this->agreementPath = $e->agreement_path;
        }
    }

    public function save(): void
    {
        $this->guardAdmin();
        $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.position' => ['nullable', 'string', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:50'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.cpr' => ['nullable', 'string', 'max:50'],
            'form.basic_salary' => ['nullable', 'numeric', 'min:0'],
            'form.join_date' => ['nullable', 'date'],
            'form.notes' => ['nullable', 'string'],
        ]);

        $employee = $this->id !== null ? Employee::query()->findOrFail($this->id) : new Employee();

        $employee->fill([
            'name' => trim((string) $this->form['name']),
            'position' => $this->blankToNull($this->form['position']),
            'phone' => $this->blankToNull($this->form['phone']),
            'email' => $this->blankToNull($this->form['email']),
            'cpr' => $this->blankToNull($this->form['cpr']),
            'basic_salary' => $this->form['basic_salary'] === '' ? 0 : round((float) $this->form['basic_salary'], 3),
            'join_date' => $this->form['join_date'] === '' ? null : (string) $this->form['join_date'],
            'active' => (bool) $this->form['active'],
            'notes' => $this->blankToNull($this->form['notes']),
            'agreement_path' => $this->agreementPath,
        ]);
        $employee->save();

        $wasNew = $this->id === null;
        $this->id = (int) $employee->getKey();

        if ($wasNew) {
            $this->redirectRoute('hr.employee.edit', ['id' => $this->id], navigate: true);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

    public function render(): View
    {
        return view('livewire.pages.employee-form', [
            'agreementUrl' => $this->agreementPath !== null ? Storage::disk('public')->url($this->agreementPath) : null,
        ]);
    }
}
