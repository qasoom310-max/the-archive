<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Chatter\Chatterable;
use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Views\ViewResolver;
use App\Livewire\Views\FormView;
use App\Livewire\Views\KanbanView;
use App\Livewire\Views\ListView;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\Ir\IrUiView;
use App\Models\Mail\MailActivityType;
use App\Models\Mail\MessageType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Contacts\Models\Partner;
use Tests\TestCase;

final class ContactsModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function install(): void
    {
        app(ModuleManager::class)->install('contacts');
    }

    public function test_partner_satisfies_engine_contracts(): void
    {
        $this->assertInstanceOf(DefinesIrModel::class, new Partner());
        $this->assertInstanceOf(Chatterable::class, new Partner());

        $def = Partner::irModelDefinition();
        $this->assertSame('contacts.partner', $def->model);
        $this->assertSame(Partner::class, $def->class);
        $this->assertCount(3, $def->views); // list, kanban, form
    }

    public function test_install_creates_table_and_registry(): void
    {
        $this->install();

        $module = IrModule::query()->where('name', 'contacts')->sole();
        $this->assertSame(ModuleState::Installed, $module->state);
        $this->assertTrue($module->application);
        $this->assertTrue(Schema::hasTable('partners'));

        $irModel = IrModel::query()->where('model', 'contacts.partner')->sole();
        $this->assertSame(Partner::class, $irModel->class);
        $this->assertSame('contacts', $irModel->module);
        $this->assertEqualsCanonicalizing(
            ['name', 'is_company', 'image_path', 'email', 'phone', 'street', 'street2', 'city', 'zip', 'state', 'country'],
            $irModel->fields()->pluck('name')->all(),
        );

        foreach (['list', 'kanban', 'form'] as $type) {
            $this->assertTrue(
                IrUiView::query()->where('model', 'contacts.partner')->where('type', $type)->exists(),
                "missing {$type} view",
            );
        }
    }

    public function test_uninstall_reverts_partner_table_and_registry(): void
    {
        $this->install();
        app(ModuleManager::class)->uninstall('contacts');

        $this->assertFalse(Schema::hasTable('partners'));
        $this->assertSame(0, IrModel::query()->where('model', 'contacts.partner')->count());
        $this->assertSame(
            ModuleState::Uninstalled,
            IrModule::query()->where('name', 'contacts')->sole()->state,
        );
    }

    public function test_resolver_returns_stored_form_arch(): void
    {
        $this->install();

        $arch = app(ViewResolver::class)->arch('contacts.partner', 'form');

        $this->assertCount(11, $arch->formFields);
        $this->assertSame('name', $arch->formFields[0]->field);
        $this->assertTrue($arch->formFields[0]->required);
        $this->assertSame('image', $arch->formFields[2]->widget);
    }

    public function test_formview_creates_partner_and_logs_chatter(): void
    {
        $this->install();

        Livewire::test(FormView::class, [
            'model' => Partner::class,
            'modelKey' => 'contacts.partner',
        ])
            ->set('form.name', 'Acme Corp')
            ->set('form.email', 'hi@acme.test')
            ->set('form.is_company', true)
            ->call('save');

        $partner = Partner::query()->where('name', 'Acme Corp')->sole();
        $this->assertTrue($partner->is_company);
        $this->assertTrue(
            $partner->messages()->where('type', MessageType::Log)->where('body', 'Record created.')->exists(),
        );
    }

    public function test_formview_validates_required_name(): void
    {
        $this->install();

        Livewire::test(FormView::class, ['model' => Partner::class, 'modelKey' => 'contacts.partner'])
            ->set('form.name', '')
            ->set('form.email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.email']);
    }

    public function test_partner_chatter_and_activities(): void
    {
        $this->install();
        $type = MailActivityType::query()->create(['name' => 'To Do', 'icon' => 'x']);

        $partner = Partner::query()->create(['name' => 'Jane', 'email' => 'jane@x.test']);
        $partner->postNote('Followed up by phone.', 'Administrator');
        $partner->scheduleActivity($type, 'Send quote', now()->addDay());

        $this->assertSame(1, $partner->messages()->where('type', MessageType::Note)->count());
        $this->assertSame(1, $partner->activities()->count());
    }

    public function test_listview_uses_partner_stored_arch(): void
    {
        $this->install();
        Partner::query()->create(['name' => 'Zeta Ltd', 'email' => 'z@zeta.test', 'is_company' => true]);

        Livewire::test(ListView::class, ['model' => Partner::class, 'modelKey' => 'contacts.partner'])
            ->assertSet('sorts', [['field' => 'name', 'dir' => 'asc']])
            ->assertSee('Zeta Ltd')
            ->assertSee('z@zeta.test');
    }

    public function test_kanban_groups_by_country_and_move_logs_transition(): void
    {
        $this->install();
        $partner = Partner::query()->create(['name' => 'Globex', 'country' => 'France']);

        Livewire::test(KanbanView::class, ['model' => Partner::class, 'modelKey' => 'contacts.partner'])
            ->assertSee('France')
            ->call('moveCard', $partner->id, 'Germany');

        $partner->refresh();
        $this->assertSame('Germany', $partner->country);
        $this->assertTrue(
            $partner->messages()->where('body', 'Stage: France → Germany')->exists(),
        );
    }
}
