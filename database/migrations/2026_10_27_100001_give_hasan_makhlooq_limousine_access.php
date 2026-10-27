<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Business\Features;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hasan Makhlooq got 403 on a limousine invoice (/app/limousine/invoice/1793):
 * his account in the Wanaan database was set up without the Limousine app.
 * The owner asked for it to be fixed for him on 2026-10-27.
 *
 * Adds Limousine to the apps he already has, at the role he already holds
 * (Staff = read, Supervisor/Accountant = read + write + create), in every
 * database where he has an account and the business runs Limousine. His other
 * apps and his role are unchanged. An administrator limited to some apps gets
 * Limousine added to that list. Idempotent; a no-op where he is absent, where
 * he already has it, and in a database that does not run Limousine (Main, a
 * café). Core migration, so it reaches Main and every workspace.
 */
return new class extends Migration
{
    private const EMAIL = 'hasan.fuad@hotmail.com';

    public function up(): void
    {
        foreach (['users', 'res_groups', 'ir_model_access', 'ir_model'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [self::EMAIL])->first();
        $database = DB::connection()->getDatabaseName();

        if ($user === null) {
            return;
        }

        if (! Features::moduleAllowed('limousine')
            || ! DB::table('ir_model')->where('module', 'limousine')->exists()) {
            return;
        }

        if ($user->isAdmin()) {
            $scope = $user->adminAppScope();
            if ($scope !== null && ! in_array('limousine', $scope, true)) {
                $user->forceFill(['admin_apps' => [...$scope, 'limousine']])->save();
                $this->record($user, 'administrator, app list', $database);
            }

            return;
        }

        $provisioner = app(UserProvisioner::class);
        $apps = $provisioner->appsOf($user);

        if (in_array('limousine', $apps, true)) {
            return;
        }

        $role = $provisioner->roleOf($user);
        $provisioner->grantApps($user, [...$apps, 'limousine'], $role);
        $this->record($user, $role->value . ', apps before: ' . ($apps === [] ? 'none' : implode(', ', $apps)), $database);
    }

    private function record(User $user, string $detail, string $database): void
    {
        Log::info("Limousine access given to {$user->name} ({$detail}) in {$database}");

        try {
            app(ActivityLogger::class)->log(
                'user_updated',
                $user->name,
                'Limousine app access added (' . $detail . ') at the owner\'s request',
            );
        } catch (\Throwable) {
            // The audit line is a courtesy; the access itself is what matters.
        }
    }

    public function down(): void
    {
        // Access given on request is not taken back by a rollback.
    }
};
