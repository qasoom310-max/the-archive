<div class="mx-auto max-w-2xl p-4 sm:p-6">
    <h1 class="mb-1 text-xl font-semibold text-chrome-900">{{ __('Driver details') }}</h1>
    <p class="mb-5 text-sm text-chrome-500">{{ __('Customers get their driver and car on WhatsApp 12 hours before pick-up. Until a trip has both, operations are reminded every 10 minutes.') }}</p>

    @include('partials.settings-nav', ['active' => 'limo_crew'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 ring-1 ring-emerald-100">
            {{ __('Saved.') }}
        </div>
    @endif

    @unless ($whatsappReady)
        <div class="mb-4 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800 ring-1 ring-amber-100" data-whatsapp-not-ready>
            {{ __('WhatsApp is not connected in this database yet — set it up under Settings → WhatsApp, or nothing will be sent.') }}
        </div>
    @endunless

    <form wire:submit="save" class="space-y-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <div>
            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Operations WhatsApp numbers') }}</label>
            <textarea wire:model="operationsNumbers" rows="3" dir="ltr" class="o-input w-full"
                placeholder="+973 3xxx xxxx"></textarea>
            <p class="mt-1 text-xs text-chrome-500">{{ __('One per line. From 12 hours before pick-up, each is reminded every 10 minutes until the trip has a car and a driver.') }}</p>
            @error('operationsNumbers') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Number to alert when the 12 hours are missed') }}</label>
            <input type="text" wire:model="ownerNumber" dir="ltr" class="o-input w-full" placeholder="+973 3xxx xxxx">
            <p class="mt-1 text-xs text-chrome-500">{{ __('Told once per trip, when it reaches 12 hours before pick-up with no car or driver.') }}</p>
            @error('ownerNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-lg bg-chrome-50 px-4 py-3">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model="enabled" class="size-4 rounded border-chrome-300 text-primary-500" data-crew-enabled>
                <span class="text-sm font-medium text-chrome-800">{{ __('Send driver details to customers and reminders to operations') }}</span>
            </label>
            <p class="mt-1 ms-7 text-xs text-chrome-500">{{ __('Turn this on only after Meta has approved the two message templates below.') }}</p>
        </div>

        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save') }}</button>
        </div>
    </form>

    {{-- Who sees a trip's details only after it has a car and a driver
         (Modules\Limousine\Support\TripDetailsLock). The owner's call: other
         admins see the list but cannot change it. --}}
    <form wire:submit="saveLockedStaff" class="mt-6 space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6" data-locked-staff>
        <div>
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Operations staff: details only after the car and driver are assigned') }}</h2>
            <p class="mt-1 text-xs text-chrome-500">{{ __('The people ticked here see a trip\'s time, car type, customer and countries only. The places, passenger, phone numbers, copy button and service order appear once a car and a driver are assigned — so a trip can\'t be sent to a driver outside the system.') }}</p>
            <p class="mt-1 text-xs text-chrome-500">{{ __('The same switch is on each person under Settings → Users (⋮ → Lock trip details), with their trip reminders.') }}</p>
        </div>

        @if ($staffSaved)
            <div class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ __('Saved.') }}</div>
        @endif

        <div class="max-h-72 divide-y divide-chrome-100 overflow-y-auto rounded-lg ring-1 ring-chrome-200">
            @forelse ($staff as $person)
                <label class="flex items-center gap-3 px-3 py-2 text-sm {{ $mayLockStaff ? 'cursor-pointer hover:bg-chrome-50' : '' }}">
                    <input type="checkbox" wire:model="lockedUserIds" value="{{ $person->id }}"
                           class="size-4 rounded border-chrome-300 text-primary-500" @disabled(! $mayLockStaff)>
                    <span class="font-medium text-chrome-800">{{ $person->name }}</span>
                    @if ($person->email)
                        <span class="text-xs text-chrome-500" dir="ltr">{{ $person->email }}</span>
                    @endif
                </label>
            @empty
                <p class="px-3 py-2 text-sm text-chrome-500">{{ __('No staff accounts in this database.') }}</p>
            @endforelse
        </div>

        @if ($mayLockStaff)
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Save staff') }}</button>
            </div>
        @else
            <p class="text-xs text-chrome-500">{{ __('Only the owner can change who is ticked.') }}</p>
        @endif
    </form>

    {{-- WhatsApp lets a business START a conversation only with a template Meta
         has approved, so these two are created once in WhatsApp Manager
         (category: Utility), named exactly as shown, in the template language
         set under Settings → WhatsApp. Shown in both languages to copy. --}}
    <div class="mt-6 space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6" data-crew-templates>
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Message templates to create in WhatsApp Manager') }}</h2>
        <p class="text-xs text-chrome-500">{{ __('Category: Utility. Use exactly these names, in the template language set under Settings → WhatsApp.') }}</p>

        <div>
            <p class="mb-1 font-mono text-xs font-semibold text-chrome-800" dir="ltr">{{ $customerTemplate }}</p>
            <pre class="whitespace-pre-wrap rounded-lg bg-chrome-50 p-3 text-xs text-chrome-800" dir="ltr">Hello @{{1}}, here are your driver details for trip @{{2}} on @{{3}}.
Driver: @{{4}}
Phone: @{{5}}
Car: @{{6}}
Plate: @{{7}}
Thank you for choosing us.</pre>
            <pre class="mt-2 whitespace-pre-wrap rounded-lg bg-chrome-50 p-3 text-xs text-chrome-800" dir="rtl">مرحباً @{{1}}، هذه تفاصيل السائق لرحلتك رقم @{{2}} بتاريخ @{{3}}.
السائق: @{{4}}
الهاتف: @{{5}}
السيارة: @{{6}}
رقم اللوحة: @{{7}}
شكراً لاختياركم لنا.</pre>
        </div>

        <div>
            <p class="mb-1 font-mono text-xs font-semibold text-chrome-800" dir="ltr">{{ $reminderTemplate }}</p>
            <pre class="whitespace-pre-wrap rounded-lg bg-chrome-50 p-3 text-xs text-chrome-800" dir="ltr">Trip @{{1}} on @{{2}} for @{{3}} still has no @{{4}} in the system. The customer must get the driver details 12 hours before pick-up. Please assign it now.</pre>
            <pre class="mt-2 whitespace-pre-wrap rounded-lg bg-chrome-50 p-3 text-xs text-chrome-800" dir="rtl">الرحلة @{{1}} بتاريخ @{{2}} للعميل @{{3}} لا يوجد لها @{{4}} في النظام حتى الآن. يجب أن يستلم العميل تفاصيل السائق قبل 12 ساعة من موعد الرحلة. يرجى التعيين الآن.</pre>
        </div>
    </div>
</div>
