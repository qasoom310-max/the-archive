<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The profile avatar lives on one shared disk but each workspace has its own
 * `users` row (the tenant copy, matched by email, has no avatar_path). The
 * avatar must still render in every workspace by falling back to the Main
 * record — regression for "no image in qassim profile when switching DBs".
 */
final class AvatarWorkspaceFallbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_avatar_falls_back_to_the_main_record_inside_a_workspace(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/qassim.png', 'fake-image-bytes');

        $admin = User::factory()->create([
            'is_admin' => true,
            'email' => 'qassim@example.com',
            'avatar_path' => 'avatars/qassim.png',
        ]);
        $this->actingAs($admin);

        // On Main the avatar resolves from the user's own row.
        $this->assertNotNull($admin->avatarUrl());

        // A separate workspace — the tenant copy of qassim has NO avatar_path.
        $workspace = app(WorkspaceManager::class)->provision('Kaleem', $admin, ['contacts']);

        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), function (): void {
            $tenantUser = User::query()->where('email', 'qassim@example.com')->firstOrFail();

            // The tenant row genuinely has no avatar path of its own...
            $this->assertTrue($tenantUser->avatar_path === null || $tenantUser->avatar_path === '');

            // ...but the avatar still resolves via the Main fallback.
            $this->assertNotNull($tenantUser->avatarUrl());
        });
    }

    public function test_a_workspace_user_with_its_own_avatar_keeps_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/main.png', 'a');
        Storage::disk('public')->put('avatars/branch.png', 'b');

        $admin = User::factory()->create([
            'is_admin' => true,
            'email' => 'owner@example.com',
            'avatar_path' => 'avatars/main.png',
        ]);
        $this->actingAs($admin);

        $workspace = app(WorkspaceManager::class)->provision('Branch', $admin, ['contacts']);

        app(WorkspaceManager::class)->withTenant((string) $workspace->databasePath(), function (): void {
            $tenantUser = User::query()->where('email', 'owner@example.com')->firstOrFail();
            $tenantUser->avatar_path = 'avatars/branch.png';
            $tenantUser->save();

            // Its own avatar wins — no fallback.
            $this->assertStringContainsString('branch.png', (string) $tenantUser->avatarUrl());
        });
    }
}
