<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Pages\ActivityLog as ActivityLogPage;
use App\Livewire\Pages\SettingsPage;
use App\Livewire\Settings\UserManager;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin-only audit trail: a row per meaningful action, with the actor's
 * name/role snapshotted, surfaced on the /activity page.
 */
final class ActivityLogTest extends TestCase
{
    use DatabaseMigrations;

    public function test_logger_snapshots_the_actor_and_writes_a_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Boss']);
        $this->actingAs($admin);

        app(ActivityLogger::class)->log('created', 'Partner #1', 'some detail');

        $log = ActivityLog::query()->where('action', 'created')->firstOrFail();
        $this->assertSame((int) $admin->getKey(), (int) $log->user_id);
        $this->assertSame('Boss', $log->user_name);
        $this->assertTrue($log->user_is_admin);
        $this->assertSame('Partner #1', $log->subject);
        $this->assertNotNull($log->created_at);
    }

    public function test_login_event_is_audited(): void
    {
        $user = User::factory()->create(['name' => 'Faraj']);

        event(new Login('web', $user, false));

        $this->assertTrue(
            ActivityLog::query()->where('action', 'login')->where('user_id', $user->getKey())->exists(),
        );
    }

    public function test_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(ActivityLogPage::class)->assertForbidden();
    }

    public function test_page_lists_entries_and_filters_by_action(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        ActivityLog::query()->create([
            'user_id' => 1, 'user_name' => 'Alice', 'user_is_admin' => true,
            'action' => 'login', 'subject' => null, 'created_at' => Carbon::now(),
        ]);
        ActivityLog::query()->create([
            'user_id' => 2, 'user_name' => 'Bob', 'user_is_admin' => false,
            'action' => 'deleted', 'subject' => 'Partner', 'created_at' => Carbon::now(),
        ]);

        Livewire::test(ActivityLogPage::class)
            ->assertSee('Alice')
            ->assertSee('Bob')
            ->set('action', 'login')
            ->assertSee('Alice')
            ->assertDontSee('Bob');
    }

    public function test_creating_a_user_is_audited(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        $mainId = (int) app(WorkspaceManager::class)->ensureMain()->id;

        Livewire::test(UserManager::class)
            ->set('name', 'New Staff')
            ->set('email', 'newstaff@example.com')
            ->set('password', 'secret12')
            ->set('apps', ['pos'])
            ->set('workspaces', [$mainId])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(
            ActivityLog::query()->where('action', 'user_created')->where('subject', 'newstaff@example.com')->exists(),
        );
    }

    public function test_saving_settings_is_audited(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Setting::set('company.name', 'Old Name');

        $component = Livewire::test(SettingsPage::class);
        // Find the company.name row index in the data-driven form.
        $form = $component->get('form');
        $index = collect($form)->search(fn (array $row): bool => $row['key'] === 'company.name');
        $this->assertNotFalse($index);

        $component->set('form.' . $index . '.value', 'New Name')->call('save');

        $this->assertTrue(ActivityLog::query()->where('action', 'settings_updated')->exists());
        $this->assertSame('New Name', Setting::get('company.name'));
    }
}
