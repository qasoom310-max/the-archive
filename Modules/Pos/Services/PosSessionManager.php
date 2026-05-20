<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosSessionParticipant;

/**
 * The register is a single global singleton: at most one open
 * {@see PosSession} exists for the whole store. Every POS component
 * goes through here instead of creating its own session, so all
 * cashiers share one float / one closing procedure while individual
 * accountability is preserved on each order's `user_id` and via
 * per-user heartbeat presence rows.
 */
final class PosSessionManager
{
    /**
     * The one open register session, or null if the register is closed.
     */
    public function getActiveSession(): ?PosSession
    {
        return PosSession::query()
            ->where('state', SessionState::Opened)
            ->latest('id')
            ->first();
    }

    /**
     * Get-or-create the singleton. If the register is already open, the
     * caller *joins* it (no new session); otherwise it is opened with
     * the given float. Wrapped + locked so two simultaneous "Open"
     * clicks can never create two sessions.
     */
    public function openOrResume(float $openingCash, ?int $userId): PosSession
    {
        /** @var PosSession $session */
        $session = DB::transaction(function () use ($openingCash, $userId): PosSession {
            $active = PosSession::query()
                ->where('state', SessionState::Opened)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($active === null) {
                $seq = PosSession::query()->count() + 1;
                $active = PosSession::query()->create([
                    'reference' => sprintf('POS-S/%04d', $seq),
                    'user_id' => $userId,
                    'state' => SessionState::Opened,
                    'opening_cash' => round($openingCash, 2),
                    'opened_at' => Carbon::now(),
                ]);
                $active->logChange(
                    "Register {$active->reference} opened with float {$active->opening_cash}.",
                );
            }

            $this->heartbeat($active, $userId);

            return $active;
        });

        return $session;
    }

    /**
     * Mark a user as currently active in the shared session.
     */
    public function heartbeat(PosSession $session, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        PosSessionParticipant::query()->updateOrCreate(
            ['pos_session_id' => $session->id, 'user_id' => $userId],
            ['last_activity' => Carbon::now()],
        );
    }

    /**
     * Users seen within the window — "currently logged into" the shared
     * register, most-recent first.
     *
     * @return Collection<int, PosSessionParticipant>
     */
    public function activeParticipants(PosSession $session, int $withinSeconds = 120): Collection
    {
        return PosSessionParticipant::query()
            ->with('user')
            ->where('pos_session_id', $session->id)
            ->where('last_activity', '>=', Carbon::now()->subSeconds($withinSeconds))
            ->orderByDesc('last_activity')
            ->get();
    }
}
