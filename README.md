# Modules (Addons)

Each subdirectory here is a **self-contained ERP application**, modeled on Odoo's
addons. A module is discovered when it contains a `module.json` manifest.

## Layout

```
Modules/<ModuleName>/
  module.json                 manifest (name, version, depends, providers, models)
  Models/                     Eloquent models (implement App\Erp\Contracts\DefinesIrModel)
  Livewire/                   Livewire screen components
  Providers/                  <Module>ServiceProvider (thin; engine auto-wires)
  database/migrations/        module-owned schema (run by module:install)
  resources/views/            Blade views, namespaced "<module>::view"
  routes/web.php              loaded only while installed
```

PSR-4 (composer.json): `Modules\` → `Modules/`, so
`Modules\<ModuleName>\Models\Foo` → `Modules/<ModuleName>/Models/Foo.php`
(no `src/` segment). See `Modules/Contacts/` for the reference implementation.

## Lifecycle

| Command | Effect |
|---|---|
| `php artisan module:list` | show discovered modules + state |
| `php artisan module:sync` | register newly discovered modules into `ir_module` |
| `php artisan module:install <name>` | resolve deps, run migrations, register `ir_model`/`ir_model_fields`/`ir_ui_view`, mark installed |
| `php artisan module:uninstall <name>` | roll back migrations, purge registry rows, mark uninstalled |

See `CLAUDE.md` §4 for the architecture contract.
