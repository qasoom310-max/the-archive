<div class="space-y-6">
    <div>
        <h2 class="text-sm font-semibold text-chrome-800">
            {{ $editingId ? __('Edit user') : __('Add user') }}
        </h2>
        <p class="mt-1 text-sm text-chrome-500">
            {{ __('Create a staff account and choose which apps and databases they can see (view only).') }}
        </p>
    </div>

    @if (session('user_saved'))
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-200">
            {{ session('user_saved') }}
        </p>
    @endif

    @unless ($onMain)
        {{-- Logins live in the Main database. Adding a user from inside a
             workspace would make an account that can't sign in. --}}
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
            {{ __('You’re inside a workspace. Users can only be added from the Main database (the login). Switch to Main from “My database”, then add the user there and lock them to this workspace.') }}
        </p>
    @endunless

    @if ($onMain)
    <form wire:submit="save" class="space-y-5">
        {{-- Credentials --}}
        <div class="grid gap-4 sm:grid-cols-3">
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
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">
                    {{ __('Password') }} @unless ($editingId) <span class="text-red-500">*</span> @endunless
                </label>
                <div class="relative" x-data="{ show: false }">
                    <input :type="show ? 'text' : 'password'" wire:model="password" autocomplete="new-password"
                        placeholder="{{ $editingId ? __('Leave blank to keep') : '' }}" class="o-input pe-10">
                    <button type="button" @click="show = !show" tabindex="-1"
                        :aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))"
                        class="absolute inset-y-0 end-0 flex items-center px-3 text-primary-600 hover:text-primary-700">
                        <svg x-show="!show" class="size-5" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 4C5.5 4 2.4 7.4 1.3 9.3a1.4 1.4 0 0 0 0 1.4C2.4 12.6 5.5 16 10 16s7.6-3.4 8.7-5.3a1.4 1.4 0 0 0 0-1.4C17.6 7.4 14.5 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/>
                        </svg>
                        <svg x-show="show" x-cloak class="size-5" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M3.7 2.3A1 1 0 0 0 2.3 3.7l2 2C3 6.8 1.9 8.2 1.3 9.3a1.4 1.4 0 0 0 0 1.4C2.4 12.6 5.5 16 10 16c1.5 0 2.9-.4 4.1-1l2.2 2.2a1 1 0 0 0 1.4-1.4l-14-13.5ZM10 13a3 3 0 0 1-2.8-4.1l3.9 3.9c-.3.1-.7.2-1.1.2Zm0-9c4.5 0 7.6 3.4 8.7 5.3.3.5.3 1 0 1.4-.5.8-1.2 1.8-2.2 2.7l-2.6-2.6A3 3 0 0 0 8.2 6.6L6.4 4.8C7.5 4.3 8.7 4 10 4Z"/>
                        </svg>
                    </button>
                </div>
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Role --}}
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Role') }}</label>
            <div class="grid gap-2 sm:max-w-md sm:grid-cols-2">
                @foreach (['staff' => __('Staff (view only)'), 'admin' => __('Administrator')] as $value => $roleLabel)
                    <label @class([
                        'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                        'border-primary-500 bg-primary-50 text-chrome-800' => $role === $value,
                        'border-chrome-200 text-chrome-700 hover:bg-chrome-50' => $role !== $value,
                        'pointer-events-none opacity-60' => $roleLocked,
                    ])>
                        <input type="radio" wire:model.live="role" value="{{ $value }}" @disabled($roleLocked)
                            class="border-chrome-300 text-primary-600 focus:ring-primary-500">
                        <span>{{ $roleLabel }}</span>
                    </label>
                @endforeach
            </div>
            @if ($roleLocked)
                <p class="mt-1 text-xs text-chrome-400">{{ __('This user is a super admin; manage their role from the super-admin controls.') }}</p>
            @endif
            @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- App access — only for staff; admins bypass the ACL entirely. --}}
        @if ($role === 'staff')
            <div>
                <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Apps this user can access') }}</label>
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
                {{ __('Administrators have full access to every app and setting.') }}
            </p>
        @endif

        {{-- Database access — only meaningful when creating (it seeds the
             account into each picked database). On edit the account already
             lives where it lives; name/email/password/apps update on Main. --}}
        @unless ($editingId)
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
        @endunless

        <div class="flex items-center justify-end gap-2 border-t border-chrome-100 pt-4">
            @if ($editingId)
                <button type="button" wire:click="cancelEdit"
                    class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                    {{ __('Cancel') }}
                </button>
            @endif
            <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $editingId ? __('Save changes') : __('Create user') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>
    @endif

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
                @endphp
                <li wire:key="user-{{ $user->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        @if ($user->is_super_admin)
                            <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ __('Super admin') }}</span>
                        @elseif ($user->is_admin)
                            <span class="shrink-0 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary-700">{{ __('Admin') }}</span>
                        @else
                            <span class="shrink-0 rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Staff') }}</span>
                        @endif
                        @if ($user->is_accountant)
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ __('Accountant') }}</span>
                        @endif
                        @if ($user->home_workspace_id && ($workspaceNames[$user->home_workspace_id] ?? null))
                            <span class="shrink-0 rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ __('Locked') }}: {{ $workspaceNames[$user->home_workspace_id] }}</span>
                        @endif
                        <span class="min-w-0 truncate">
                            <span class="font-medium text-chrome-700">{{ $user->name }}</span>
                            <span class="text-chrome-400">— {{ $user->email }}</span>
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        @unless ($onMain)
                            <span class="text-xs text-chrome-300">{{ __('Managed on Main') }}</span>
                        @endunless
                        {{-- Owner-only: promote/demote super admin. --}}
                        @if ($onMain && $actorIsSuperAdmin && ! $isSelf)
                            <button type="button" wire:click="toggleSuperAdmin({{ $user->id }})"
                                class="text-xs font-medium text-amber-700 hover:underline">
                                {{ $user->is_super_admin ? __('Remove super admin') : __('Make super admin') }}
                            </button>
                            {{-- Owner-only: grant/revoke the Accountant role (confirm payments). --}}
                            <button type="button" wire:click="toggleAccountant({{ $user->id }})"
                                class="text-xs font-medium text-emerald-700 hover:underline">
                                {{ $user->is_accountant ? __('Remove accountant') : __('Make accountant') }}
                            </button>
                        @endif
                        @if ($onMain && $canManage)
                            <button type="button" wire:click="editUser({{ $user->id }})"
                                class="text-xs font-medium text-primary-700 hover:underline">{{ __('Edit') }}</button>
                            @if (! $isSelf && ! $isLastAdmin)
                                <button type="button" wire:click="deleteUser({{ $user->id }})"
                                    wire:confirm="{{ __('Delete :name?', ['name' => $user->name]) }}"
                                    class="text-xs text-red-600 hover:underline">{{ __('remove') }}</button>
                            @endif
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
