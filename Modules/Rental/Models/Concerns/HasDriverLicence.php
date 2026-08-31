<?php

declare(strict_types=1);

namespace Modules\Rental\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * A driver's licence, and whether it still lets them drive.
 *
 * Shared because `Driver` and `LimoDriver` are two doors onto ONE table — the
 * same people drive for both apps. Written once here so the two cannot answer
 * "may this driver go out?" differently, which is exactly the kind of drift
 * that puts an unlicensed driver on the road in one app while the other refuses
 * them.
 */
trait HasDriverLicence
{
    /** Warn this many days before a licence runs out. */
    public const RENEWAL_WARNING_DAYS = 30;

    /** Has the licence run out? A missing date is not expired — it is unknown. */
    public function licenceExpired(): bool
    {
        return $this->license_expiry !== null
            && $this->license_expiry->lt(Carbon::today());
    }

    /** Still valid, but not for much longer. */
    public function licenceExpiringSoon(): bool
    {
        if ($this->license_expiry === null || $this->licenceExpired()) {
            return false;
        }

        return $this->license_expiry->lte(Carbon::today()->addDays(self::RENEWAL_WARNING_DAYS));
    }

    /**
     * May this driver be given a job today?
     *
     * Two ways to be unavailable, and they mean different things: inactive is a
     * decision somebody made, an expired licence is a fact about the paperwork.
     * A driver with no expiry on file is allowed — we do not know it has run
     * out, and refusing every driver whose date was never entered would stop
     * the office working on the day this ships.
     */
    public function canBeDispatched(): bool
    {
        return (bool) $this->active && ! $this->licenceExpired();
    }

    /** Why they cannot be dispatched, for a message the office can act on. */
    public function dispatchBlockReason(): ?string
    {
        if (! $this->active) {
            return (string) __('That driver is not active.');
        }

        if ($this->licenceExpired()) {
            return (string) __("That driver's licence expired on :date — renew it on their record first.", [
                'date' => $this->license_expiry?->isoFormat('DD-MMM-YYYY') ?? '',
            ]);
        }

        return null;
    }
}
