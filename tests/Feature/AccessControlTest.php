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

        // Login lands on the database chooser (which sends single-database /
        // locked users straight in) rather than dropping into a remembered one.
        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('workspaces.choose'));

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

    public function test_a_paused_users_very_next_request_signs_them_out(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        $this->get('/')->assertOk();

        $user->forceFill(['is_paused' => true])->save();

        $this->get('/')->assertStatus(419);
        $this->assertGuest();
    }

    public function test_a_paused_user_cannot_sign_in_even_with_the_right_password(): void
    {
        User::factory()->create([
            'email' => 'paused@example.com',
            'is_paused' => true,
        ]);

        Livewire::test(Login::class)
            ->set('email', 'paused@example.com')
            ->set('password', 'password')
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

    // ---- Engine hardening -------------------------------------------------

    public function test_an_empty_model_key_denies_instead_of_allowing(): void
    {
        // The permission check used to short-circuit to ALLOW on a blank key.
        // Since `modelKey` is a Livewire property, that made a blank key a
        // wildcard past every check. It must fail closed.
        $this->actAsSalesUser();

        Livewire::test(ListView::class, ['model' => DemoTicket::class, 'modelKey' => ''])
            ->assertSee('Access denied');

        $ticket = DemoTicket::query()->create(['subject' => 'Untouched', 'stage' => 'New']);

        Livewire::test(KanbanView::class, ['model' => DemoTicket::class, 'modelKey' => ''])
            ->call('moveCard', $ticket->id, 'Done')
            ->assertForbidden();

        $this->assertSame('New', $ticket->fresh()?->stage);
    }

    public function test_the_binding_properties_cannot_be_repointed_by_the_browser(): void
    {
        // #[Locked] on model / modelKey / recordId: without it a user could
        // repoint a form they may legitimately open at another model or row.
        $this->actAsSalesUser();

        foreach (['model', 'modelKey', 'recordId'] as $property) {
            $component = Livewire::test(FormView::class, [
                'model' => Partner::class,
                'modelKey' => 'contacts.partner',
            ]);

            try {
                $component->set($property, $property === 'recordId' ? 1 : 'App\\Models\\User');
                $this->fail("Property [{$property}] should be locked against client updates.");
            } catch (\Throwable $e) {
                $this->assertStringContainsString('locked', mb_strtolower($e->getMessage()));
            }
        }
    }

    public function test_upload_paths_cannot_write_undeclared_attributes(): void
    {
        // The image/file buffers are public properties, so their KEYS are
        // attacker-controlled. Only attributes declared as an image/file field
        // in the arch may be written — otherwise a user with legitimate Write
        // on a model could set any column of it.
        $this->actAsSalesUser();
        $partner = Partner::query()->create(['name' => 'Acme']);

        Livewire::test(FormView::class, [
            'model' => Partner::class,
            'modelKey' => 'contacts.partner',
            'recordId' => $partner->id,
        ])
            ->set('imagePaths', ['name' => 'HACKED', 'email' => 'hacked@example.com'])
            ->set('filePaths', ['name' => 'HACKED-TOO'])
            ->call('save');

        $fresh = $partner->fresh();
        $this->assertSame('Acme', $fresh?->name);
        $this->assertNotSame('hacked@example.com', $fresh?->email);
    }
}
