<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\StaffApp\AccountDeletion;
use App\Erp\Tenancy\WorkspaceManager;
use App\Http\Middleware\AppWorkspace;
use App\Models\AppDeletionRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signing in to the staff app: the same account and password as the website,
 * the same limits on wrong guesses, and a token instead of a session.
 */
final class AuthController
{
    /** Wrong passwords one account may take, from anywhere, before it is locked a while. */
    private const ACCOUNT_ATTEMPTS = 10;

    private const ACCOUNT_LOCK_SECONDS = 900;

    public function __construct(private readonly ActivityLogger $activity)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        // Always Main: the canonical identity store (see SetActiveWorkspace).
        $login = trim((string) $data['login']);
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'name';

        // Per address + typed login, and per account wherever the guesses come
        // from — keyed by the account's id, since the database matches the
        // typed text regardless of case and accents.
        /** @var array<string, array{int, int}> $limits key => [max attempts, decay seconds] */
        $limits = ['app-login:' . mb_strtolower($login) . '|' . $request->ip() => [5, 60]];
        $user = User::query()->where($field, $login)->first();
        if ($user !== null) {
            $limits['app-login-account:' . $user->id] = [self::ACCOUNT_ATTEMPTS, self::ACCOUNT_LOCK_SECONDS];
        }

        foreach ($limits as $key => [$maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'login' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        if ($user === null || ! Hash::check((string) $data['password'], (string) $user->password)) {
            foreach ($limits as $key => [, $decaySeconds]) {
                RateLimiter::hit($key, $decaySeconds);
            }

            throw ValidationException::withMessages([
                'login' => __('These credentials do not match our records.'),
            ]);
        }

        if ($user->isPaused()) {
            throw ValidationException::withMessages([
                'login' => __('Your account has been paused. Contact your administrator.'),
            ]);
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::clear($key);
        }

        $device = trim((string) ($data['device_name'] ?? ''));
        $token = $user->createToken($device !== '' ? $device : 'Staff app');

        $this->activity->log('login', __('Staff app'), __('Signed in on :device', ['device' => $token->accessToken->name]), $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** Sign this phone out: its token stops working at once. */
    public function logout(Request $request): JsonResponse
    {
        // Routes under auth:sanctum without a session: always a real token.
        $user = $request->user();
        if ($user instanceof User) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * "Delete my account", from inside the app (Apple wants it there). The
     * account is the company's, so this asks: an admin deletes it under
     * Settings → Users, within the 30 days the privacy policy promises.
     */
    public function requestDeletion(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $login = $request->attributes->get(AppWorkspace::LOGIN);
        if (! $login instanceof User) {
            abort(401);
        }

        $workspace = $request->attributes->get(AppWorkspace::WORKSPACE);
        $asked = $deletion->request([
            'name' => $login->name,
            'login' => $login->email ?? $login->name,
            'user_id' => $login->id,
            'business' => $workspace instanceof Workspace ? (string) $workspace->name : null,
        ], AppDeletionRequest::SOURCE_APP, $request->ip());

        return response()->json([
            'requested_at' => $asked->created_at?->toIso8601String(),
            'message' => __('Your request has been sent. The company will delete your account and tell you within 30 days.'),
        ]);
    }

    /**
     * Who is signed in, in which business, and what the app may offer them —
     * read in that business's database, like every screen of the website.
     */
    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();
        $login = $request->attributes->get(AppWorkspace::LOGIN);
        if (! $user instanceof User || ! $login instanceof User) {
            abort(401);
        }

        $workspace = $request->attributes->get(AppWorkspace::WORKSPACE);
        $access = app(AccessControl::class);

        $workspaces = app(WorkspaceManager::class)->accessibleFor($login)
            ->map(static fn (Workspace $w): array => ['id' => (int) $w->id, 'name' => (string) $w->name, 'is_main' => (bool) $w->is_main])
            ->values()->all();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'is_owner' => $user->isSuperAdmin(),
                'details_after_crew' => $user->seesTripDetailsOnlyWhenCrewed(),
            ],
            'workspace' => $workspace instanceof Workspace ? ['id' => (int) $workspace->id, 'name' => (string) $workspace->name] : null,
            'workspaces' => $workspaces,
            'can' => [
                'view_trips' => $access->allows($user, 'limousine.booking', Permission::Read),
                'assign_trips' => $access->allows($user, 'limousine.booking', Permission::Write),
            ],
        ]);
    }
}
