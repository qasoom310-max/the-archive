<div class="space-y-6">
    <div>
        <h2 class="text-sm font-semibold text-chrome-800">
            @if ($editingGlobal)
                {{ __('Edit app access') }}
            @else
                {{ $editingId ? __('Edit user') : __('Add user') }}
            @endif
        </h2>
        <p class="mt-1 text-sm text-chrome-500">
            @if ($editingGlobal)
                {{ __('This account is shared with every database. Only what it can see HERE is set on this screen.') }}
            @elseif ($workspaceId)
                {{ __('Create an account for this database and choose which apps it can see.') }}
            @else
                {{ __('Create a staff account and choose which apps and databases they can see (view only).') }}
            @endif
        </p>
    </div>

    @if (session('user_saved'))
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-200">
            {{ session('user_saved') }}
        </p>
    @endif

    @if ($workspaceId && ! $editingGlobal)
        {{-- Inside a workspace: the account belongs to THIS database and signs
             straight into it. (A login shell is written to Main behind the
             scenes — the admin never has to switch databases.) --}}
        <p class="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 ring-1 ring-sky-200">
            {{ __('This user will belong to :database — they sign in straight into it and can’t reach any other database.', ['database' => $workspaceName]) }}
        </p>
    @endif

    <form wire:submit="save" class="space-y-5">
        {{-- Credentials --}}
        @if ($editingGlobal)
            <div class="rounded-lg bg-chrome-50 px-3 py-2 ring-1 ring-chrome-100">
                <p class="text-sm">
                    <span class="font-medium text-chrome-800">{{ $name }}</span>
                    <span class="text-chrome-400">— {{ $email }}</span>
                </p>
                <p class="mt-1 text-xs text-chrome-500">
                    {{ __('Their name, email and role are managed on Main. Tick the apps they should see in :database.', ['database' => $workspaceName]) }}
                </p>
            </div>
        @else
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Username') }} <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" autocomplete="off" class="o-input">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }} <span class="text-red-500">*</span></label>
                <input type="email" wire:model="email" autocomplete="off" class="o-input">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        <p class="flex items-start gap-2 text-xs text-chrome-500">
            <svg class="mt-0.5 size-4 shrink-0 text-primary-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/>
            </svg>
            @if ($editingId)
                {{ __('Passwords are not set here. They change theirs with “Forgot your password?” on the sign-in page.') }}
            @else
                {{ __('A strong password is generated and emailed to them with the sign-in link. They can choose their own from “Forgot your password?” on the sign-in page.') }}
            @endif
        </p>

        {{-- Role — ONE mutually-exclusive choice. Super admin + Accountant only
             render for a super admin (owner-only to assign; the `role.in` rule
             re-checks server-side). --}}
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Role') }}</label>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($roleOptions as $option)
                    <label wire:key="role-{{ $option->value }}" @class([
                        'flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm',
                        'border-primary-500 bg-primary-50 text-chrome-800' => $role === $option->value,
                        'border-chrome-200 text-chrome-700 hover:bg-chrome-50' => $role !== $option->value,
                    ])>
                        <input type="radio" wire:model.live="role" value="{{ $option->value }}"
                            class="mt-0.5 border-chrome-300 text-primary-600 focus:ring-primary-500">
                        <span>
                            <span class="block font-medium">{{ __($option->label()) }}</span>
                            <span class="block text-xs text-chrome-400">{{ __($option->description()) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        @endif

        {{-- App access. Staff/Supervisor/Accountant are GRANTED the ticked
             apps; an Administrator is instead NARROWED to them (still full
             access, incl. delete, and that app's own Settings tab — just to
             fewer apps). Only a super admin skips this entirely. --}}
        @if ($currentRole->usesAppPicker())
            <div>
                <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Apps this user can access') }}</label>
                @if ($currentRole->isScopableAdmin())
                    <p class="mb-2 text-xs text-chrome-400">{{ __('Leave every box unticked for unrestricted access to every app (the default). Tick specific apps to limit this administrator to just those — still with full add/edit/delete rights and that app’s own Settings tab.') }}</p>
                @endif
                @if ($appModules->isEmpty())
                    <p class="text-sm text-chrome-400">{{ __('No apps installed yet.') }}</p>
                @else
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($appModules as $app)
                            @php
                                $moduleKey = 'module.' . $app->name;
                                $label = __($moduleKey);
                                if ($label === $moduleKey) { $label = $app->display_name; }
                            @endphp
                            <label wire:key="app-{{ $app->id }}"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm hover:bg-chrome-50">
                                <input type="checkbox" wire:model="apps" value="{{ $app->name }}"
                                    class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                <span class="text-chrome-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <p class="rounded-lg bg-chrome-50 px-3 py-2 text-sm text-chrome-500 ring-1 ring-chrome-100">
                {{ __('Super admins have full access to every app and setting.') }}
            </p>
        @endif

        {{-- Database access — only meaningful when creating FROM MAIN (it seeds
             the account into each picked database). Inside a workspace there's
             nothing to pick: the account belongs to the database you're in. On
             edit the account already lives where it lives. --}}
        @if (! $editingId && ! $workspaceId)
            {{-- Workspace-locked admin: full owner of ONE database, no access to
                 any other. Only a super admin may mint one. --}}
            @php $tenantWorkspaces = $workspaceList->where('is_main', false); @endphp
            @if ($actorIsSuperAdmin && $tenantWorkspaces->isNotEmpty())
                <div class="rounded-lg border border-chrome-200 p-3">
                    <label class="flex cursor-pointer items-start gap-2">
                        <input type="checkbox" wire:model.live="lockToWorkspace"
                            class="mt-0.5 rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                        <span>
                            <span class="block text-sm font-medium text-chrome-800">{{ __('Lock to one database (workspace admin)') }}</span>
                            <span class="block text-xs text-chrome-400">{{ __('Full owner of a single database, with no access to any other and no database switcher. The role and app choices above don’t apply.') }}</span>
                        </span>
                    </label>
                    @if ($lockToWorkspace)
                        <div class="mt-3">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Which database') }}</label>
                            <select wire:model="lockWorkspaceId" class="o-input">
                                <option value="">{{ __('— Select —') }}</option>
                                @foreach ($tenantWorkspaces as $workspace)
                                    <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                                @endforeach
                            </select>
                            @error('lockWorkspaceId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @error('lockToWorkspace') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            @endif

            @unless ($lockToWorkspace)
                <div>
                    <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Databases this user can access') }}</label>
                    <p class="mb-2 text-xs text-chrome-400">{{ __('Pick the databases to create this account in.') }}</p>
                    @if ($workspaceList->isEmpty())
                        <p class="text-sm text-chrome-400">{{ __('No databases available.') }}</p>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($workspaceList as $workspace)
                                <label wire:key="ws-{{ $workspace->id }}"
                                    class="flex cursor-pointer items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm hover:bg-chrome-50">
                                    <input type="checkbox" wire:model="workspaces" value="{{ $workspace->id }}"
                                        class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                                    <span class="text-chrome-700">{{ $workspace->name }}</span>
                                    @if ($workspace->is_main)
                                        <span class="ms-auto rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-medium text-chrome-500">{{ __('Main') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('workspaces') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endunless
        @endif

        <div class="flex items-center justify-end gap-2 border-t border-chrome-100 pt-4">
            @if ($editingId)
                <button type="button" wire:click="cancelEdit"
                    class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                    {{ __('Cancel') }}
                </button>
            @endif
            <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">@if ($editingGlobal){{ __('Save app access') }}@else{{ $editingId ? __('Save changes') : __('Create user') }}@endif</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>

    {{-- All users (admins + staff) --}}
    <div class="border-t border-chrome-100 pt-5">
        <h3 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Users') }}</h3>
        <ul class="divide-y divide-chrome-100 rounded-lg border border-chrome-100">
            @forelse ($users as $user)
                @php
                    $isSelf = $user->id === $currentUserId;
                    $isLastAdmin = $user->is_admin && $adminCount <= 1;
                    // A regular admin may not manage a super admin (escalation guard).
                    $canManage = ! $user->is_super_admin || $actorIsSuperAdmin;
                    // Inside a workspace only this database's own accounts are
                    // editable — a global account is shared with every other
                    // database, so it stays read-only here ("Managed on Main").
                    $inScope = ! $workspaceId || (int) $user->home_workspace_id === (int) $workspaceId;
                    // A global account's identity is Main's, but ACL grants are
                    // per-database rows — so what it can see HERE is ours to set.
                    // (An administrator bypasses the ACL: nothing to grant.)
                    $canSetAccessHere = $workspaceId && ! $inScope && $user->home_workspace_id === null && ! $user->is_admin;
                    // Pause is NOT an identity edit — it's "block this account
                    // in the database I'm looking at right now", so unlike
                    // Edit/Delete it is available on EVERY row (global
                    // accounts included) without a trip to Main. Turning pause
                    // ON still can't target yourself or the last admin;
                    // turning it OFF has no such risk.
                    $canTogglePauseOn = $canManage && ! $isSelf && ! $isLastAdmin;
                    $showActionsMenu = $canManage && ($inScope || $canSetAccessHere || $user->is_paused || $canTogglePauseOn);
                @endphp
                <li wire:key="user-{{ $user->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        {{-- One badge = the one role. Classes are written out in
                             full so Tailwind's JIT scanner sees them. --}}
                        @php
                            $userRole = $userRoles[$user->id] ?? \App\Erp\Admin\StaffRole::Staff;
                            $roleBadge = match ($userRole->color()) {
                                'amber' => 'bg-amber-100 text-amber-700',
                                'primary' => 'bg-primary-100 text-primary-700',
                                'sky' => 'bg-sky-100 text-sky-700',
                                'emerald' => 'bg-emerald-100 text-emerald-700',
                                default => 'bg-chrome-100 text-chrome-500',
                            };
                        @endphp
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $roleBadge }}">
                            {{ __($userRole->label()) }}
                        </span>
                        @if ($user->home_workspace_id && ($workspaceNames[$user->home_workspace_id] ?? null))
                            <span class="shrink-0 rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ __('Locked') }}: {{ $workspaceNames[$user->home_workspace_id] }}</span>
                        @endif
                        @if ($user->is_paused)
                            <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">{{ __('Paused') }}</span>
                        @endif
                        <span class="min-w-0 truncate">
                            <span class="font-medium text-chrome-700">{{ $user->name }}</span>
                            <span class="text-chrome-400">— {{ $user->email }}</span>
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        @unless ($inScope)
                            <span class="text-xs text-chrome-300">{{ __('Managed on Main') }}</span>
                        @endunless
                        {{-- Role changes (incl. super admin + accountant) happen in
                             Edit now — one place, one set of guards. Edit / Pause /
                             Delete live behind a 3-dot menu (the same isolated
                             per-row Alpine scope + @click.outside pattern used by
                             the app-bar dropdowns). Pause·Unpause is available on
                             EVERY row regardless of scope (see $canTogglePauseOn
                             above) — only Edit/Edit access/Delete are scope-gated. --}}
                        @if ($showActionsMenu)
                            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                                <button type="button" @click="open = ! open"
                                    class="flex size-7 items-center justify-center rounded-full text-chrome-500 hover:bg-chrome-100"
                                    aria-label="{{ __('Actions') }}">
                                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>
                                    </svg>
                                </button>
                                <div x-cloak x-show="open" x-transition
                                    class="absolute end-0 z-20 mt-1 w-36 overflow-hidden rounded-lg border border-chrome-200 bg-white py-1 shadow-lg">
                                    @if ($inScope)
                                        <button type="button" @click="open = false" wire:click="editUser({{ $user->id }})"
                                            class="block w-full px-3 py-1.5 text-start text-xs font-medium text-chrome-700 hover:bg-chrome-50">
                                            {{ __('Edit') }}
                                        </button>
                                    @elseif ($canSetAccessHere)
                                        <button type="button" @click="open = false" wire:click="editUser({{ $user->id }})"
                                            class="block w-full px-3 py-1.5 text-start text-xs font-medium text-chrome-700 hover:bg-chrome-50">
                                            {{ __('Edit access') }}
                                        </button>
                                    @endif
                                    @if ($user->is_paused)
                                        <button type="button" @click="open = false" wire:click="togglePause({{ $user->id }})"
                                            class="block w-full px-3 py-1.5 text-start text-xs font-medium text-emerald-700 hover:bg-emerald-50">
                                            {{ __('Unpause') }}
                                        </button>
                                    @elseif ($canTogglePauseOn)
                                        <button type="button" @click="open = false" wire:click="togglePause({{ $user->id }})"
                                            wire:confirm="{{ __('Pause :name? They will be signed out and unable to sign in until unpaused.', ['name' => $user->name]) }}"
                                            class="block w-full px-3 py-1.5 text-start text-xs font-medium text-amber-700 hover:bg-amber-50">
                                            {{ __('Pause') }}
                                        </button>
                                    @endif
                                    @if ($inScope && ! $isSelf && ! $isLastAdmin)
                                        <button type="button" @click="open = false" wire:click="deleteUser({{ $user->id }})"
                                            wire:confirm="{{ __('Delete :name?', ['name' => $user->name]) }}"
                                            class="block w-full px-3 py-1.5 text-start text-xs font-medium text-red-600 hover:bg-red-50">
                                            {{ __('Delete') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </span>
                </li>
            @empty
                <li class="px-3 py-4 text-center text-sm text-chrome-400">{{ __('No users yet.') }}</li>
            @endforelse
        </ul>
    </div>

    {{-- 2FA: regular admins confirm an emailed code before edit/delete. --}}
    @include('partials.otp-modal')
</div>
