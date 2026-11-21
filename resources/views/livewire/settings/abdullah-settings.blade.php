@php
    $lbl = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500';
    $kinds = ['chat' => __('Chat'), 'booking' => __('New booking'), 'check' => __('Mistake'), 'trip' => __('Trip without driver'), 'escalation' => __('Told the owner')];
@endphp

<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Settings') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Abdullah — the assistant who talks to your staff inside the ERP.') }}</p>
    </div>

    @include('partials.settings-nav', ['active' => 'abdullah'])

    <div class="mb-5 space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5" data-abdullah-settings>
        <label class="flex items-center gap-3">
            <input type="checkbox" wire:model="enabled" class="size-4 rounded border-chrome-300 text-primary-600">
            <span class="text-sm font-semibold text-chrome-800">{{ __('Abdullah is on') }}</span>
        </label>

        <p class="rounded-lg px-3 py-2 text-xs {{ $hasKey ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }}">
            @if ($hasKey)
                {{ __('He writes with Claude: :used of :limit messages used this month. Past the limit he uses short plain sentences until next month.', ['used' => $used, 'limit' => $monthlyLimit]) }}
            @else
                {{ __('No Claude key is saved, so he uses short plain sentences in Arabic or English. Add the key in WhatsApp → Assistant settings.') }}
            @endif
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Claude messages per month') }}</label>
                <input type="number" min="0" wire:model="monthlyLimit" class="o-input w-full">
                @error('monthlyLimit') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Trip reminders go to') }}</label>
                <input type="email" wire:model="opsEmail" class="o-input w-full" dir="ltr">
                @error('opsEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Remind every (minutes)') }}</label>
                <input type="number" min="5" wire:model="tripEvery" class="o-input w-full">
                @error('tripEvery') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Reminders per trip, then tell me') }}</label>
                <input type="number" min="1" wire:model="tripMax" class="o-input w-full">
                @error('tripMax') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end">
            <button type="button" wire:click="save" class="o-btn-primary">{{ __('Save') }}</button>
        </div>
    </div>

    <div class="mb-5 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="border-b border-chrome-100 px-6 py-4">
            <h2 class="text-sm font-semibold text-chrome-900">{{ __('Staff') }}</h2>
            <p class="text-xs text-chrome-500">{{ __('The language Abdullah uses with each person. Each person can also change it in his box. Trip reminders in the box go to the people with "Trip reminders" on under Users.') }}</p>
        </div>
        <table class="w-full min-w-[560px] text-sm">
            <tbody class="divide-y divide-chrome-50">
                @foreach ($users as $u)
                    <tr wire:key="abd-user-{{ $u->id }}">
                        <td class="px-6 py-2 font-medium text-chrome-800">{{ $u->name }}</td>
                        <td class="px-3 py-2">
                            <select wire:change="setLanguage({{ $u->id }}, $event.target.value)" class="o-input py-1 text-sm">
                                @foreach ($languages as $code => $name)
                                    <option value="{{ $code }}" @selected($abdullah->languageFor($u) === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-3 py-2 text-xs text-chrome-500">
                            @if ($hasTripColumn && $u->getAttribute('trip_reminders')) {{ __('Trip reminders') }} @endif
                        </td>
                        <td class="px-6 py-2 text-end">
                            <button type="button" wire:click="toggleOff({{ $u->id }})"
                                    class="rounded-lg px-2.5 py-1 text-xs font-semibold {{ $u->getAttribute('abdullah_off') ? 'bg-chrome-100 text-chrome-500' : 'bg-emerald-100 text-emerald-700' }}">
                                {{ $u->getAttribute('abdullah_off') ? __('Off') : __('On') }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5" data-abdullah-log>
        <div class="border-b border-chrome-100 px-6 py-4">
            <h2 class="text-sm font-semibold text-chrome-900">{{ __('What Abdullah said') }}</h2>
            <p class="text-xs text-chrome-500">{{ __('The latest 80 messages, in the box and by email. Nothing is deleted.') }}</p>
        </div>
        <ul class="divide-y divide-chrome-50">
            @forelse ($log as $m)
                <li class="px-6 py-3">
                    <div class="mb-1 flex flex-wrap items-center gap-2 text-[11px] text-chrome-500">
                        <span class="font-semibold text-chrome-700">{{ $m->role === 'user' ? ($m->user?->name ?? '—') . ' → ' . __('Abdullah') : __('Abdullah') . ' → ' . ($m->email_to ?? $m->user?->name ?? '—') }}</span>
                        <span class="rounded bg-chrome-100 px-1.5 py-0.5">{{ $kinds[$m->kind] ?? $m->kind }}</span>
                        <span>{{ $m->channel === 'email' ? __('Email') : __('Box') }}</span>
                        <span>{{ $m->created_at?->format('Y-m-d H:i') }}</span>
                    </div>
                    <p dir="auto" class="whitespace-pre-line text-sm text-chrome-800">{{ $m->body }}</p>
                </li>
            @empty
                <li class="px-6 py-8 text-center text-sm text-chrome-400">{{ __('Abdullah has not said anything yet.') }}</li>
            @endforelse
        </ul>
    </div>
</div>
