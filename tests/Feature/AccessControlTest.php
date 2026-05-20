<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Livewire\Auth\Login;
use App\Livewire\Views\FormView;
use App\Livewire\Views\KanbanView;
use App\Livewire\Views\ListView;
use App\Models\Auth\Group;
use App\Models\Auth\ModelAccess;
use App\Models\Demo\DemoTicket;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\DemoViewSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Contacts\Models\Partner;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    use DatabaseMigrations;

    // ---- Auth flow --------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/playground')->assertRedirect(route('login'));
    }

    public function test_login_screen_renders_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_successful_login_authenticates_and_redirects(): void
    {
        $this->seed(AuthSeeder::class);

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->seed(AuthSeeder::class);

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    // ---- AccessControl service -------------------------------------------

    public function test_admin_bypasses_all_checks(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $acl = app(AccessControl::class);

        $this->assertTrue($acl->allows($admin, 'anything.at.all', Permission::Unlink));
    }

    public function test_group_rules_grant_only_their_permissions(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $group = Group::query()->create(['name' => 'G', 'code' => 'g']);
        $user->groups()->attach($group);
        ModelAccess::query()->create([
            'name' => 'r', 'model' => 'x.y', 'group_id' => $group->id,
            'perm_read' => true, 'perm_write' => false,
            'perm_create' => false, 'perm_unlink' => false,
        ]);

        $acl = app(AccessControl::class);

        $this->assertTrue($acl->allows($user, 'x.y', Permission::Read));
        $this->assertFalse($acl->allows($user, 'x.y', Permission::Write));
        $this->assertFalse($acl->allows($user, 'x.y', Permission::Read)
            && $acl->allows($user, 'other.model', Permission::Read));
    }

    public function test_deny_by_default_and_for_guests(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $acl = app(AccessControl::class);

        $this->assertFalse($acl->allows($user, 'x.y', Permission::Read));
        $this->assertFalse($acl->allows(null, 'x.y', Permission::Read));
    }

    // ---- Enforcement in the view engine ----------------------------------

    private function actAsSalesUser(): void
    {
        app(ModuleManager::class)->install('contacts');
        $this->seed(AuthSeeder::class);
        $this->seed(DemoViewSeeder::class);
        $sales = User::query()->where('email', 'sales@example.com')->sole();
        $this->actingAs($sales);
    }

    public function test_sales_user_can_create_partner_but_not_delete(): void
    {
        $this->actAsSalesUser();

        // create is granted
        Livewire::test(FormView::class, ['model' => Partner::class, 'modelKey' => 'contacts.partner'])
            ->set('form.name', 'Allowed Co')
            ->call('save');

        $partner = Partner::query()->where('name', 'Allowed Co')->sole();

        // delete (unlink) is not granted → 403, record survives
        Livewire::test(ListView::class, ['model' => Partner::class, 'modelKey' => 'contacts.partner'])
            ->set('selected', [$partner->id])
            ->call('bulkDelete')
            ->assertForbidden();

        $this->assertDatabaseHas('partners', ['name' => 'Allowed Co']);
    }

    public function test_sales_user_cannot_write_read_only_model(): void
    {
        $this->actAsSalesUser();
        $ticket = DemoTicket::query()->create(['subject' => 'RO', 'stage' => 'New']);

        Livewire::test(KanbanView::class, ['model' => DemoTicket::class, 'modelKey' => 'demo.ticket'])
            ->call('moveCard', $ticket->id, 'Done')
            ->assertForbidden();

        $this->assertSame('New', $ticket->fresh()?->stage);
    }

    public function test_unauthorized_model_renders_forbidden(): void
    {
        $this->actAsSalesUser();

        // sales user has no rule for "secret.model"
        Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => 'secret.model'])
            ->assertSee('Access denied');
    }
}
