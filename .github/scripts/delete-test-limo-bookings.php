<?php

// One-off: removes the three TEST bookings created directly in the Wanaan ERP
// (BK/15408 Qassim "test", BK/15409 + BK/15410 Hasan Makhlooq) so the old
// system's real bookings under those numbers can be imported. Owner confirmed
// 2026-09-14. Refuses to touch a booking that is not the one expected.
// DELETE_TEST_BOOKINGS_APPLY=1 to write; otherwise reports only.

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

$apply = getenv('DELETE_TEST_BOOKINGS_APPLY') === '1';
$expected = [15408 => 'Qassim Makhlooq', 15409 => 'Hasan Makhlooq', 15410 => 'Hasan Makhlooq'];

$ws = Workspace::query()->find(7);
if ($ws === null || stripos($ws->name, 'wanaan') === false) {
    echo "WORKSPACE 7 IS NOT WANAAN — aborting\n";

    return;
}
echo ($apply ? '' : '[DRY RUN] ')."WORKSPACE {$ws->id} {$ws->name}\n";

app(WorkspaceManager::class)->withTenant($ws->databasePath(), function () use ($apply, $expected): void {
    $legable = 'Modules\\Limousine\\Models\\LimoBooking';
    $ids = array_keys($expected);

    foreach ($expected as $id => $name) {
        $b = DB::table('limo_bookings')->where('id', $id)->first();
        $customer = $b !== null ? DB::table('rental_customers')->where('id', $b->customer_id)->value('name') : null;
        // An imported booking carries "Booking #N" notes; these were keyed in here.
        if ($b === null || trim((string) $customer) !== $name || str_starts_with((string) $b->notes, 'Booking #')) {
            echo "BK/{$id} is not the expected test booking (found: ".json_encode([$customer, $b->notes ?? null]).") — aborting, nothing deleted\n";

            return;
        }
        echo "BK/{$id} {$customer} {$b->pickup_at} fare {$b->fare} status {$b->status}\n";
    }

    $invoiceIds = DB::table('limo_invoices')->whereIn('booking_id', $ids)->pluck('id')->all();
    $receipts = DB::table('limo_receipts')->whereIn('booking_id', $ids)->orWhereIn('invoice_id', $invoiceIds);
    $links = DB::table('limo_payment_links')->whereIn('booking_id', $ids);
    $legs = DB::table('limo_legs')->where('legable_type', $legable)->whereIn('legable_id', $ids);
    $redemptions = DB::table('limo_coupon_redemptions')->where('redeemable_type', $legable)->whereIn('redeemable_id', $ids);

    echo 'legs: '.$legs->count()
        .', invoices: '.json_encode(DB::table('limo_invoices')->whereIn('id', $invoiceIds)->pluck('reference'))
        .', receipts: '.json_encode($receipts->clone()->get(['reference', 'amount']))
        .', payment links: '.$links->count()
        .', coupon redemptions: '.$redemptions->count()."\n";

    if (! $apply) {
        echo "Dry run — nothing deleted.\n";

        return;
    }

    echo 'Backup taken: '.basename(app(DatabaseBackup::class)->snapshot())."\n";

    DB::transaction(function () use ($ids, $invoiceIds, $receipts, $links, $legs, $redemptions): void {
        $redemptions->delete();
        $links->delete();
        $receipts->delete();
        DB::table('limo_invoices')->whereIn('id', $invoiceIds)->delete();
        $legs->delete();
        DB::table('limo_bookings')->whereIn('id', $ids)->delete();
    });

    echo 'Deleted. Bookings left with those numbers: '.DB::table('limo_bookings')->whereIn('id', $ids)->count()."\n";
});
