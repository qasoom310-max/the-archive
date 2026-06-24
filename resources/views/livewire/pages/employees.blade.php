@php use App\Erp\Money\Currencies; @endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Employees') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Staff records, contracts and payroll.') }}</p>
        </div>
        <a href="{{ url('/hr/employee/new') }}" wire:navigate class="o-btn-primary">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            {{ __('New employee') }}
        </a>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-200 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Name') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Position') }}</th>
                    <th class="px-4 py-2.5 text-end font-semibold">{{ __('Basic salary') }}</th>
                    <th class="px-4 py-2.5 text-start font-semibold">{{ __('Status') }}</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($employees as $e)
                    <tr wire:key="emp-{{ $e->id }}" class="hover:bg-chrome-50 {{ $e->active ? '' : 'opacity-60' }}">
                        <td class="px-4 py-2.5">
                            <a href="{{ url('/hr/employee/' . $e->id) }}" wire:navigate class="font-medium text-chrome-800 hover:text-primary-700">{{ $e->name }}</a>
                        </td>
                        <td class="px-4 py-2.5 text-chrome-500">{{ $e->position ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-end tabular-nums text-chrome-700">{{ Currencies::format($e->basic_salary) }}</td>
                        <td class="px-4 py-2.5">
                            @if ($e->active)
                                <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Active') }}</span>
                            @else
                                <span class="inline-flex rounded-full bg-chrome-100 px-2 py-0.5 text-xs font-medium text-chrome-500">{{ __('Inactive') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-end">
                            <a href="{{ url('/hr/employee/' . $e->id . '/payroll') }}" wire:navigate class="text-xs font-medium text-primary-700 hover:underline">{{ __('Payroll') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No employees yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
