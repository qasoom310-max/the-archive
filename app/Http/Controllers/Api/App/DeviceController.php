<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App;

use App\Models\AppDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The phone tells the ERP where to send its notifications (its Expo push
 * token) when it signs in, and takes it back when it signs out. Kept in the
 * business database the person works in, which is where the reminders run.
 */
final class DeviceController
{
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(401);
        }

        $data = $request->validate([
            'token' => ['required', 'string', 'max:191', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[^\]]+\]$/'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
        ]);

        // A phone that changes hands keeps one row: the token moves to whoever
        // signed in on it last.
        AppDevice::query()->updateOrCreate(
            ['token' => (string) $data['token']],
            ['user_id' => $user->id, 'platform' => $data['platform'] ?? null, 'last_seen_at' => now()],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(401);
        }

        $token = (string) $request->input('token', '');
        AppDevice::query()->where('user_id', $user->id)->where('token', $token)->delete();

        return response()->json(['ok' => true]);
    }
}
