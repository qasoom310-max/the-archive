<?php

// One-off, READ-ONLY look at how the Wanaan limousine bookings are stored,
// so newly exported rows from the old system can be mapped onto the same
// shape. Writes nothing.

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

$ws = Workspace::query()->find(7);
if ($ws === null) {
    echo "WORKSPACE 7 NOT FOUND\n";

    return;
}
echo "WORKSPACE {$ws->id} {$ws->name}\n";

$legable = 'Modules\\Limousine\\Models\\LimoBooking';

app(WorkspaceManager::class)->withTenant($ws->databasePath(), function () use ($legable): void {
    $out = static function (string $label, mixed $value): void {
        echo $label.': '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    };

    $out('BOOKINGS count', DB::table('limo_bookings')->count());
    $out('BOOKINGS max id', DB::table('limo_bookings')->max('id'));
    $out('BOOKINGS status counts', DB::table('limo_bookings')->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status'));
    $out('BOOKINGS payment counts', DB::table('limo_bookings')->select('payment_status', DB::raw('count(*) c'))->groupBy('payment_status')->pluck('c', 'payment_status'));
    $out('BOOKINGS booking_type counts', DB::table('limo_bookings')->select('booking_type', DB::raw('count(*) c'))->groupBy('booking_type')->pluck('c', 'booking_type'));
    $out('LEGS status counts', DB::table('limo_legs')->where('legable_type', $legable)->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status'));
    $out('LEGS service counts', DB::table('limo_legs')->where('legable_type', $legable)->select('service_type', DB::raw('count(*) c'))->groupBy('service_type')->pluck('c', 'service_type'));
    $out('INVOICES count / max id', [DB::table('limo_invoices')->count(), DB::table('limo_invoices')->max('id')]);
    $out('INVOICES with booking_id', DB::table('limo_invoices')->whereNotNull('booking_id')->count());
    $out('RECEIPTS count / max id', [DB::table('limo_receipts')->count(), DB::table('limo_receipts')->max('id')]);
    $out('CUSTOMERS count', DB::table('limo_customers')->count());

    $out('TARGET ids present (15452..15455)', DB::table('limo_bookings')->whereBetween('id', [15452, 15455])->pluck('reference', 'id'));

    $dump = static function ($b) use ($legable, $out): void {
        $row = (array) $b;
        $row['customer'] = DB::table('limo_customers')->where('id', $b->customer_id)->first(['id', 'name', 'type', 'phone', 'contact_person', 'contact_phone']);
        $row['legs'] = DB::table('limo_legs')->where('legable_type', $legable)->where('legable_id', $b->id)
            ->get(['id', 'sequence', 'reference', 'status', 'service_type', 'driver', 'driver_id', 'vehicle', 'car_id', 'from_location', 'from_location_url', 'to_location', 'to_location_url', 'start_at', 'hours', 'days', 'rate', 'rate_basis', 'line_total', 'net_amount']);
        $row['invoices'] = DB::table('limo_invoices')->where('booking_id', $b->id)->get(['id', 'reference', 'issue_date', 'total', 'amount_paid', 'status']);
        $row['receipts'] = DB::table('limo_receipts')->where('booking_id', $b->id)->get(['id', 'reference', 'date', 'amount', 'method']);
        $out('BOOKING '.$b->id, $row);
    };

    echo "--- latest 6 bookings ---\n";
    foreach (DB::table('limo_bookings')->orderByDesc('id')->limit(6)->get() as $b) {
        $dump($b);
    }

    echo "--- 3 non-completed, non-cancelled bookings ---\n";
    foreach (DB::table('limo_bookings')->whereNotIn('status', ['completed', 'cancelled'])->orderByDesc('id')->limit(3)->get() as $b) {
        $dump($b);
    }

    echo "--- Fursan customers ---\n";
    $out('FURSAN', DB::table('limo_customers')->where('name', 'like', '%Fursan%')->get(['id', 'name', 'type', 'phone', 'contact_person', 'contact_phone']));
    $out('FURSAN booking count', DB::table('limo_bookings')->whereIn('customer_id', DB::table('limo_customers')->where('name', 'like', '%Fursan%')->pluck('id'))->count());
    foreach (DB::table('limo_bookings')->whereIn('customer_id', DB::table('limo_customers')->where('name', 'like', '%Fursan%')->pluck('id'))->orderByDesc('id')->limit(2)->get() as $b) {
        $dump($b);
    }
});
