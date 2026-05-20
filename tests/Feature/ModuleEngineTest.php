<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleException;
use App\Erp\Modules\ModuleManager;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModelField;
use App\Models\Ir\IrModule;
use App\Models\Ir\IrUiView;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ModuleEngineTest extends TestCase
{
    use DatabaseMigrations;

    private Filesystem $files;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->base = storage_path('framework/testing/erp-modules-' . uniqid());
        $this->scaffoldFixtures();
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_it_discovers_modules_from_disk(): void
    {
        $manifests = $this->manager()->discover();

        $this->assertArrayHasKey('demo', $manifests);
        $this->assertArrayHasKey('demo_child', $manifests);
        $this->assertSame('Demo', $manifests['demo']->displayName);
        $this->assertTrue($manifests['demo']->application);
    }

    public function test_sync_registers_modules_as_uninstalled(): void
    {
        $new = $this->manager()->sync();

        $this->assertSame(2, $new);
        $this->assertSame(
            ModuleState::Uninstalled,
            IrModule::query()->where('name', 'demo')->sole()->state,
        );
    }

    public function test_install_runs_migrations_and_populates_registry(): void
    {
        $this->manager()->install('demo');

        $module = IrModule::query()->where('name', 'demo')->sole();
        $this->assertSame(ModuleState::Installed, $module->state);
        $this->assertSame('1.0.0', $module->installed_version);
        $this->assertNotNull($module->installed_at);

        $this->assertTrue(Schema::hasTable('demo_widgets'));

        $irModel = IrModel::query()->where('model', 'demo.widget')->sole();
        $this->assertSame('demo_widgets', $irModel->table);
        $this->assertSame('demo', $irModel->module);

        $this->assertEqualsCanonicalizing(
            ['name', 'qty'],
            IrModelField::query()->where('ir_model_id', $irModel->id)->pluck('name')->all(),
        );

        $view = IrUiView::query()->where('model', 'demo.widget')->sole();
        $this->assertSame('list', $view->type);
        $this->assertSame(['columns' => ['name', 'qty']], $view->arch);
    }

    public function test_install_resolves_dependencies_first(): void
    {
        $this->manager()->install('demo_child');

        $this->assertSame(
            ModuleState::Installed,
            IrModule::query()->where('name', 'demo')->sole()->state,
        );
        $this->assertSame(
            ModuleState::Installed,
            IrModule::query()->where('name', 'demo_child')->sole()->state,
        );
    }

    public function test_uninstall_is_blocked_by_installed_dependents(): void
    {
        $this->manager()->install('demo_child');

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('still depends on it');

        $this->manager()->uninstall('demo');
    }

    public function test_uninstall_reverts_migrations_and_registry(): void
    {
        $manager = $this->manager();
        $manager->install('demo');

        $manager->uninstall('demo');

        $this->assertFalse(Schema::hasTable('demo_widgets'));
        $this->assertSame(0, IrModel::query()->where('module', 'demo')->count());
        $this->assertSame(0, IrUiView::query()->where('module', 'demo')->count());
        $this->assertSame(0, IrModelField::query()->count());
        $this->assertSame(
            ModuleState::Uninstalled,
            IrModule::query()->where('name', 'demo')->sole()->state,
        );
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager($this->files, $this->base, 'module.json', 'base');
    }

    private function scaffoldFixtures(): void
    {
        $demo = $this->base . '/demo';
        $this->files->ensureDirectoryExists($demo . '/database/migrations');
        $this->files->put($demo . '/module.json', (string) json_encode([
            'name' => 'demo',
            'display_name' => 'Demo',
            'version' => '1.0.0',
            'depends' => ['base'],
            'models' => ['Tests\\Fixtures\\Demo\\DemoWidget'],
            'application' => true,
        ], JSON_PRETTY_PRINT));

        $this->files->put(
            $demo . '/database/migrations/2026_01_01_000000_create_demo_widgets_table.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('demo_widgets', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                        $table->integer('qty')->default(0);
                        $table->timestamps();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('demo_widgets');
                }
            };
            PHP,
        );

        $child = $this->base . '/demo_child';
        $this->files->ensureDirectoryExists($child);
        $this->files->put($child . '/module.json', (string) json_encode([
            'name' => 'demo_child',
            'display_name' => 'Demo Child',
            'version' => '1.0.0',
            'depends' => ['demo'],
        ], JSON_PRETTY_PRINT));
    }
}
