<?php

// One-off: BK/15445 was attached to Mohammed Farah (#6261) because both share
// phone 66662828; the owner says Mohammad Shatat is a different person.
// Creates Mohammad Shatat and moves only that booking to him. SPLIT_APPLY=1 to
// write; otherwise reports.

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoCustomer;

$apply = getenv('SPLIT_APPLY') === '1';
$ws = Workspace::query()->find(7);
if ($ws === null || stripos($ws->name, 'wanaan') === false) {
    echo "WORKSPACE 7 IS NOT WANAAN — aborting\n";

    return;
}

app(WorkspaceManager::class)->withTenant($ws->databasePath(), function () use ($apply): void {
    $b = DB::table('limo_bookings')->where('id', 15445)->first();
    $farah = DB::table('rental_customers')->where('id', 6261)->first(['id', 'name', 'phone']);
    echo ($apply ? '' : '[DRY RUN] ').'BK/15445: '.json_encode([$b->customer_id ?? null, $b->pax_name ?? null, $b->pickup_at ?? null, $b->fare ?? null]).' Farah: '.json_encode($farah)."\n";

    if ($b === null || (int) $b->customer_id !== 6261 || $b->pax_name !== 'Mohammad Shatat') {
        echo "Not in the expected state — aborting, nothing changed\n";

        return;
    }

    $existing = LimoCustomer::query()->where('name', 'Mohammad Shatat')->first();
    echo 'Existing "Mohammad Shatat" customer: '.($existing?->id ?? 'none')."\n";

    if (! $apply) {
        return;
    }

    DB::transaction(function () use ($existing): void {
        $customer = $existing ?? LimoCustomer::query()->create([
            'name' => 'Mohammad Shatat', 'type' => 'individual', 'phone' => '66662828', 'active' => true,
        ]);
        DB::table('limo_bookings')->where('id', 15445)->update(['customer_id' => $customer->id, 'updated_at' => now()]);
        echo "BK/15445 now belongs to customer #{$customer->id} {$customer->name}\n";
    });
});
