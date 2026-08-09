<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Backup\DatabaseBackup;
use App\Livewire\Pages\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * In-app whole-database backup: a snapshot dumps every business table, and a
 * restore rolls the database back to it (a point-in-time rollback). Verified on
 * the core `users` table (present without any module install).
 */
final class DatabaseBackupTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    private function backup(): DatabaseBackup
    {
        return app(DatabaseBackup::class);
    }

    public function test_a_snapshot_creates_a_listed_file(): void
    {
        $path = $this->backup()->snapshot();

        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, $this->backup()->list());
        $this->assertTrue($this->backup()->owns($path));
    }

    public function test_listed_backup_time_uses_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Bahrain']);

        $this->backup()->snapshot();

        $created = $this->backup()->list()[0]['created_at'];
        $this->assertSame('Asia/Bahrain', $created->timezone->getName());
    }

    public function test_restore_brings_back_a_deleted_record(): void
    {
        $victim = User::factory()->create(['name' => 'Deleted Later', 'is_admin' => false]);
        $path = $this->backup()->snapshot();

        $victim->delete();
        $this->assertDatabaseMissing('users', ['id' => $victim->id]);

        $this->backup()->restore($path);

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Deleted Later']);
    }

    public function test_restore_removes_a_record_added_after_the_snapshot(): void
    {
        $path = $this->backup()->snapshot();

        $added = User::factory()->create(['name' => 'Added After']);
        $this->assertDatabaseHas('users', ['id' => $added->id]);

        $this->backup()->restore($path);

        $this->assertDatabaseMissing('users', ['id' => $added->id]);
    }

    public function test_restore_reverts_an_edit(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        $path = $this->backup()->snapshot();

        $user->update(['name' => 'Changed']);

        $this->backup()->restore($path);

        $this->assertSame('Original', $user->fresh()?->name);
    }

    public function test_purge_removes_snapshots_past_the_retention_window(): void
    {
        $fresh = $this->backup()->snapshot();
        $old = $this->backup()->snapshot();

        // Age the second file beyond the window.
        $oldAbsolute = Storage::disk('local')->path($old);
        touch($oldAbsolute, now()->subDays(DatabaseBackup::RETENTION_DAYS + 1)->getTimestamp());

        $removed = $this->backup()->purge();

        $this->assertSame(1, $removed);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($fresh);
    }

    public function test_the_page_backs_up_and_restores_with_a_password(): void
    {
        // Password the factory uses is "password".
        $victim = User::factory()->create(['name' => 'Gone', 'is_admin' => false]);

        Livewire::test(ActivityLog::class)->call('backupNow');
        $path = $this->backup()->list()[0]['path'];

        $victim->delete();

        Livewire::test(ActivityLog::class)
            ->call('confirmRestore', $path)
            ->set('restorePassword', 'password')
            ->call('restore');

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Gone']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'backup_restored']);
    }

    public function test_restore_is_refused_with_a_wrong_password(): void
    {
        $victim = User::factory()->create(['name' => 'Stay Gone', 'is_admin' => false]);
        $path = $this->backup()->snapshot();
        $victim->delete();

        Livewire::test(ActivityLog::class)
            ->call('confirmRestore', $path)
            ->set('restorePassword', 'wrong-password')
            ->call('restore')
            ->assertHasErrors('restorePassword');

        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
    }

    public function test_the_backups_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(ActivityLog::class)->assertForbidden();
    }
}
