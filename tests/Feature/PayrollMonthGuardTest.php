<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Pages\EmployeePayroll;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The payroll month lives in the address bar. A month that is not one falls
 * back to the current month instead of crashing the page on parse.
 */
final class PayrollMonthGuardTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_a_nonsense_month_in_the_url_falls_back_to_this_month(): void
    {
        $employee = Employee::query()->create(['name' => 'Ali', 'basic_salary' => 240]);

        Livewire::withQueryParams(['month' => 'not-a-month'])
            ->test(EmployeePayroll::class, ['id' => $employee->id])
            ->assertOk()
            ->assertSet('month', Carbon::now()->format('Y-m'));
    }

    public function test_a_nonsense_month_typed_later_is_corrected_too(): void
    {
        $employee = Employee::query()->create(['name' => 'Ali', 'basic_salary' => 240]);

        Livewire::test(EmployeePayroll::class, ['id' => $employee->id])
            ->set('month', '2026-13')
            ->assertOk()
            ->assertSet('month', Carbon::now()->format('Y-m'))
            ->set('month', '2026-03')
            ->assertSet('month', '2026-03');
    }
}
