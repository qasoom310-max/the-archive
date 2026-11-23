<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use App\Erp\Tenancy\WorkspaceManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Modules\Limousine\Models\LimoLeg;

/**
 * The link a driver gets with his trip to photograph his own bills.
 *
 * It names the trip AND the driver it was made for, inside the signature, so
 * it cannot be pointed at another trip or driver, and it stops working when
 * the trip goes to someone else. It lasts 48 hours from when it is made (the
 * team's choice). The business database rides along as `ws`, like the
 * customer's signing link, because the driver opens it signed out.
 */
final class DriverBillLink
{
    public const HOURS = 48;

    /**
     * The warning every driver must see, in both languages at once (the team's
     * wording, 2026-11-23): a photo is not a hand-over. Deliberately not run
     * through __(): it is shown in English AND Arabic whatever the page's language.
     */
    public const NOTE_EN = 'Sending a photo here does NOT count the bill on your petty cash. It counts only when you hand the ORIGINAL bill to the Accounts department.';

    public const NOTE_AR = 'إرسال صورة الفاتورة هنا لا يعني احتسابها من عهدتك. تُحتسب فقط عند تسليم الفاتورة الأصلية لقسم الحسابات.';

    public static function for(LimoLeg $leg): ?string
    {
        if ($leg->driver_id === null || ! \Illuminate\Support\Facades\Route::has('limousine.driver_bills.show')) {
            return null;
        }

        $ws = app(WorkspaceManager::class)->activeTenantId();
        $params = ['leg' => $leg->id, 'driver' => $leg->driver_id];
        if ($ws !== null) {
            $params['ws'] = $ws;
        }

        return URL::temporarySignedRoute('limousine.driver_bills.show', Carbon::now()->addHours(self::HOURS), $params);
    }
}
