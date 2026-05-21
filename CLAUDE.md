# CLAUDE.md — Project Operating Manual

> This file is the persistent source of truth for this project. Read it at the start of
> every session before doing any work. Keep it updated as architecture decisions are made.

---

## 1. Project Identity

**Name:** OpenERP-Laravel (working title) — an open-source, modular ERP.

**Mission:** Clone the architecture, extensibility model, and UX of **Odoo 19** using a
modern PHP/Laravel stack. The system is a *platform*: a thin ERP engine plus dynamically
installable application modules (CRM, Inventory, Accounting, Contacts, …), mirroring
Odoo's "addons" paradigm.

**Stack:**
| Layer | Technology | Installed |
|-------|-----------|-----------|
| Language | PHP **8.3+ (target)** | ⚠️ local env is **8.2.12** — see §6 |
| Framework | Laravel 11 | 11.52.0 |
| Reactive UI | Laravel Livewire 3 | v3.8.0 |
| Styling | TailwindCSS 3.4 (via Vite) | configured |
| Build | Vite 6 | configured |
| Static analysis | Larastan / PHPStan 2 | level 6 |
| Tests | PHPUnit 11 | 11.5.55 |
| DB (dev) | SQLite (`database/database.sqlite`) | migrated |

---

## 2. Coding Standards (NON-NEGOTIABLE)

1. **Strict types everywhere.** Every PHP file starts with:
   ```php
   <?php

   declare(strict_types=1);
   ```
2. **Complete type coverage.** Every method/function has explicit parameter types,
   property types, and an explicit **return type** (`void`, `never`, `self`, union types,
   etc.). No untyped `mixed` leaks unless genuinely unavoidable and documented.
3. **PHPStan level 6 must stay green.** Code is not "done" until `composer analyse` passes.
4. **Final by default.** Prefer `final` classes; open for extension only by deliberate design.
5. **No Facade soup in domain logic.** Inject dependencies; reserve Facades for glue code.
6. **Naming mirrors Odoo concepts** where it aids the mental model (`ir_module`,
   `mail.thread`/Chatter, `ir.ui.view`) but uses idiomatic Laravel/PSR class names.
7. **One module = one self-contained vertical** under `Modules/` (see §4).
8. Blade + Livewire for views; TailwindCSS utility classes — no bespoke CSS unless a
   utility cannot express it. Match Odoo 19's compact, dense, fast aesthetic.

---

## 3. Recurring CLI Commands

Run from project root. (Windows host; use `php artisan`, `./vendor/bin/...`.)

```bash
# --- App ---
php artisan serve                 # local dev server
npm run dev                       # Vite dev (hot reload) — run alongside serve
npm run build                     # production asset build

# --- Database ---
php artisan migrate               # apply migrations
php artisan migrate:fresh --seed  # rebuild DB from scratch + seed
php artisan migrate:rollback      # roll back last batch

# --- Quality gates (run BOTH before declaring work done) ---
composer analyse                  # PHPStan level 6  (alias -> ./vendor/bin/phpstan analyse)
composer test                     # PHPUnit suite    (alias -> php artisan test)
composer check                    # analyse + test in one shot

# --- Raw equivalents (if composer scripts unavailable) ---
./vendor/bin/phpstan analyse --no-progress
php artisan test

# --- Scaffolding ---
php artisan make:livewire <Name>
php artisan make:model <Name> -m
```

> **Pest note:** Pest 3 conflicts with the PHPUnit 11.5.55 pinned by the Laravel 11
> skeleton, and Pest 4 requires PHP 8.3+. We run **PHPUnit** for now. Migrate to Pest
> once the host is on PHP 8.3+ (then `composer require --dev pestphp/pest`).

---

## 4. Modular Architecture (Odoo Addons Paradigm)

Application modules live in **`Modules/<ModuleName>/`**, each a self-contained vertical:

PSR-4 is `Modules\\` → `Modules/` (composer.json), so a module's class
`Modules\Contacts\Models\Partner` lives at `Modules/Contacts/Models/Partner.php`
(NO `src/` segment — the manifest, code and resources sit directly under the
module root):

```
Modules/
  Contacts/
    module.json                 # manifest: name, version, depends, providers, models
    Models/                     # Eloquent models (DefinesIrModel / Chatterable)
    Livewire/                   # module screen components (full-page)
    Providers/                  # <Module>ServiceProvider (thin; engine auto-wires)
    database/migrations/        # module-owned schema (run by module:install)
    resources/views/            # Blade, namespaced "<module>::view"
    routes/web.php              # loaded only while installed
```

**Engine tables** (the `ir_*` registry, Phase 2) live in the core app and track the
platform state — modeled on Odoo's `ir.*` system models:

- `ir_module` — installed/available modules, version, state, dependency graph.
- `ir_model` & `ir_model_fields` — registry of system models + dynamic/custom fields.
- `ir_ui_view` — stored view metadata (list / kanban / form) so layouts are data-driven.

**Phase 2 — implemented engine (where things live):**

| Concern | Location |
|---|---|
| Registry models | `app/Models/Ir/{IrModule,IrModel,IrModelField,IrUiView}.php` |
| Module lifecycle | `app/Erp/Modules/{ModuleManager,ModuleManifest,ModuleException}.php` |
| Registry DTOs | `app/Erp/Registry/{Model,Field,View}Definition.php` |
| Model→registry hook | `app/Erp/Contracts/DefinesIrModel` (a module model implements `irModelDefinition()`) |
| State enum | `app/Erp/Enums/ModuleState` (uninstalled\|installed\|to_upgrade) |
| Boot wiring | `app/Providers/ModuleServiceProvider` (registered in `bootstrap/providers.php`) |
| Config | `config/erp.php` (`modules_path`, `manifest_file`, `core_module=base`) |
| Commands | `module:list` / `module:sync` / `module:install` / `module:uninstall` / `module:resync <name>` (re-reflect models/fields/views into `ir_*` after changing an `irModelDefinition()`, no schema change) |

A module is discovered via its `module.json`; `install` resolves `depends`, runs the
module's migrations, and reflects each declared `DefinesIrModel` class into
`ir_model` + `ir_model_fields` + `ir_ui_view`. `Modules/` is in `phpstan.neon` paths
and composer PSR-4 (`Modules\\` → `Modules/`). The implicit `base` dependency is
always satisfied (never resolved on disk).

**Phase 3 — implemented UX shell (where things live):**

| Concern | Location |
|---|---|
| Master layout | `resources/views/components/layouts/app.blade.php` (Livewire full-page layout) |
| App switcher | `App\Livewire\Navigation\AppSwitcher` → installed `application` modules |
| Command palette | `App\Livewire\Navigation\CommandPalette` (⌘K/Ctrl+K, fuzzy, `open-command-palette` event) |
| Contextual sidebar | `App\Livewire\Navigation\Sidebar` (driven by `/app/{module}` segment) |
| Chatter (`mail.thread`) | `App\Livewire\Chatter` + `App\Erp\Chatter\{HasChatter trait, Chatterable iface, ActivityBucket}` |
| Chatter storage | `mail_messages` / `mail_activities` / `mail_activity_types` + `App\Models\Mail\*` |
| Pages / routes | `App\Livewire\Pages\{Dashboard,ModuleHome}`; routes `/` and `/app/{module}` |
| Seeders | `MailActivityTypeSeeder`, `DemoAppSeeder` (placeholder apps), `DemoTicketSeeder` |

Any model gets a Chatter by `implements Chatterable use HasChatter`. Activity buckets:
`bucket()` derives Overdue/Today/Tomorrow/Planned from `due_date`; completed activities
stay (`done=true` → Done) and a `log` message is posted, so nothing vanishes.

- **Frontend build:** pages use `@vite` — run `npm run build` (or `npm run dev`) or
  rendering throws *ViteManifestNotFound*. The test suite needs the built manifest.
- **Demo apps:** `DemoAppSeeder` inserts placeholder `ir_module` rows (crm/sales/…)
  with no on-disk manifest, purely so the shell looks alive pre-Phase-5. Real modules
  are installed via `ModuleManager`; do not `module:uninstall` a seeded demo app.

**Phase 4 — implemented view engine (where things live):**

| Concern | Location |
|---|---|
| Arch parsing | `App\Erp\Views\ViewArch` + `ColumnDef` / `KanbanCard` / `RottingRule` (typed, defensive) |
| Resolution | `App\Erp\Views\ViewResolver` — stored `ir_ui_view` by priority, else auto-default from `ir_model_fields` |
| List view | `App\Livewire\Views\ListView` — multi-col sort (shift-click), checkbox bulk delete, footer aggregates (`sum`/`avg` over full set), pagination |
| Kanban view | `App\Livewire\Views\KanbanView` — group-by state, native HTML5 drag-drop → `moveCard()` transition (logs to Chatter if `Chatterable`), rotting cue |
| Demo | `App\Livewire\Pages\Playground` (`/playground`), `DemoViewSeeder` registers `demo.ticket` model+fields+list/kanban arch |

`arch` schema — **list:** `{columns:[{field,label,sortable,sum,avg,align,format}], default_sort:[{field,dir}], per_page}`.
**kanban:** `{group_by, stages:[{value,label}], card:{title,subtitle,badges[]}, rotting:{field,days}}`.
**form:** `{cols, fields:[{field,label,widget,required,placeholder,help,options,optionsFrom}]}`. A
`select` field is **model-sourced (a relation picker)** when it declares
`optionsFrom:{model,value?,label?,orderBy?,excludeSelf?}` — `App\Erp\Views\DynamicOptions`
parsed by `ViewArch`, resolved at render in `FormView::effectiveOptions()` (also drives the
`in:` rule; `excludeSelf` drops the edited record so a row can't point at itself — the
generic hierarchy/parent-picker primitive). No `optionsFrom` → static `options` as before.
Empty select (`—`) saves `null` (clears nullable FKs). **Use `optionsFrom` for any
relation field — never a bespoke picker component.**
Sort fields are whitelisted against arch columns (no raw `orderBy` injection). A model
needs an `ir_model`(+fields) row for the default-arch fallback; explicit `ir_ui_view`
rows always win. Phase 5's Contacts uses this exact mechanism via `DefinesIrModel`.
Enum-cast columns are normalised through `App\Erp\Views\ValueFormat` (`key()` for
grouping, `label()` for display) so List/Kanban stay generic across any model.

**Phase 5 — Contacts module (the reference addon):**

| Concern | Location |
|---|---|
| Manifest | `Modules/Contacts/module.json` (`application`, `depends:[base]`, `models`, `providers`) |
| Model | `Modules\Contacts\Models\Partner` — `implements Chatterable, DefinesIrModel; use HasChatter` |
| Schema | `Modules/Contacts/database/migrations/...create_partners_table.php` |
| UI | `Modules\Contacts\Livewire\{Partners,PartnerForm}` + `resources/views/{partners,partner-form}.blade.php` |
| Routes | `Modules/Contacts/routes/web.php` → `/app/contacts/partner[/new|/{id}]` |
| Form engine | `App\Livewire\Views\FormView` + `App\Erp\Views\FormFieldDef` (added in Phase 5) |

`Partner::irModelDefinition()` declares 11 fields + List/Kanban/Form arch (List &
Kanban carry `'open' => '/app/contacts/partner/{id}'` so rows/cards link to the Form).
Module **view namespaces are registered from filesystem discovery** (always), while
**routes/providers activate only when installed** — this also keeps Larastan's
view-string rule green.

Install flow:
```bash
php artisan migrate:fresh --seed        # engine + demo data (PartnerSeeder no-ops: no table yet)
php artisan module:install contacts     # creates `partners`, registers ir_model/fields/views
php artisan db:seed --class="Database\Seeders\PartnerSeeder"   # 6 demo partners
# Contacts now appears in the app-switcher → /app/contacts/partner
```

**Phase 6 — auth & access control (Odoo `res.groups` / `ir.model.access`):**

| Concern | Location |
|---|---|
| Schema | `users.is_admin`, `res_groups`, `res_group_user`, `ir_model_access` migrations |
| Models | `App\Models\User` (groups, isAdmin), `App\Models\Auth\{Group,ModelAccess}` |
| Service | `App\Erp\Security\AccessControl` + `Permission` enum (Read/Write/Create/Unlink) |
| Auth UI | `App\Livewire\Auth\Login` + `components/layouts/guest.blade.php`; `/login`, POST `/logout`. Login accepts **email *or* username** (`name`) — `users.email` is nullable so staff (e.g. POS cashiers) can be username-only |
| Routes | all app + module routes wrapped in `middleware('auth')`; `login` is the guest redirect target |
| Enforcement | `ListView`/`KanbanView`/`FormView` gate render (Read) + mutations (Create/Write/Unlink); `ListView` also hides the bulk-Delete button without `Unlink`; `Sidebar` filters by Read; topbar shows the real user; Chatter authorship = logged-in user |
| Seeded users | `admin@example.com / password` (superuser) · `sales@example.com / password` (Contacts user: RW+create, no delete; demo.ticket read-only). `PosStaffSeeder` (manual, not in default chain) adds username-only POS cashiers `ramadan`/`faraj`/`osama` in the `pos_user` group (**orders only**: `pos.session`/`pos.order` operate; **zero `pos.product` access** — no view/add/edit/delete of the catalogue; selling still works as the terminal isn't ACL-gated on products) |

Semantics: `is_admin` bypasses everything; otherwise **deny by default** — access needs
an `ir_model_access` row for the model owned by one of the user's groups (or a global
`group_id = null` rule). `AccessControl::authorize()` throws `AuthorizationException`
(→ HTTP 403; Livewire renders it as a forbidden response — assert with
`->assertForbidden()`). The app now requires login: `migrate:fresh --seed` runs
`AuthSeeder` first, then sign in at `/login`.

**Phase 7 — Point of Sale module (`Modules/Pos/`, depends on `contacts`):**

| Concern | Location |
|---|---|
| Schema | 7 base tables: `pos_categories/products/payment_methods/sessions/orders/order_lines/payments` + `product_recipes` (static BoM); `pos_products.stock_on_hand`, `pos_orders.components_consumed`. **User binding:** `pos_sessions.user_id` = who *opened* the shared register; `pos_orders.user_id` (cashier audit — `2026_05_19_200004`, nullable plain-indexed *logical ref*, populated in app logic); `pos_session_participants` (`2026_05_19_200005`: session+user unique, `last_activity` heartbeat) |
| Models | `Modules\Pos\Models\*` — `PosProduct/PosCategory/PosSession/PosOrder` are `DefinesIrModel`; `PosSession/PosOrder` are `Chatterable`; line/order recompute (discount → tax). `PosSession::user()`(opener) / `participants()`; `PosOrder::user()` (cashier) `belongsTo(User)`; `PosSessionParticipant` (presence) |
| Categories | `pos_categories` gained `parent_id` (logical ref) + `slug` (auto, unique) + `image` via `2026_05_19_200006`. `PosCategory` self-nests (`parent()`/`children()`/`subtreeIds()` — iterative, visited-set, cycle-safe); a `saving` guard nulls a parent that is self/descendant and auto-derives a unique slug. Managed by the **engine** (`DefinesIrModel`, `pos.category` in manifest `models[]`): List+Form CRUD at `/app/pos/category[/new|/{id}]` (`PosCategories`/`PosCategoryForm`), parent picker = dynamic `optionsFrom` select (`excludeSelf`). `PosProduct` form arch has a dynamic `pos_category_id` select. Terminal filters by **subtree** (`whereIn(categorySubtreeIds())`) and shows top-level + sub-category chips; search stays category-aware |
| Static ingredient consumption | `PosProductRecipe` (parent→component, `quantity_consumed`). `PosOrder::finalizeSale()` wraps `markPaid` + Done + `consumeComponents()` in **one DB transaction**; `consumeComponents()` decrements each component's `stock_on_hand` by `qty_consumed × line.qty`, idempotent via `components_consumed`. `PosProduct::theoreticalYield()` / `available_servings` accessor = limiting `floor(component.stock / qty)`. Recipe editor `PosRecipeEditor` on the product page; terminal tiles warn at ≤0 yield. Consumption is **strictly static** (no per-sale overrides) |
| Terminal | `Modules\Pos\Livewire\PosTerminal` — live cart (cart = a draft `PosOrder`), product grid + category filter + barcode search, customer search, split payments with change due, receipt. Draft order is stamped with the creating cashier (`user_id` = `Auth::id()` in `resolveDraftOrder`). Header shows the **current** cashier chip. `wire:poll.30s="heartbeat"` keeps the presence row fresh |
| Sessions | **Single GLOBAL register (singleton — replaced the earlier one-session-per-user model on 2026-05-19).** `Modules\Pos\Services\PosSessionManager` is the only entry point: `getActiveSession()` (the one open session or null), `openOrResume()` (locked get-or-create — any authorised user joins the existing session, never a 2nd), `heartbeat()`/`activeParticipants()`. `PosHome` shows **Open** (closed) or **Resume selling** (open, any user) + live cashier list. `PosSessionPage` has **no per-user ownership gate** (any `pos.session` Read may view/manage); **closing is manager-only** (`User::isAdmin()` + `pos.session` Write). Presence panels (`wire:poll.30s`) on home & session page; orders table shows per-order cashier. `PosSession::openForUser`/`isAccessibleBy` and the `$others`/`session-card` UI were **removed** |
| Routes | `/app/pos`, `/app/pos/order`, `/app/pos/product`, `/app/pos/category`, `/app/pos/session`, `/app/pos/session/{id}`, `/app/pos/session/{id}/terminal`, `/app/pos/product/{id\|new}`, `/app/pos/category/{id\|new}` (all `auth`); index routes back the sidebar's `pos.order/product/category/session` model entries |
| Seed | `PosSeeder` (8 products, 2 methods, `pos_user` group + ACLs; no-ops pre-install). Cashier policy: `pos_user` = **orders only** — full operate on `pos.session`/`pos.order` (no `unlink`), **no `pos.product` ACL at all**. `PosStaffSeeder` re-asserts this (idempotent) and deletes any stale `pos.product` grant |

Install (auto-pulls Contacts via dependency resolution):
```bash
php artisan module:install pos          # installs `contacts` first, then `pos`
php artisan db:seed --class="Database\Seeders\PosSeeder"
# → app-switcher → Point of Sale → Open session → register
```

**Deliberately OUT of scope** (say so if asked, offer as follow-ups): offline/PWA &
hardware/IoT (scanners, cash drawer, customer display), restaurant floors/tables/kitchen,
loyalty/gift cards/coupons, multi-currency, advanced tax (price-included, multi-tax,
fiscal positions), refunds/returns, and accounting/invoice posting. Known simplification:
cash reconciliation sums **payment amounts**; change given is computed (`change_due`) but
not posted as a drawer cash-out, so tendering over total slightly overstates expected cash
— use exact tender or treat `change_due` as informational.

---

## 5. Build Phases (roadmap & state tracker)

Keep this table current — it is how state survives across sessions.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Init: Laravel 11 + Livewire 3 + Tailwind + PHPStan/PHPUnit + this file | ✅ DONE |
| 2 | Modular addon arch + `ir_module` / `ir_model(_fields)` / `ir_ui_view` | ✅ DONE |
| 3 | Odoo 19 UX: master layout, app switcher, ⌘K command palette, sidebar, Chatter (`mail.thread`) | ✅ DONE |
| 4 | Dynamic view engine: List (sort/bulk/aggregate) + Kanban (drag-drop + rotting indicator) | ✅ DONE |
| 5 | First module: **Contacts** (`Partner` model + Form/List/Kanban + Chatter) | ✅ DONE |
| 6 | Auth & access control: login, `res_groups`, `ir_model_access`, enforced in views | ✅ DONE |
| 7 | **Point of Sale** module: sessions, terminal, payments, receipts, reconciliation | ✅ DONE |
| 8 | **Settings**: `ir_config_parameter` + cached `SettingManager`/`Setting` facade + admin Settings page | ✅ DONE (General tab; POS/Inventory tabs + wiring the static-consumption toggle = next increments) |
| 9 | **Inventory**: double-entry schema + Overview Kanban + atomic pickings/transfer flow | ✅ (adjustment/replenishment/lots/valuation+forecast/barcode = next increments) |
| 10 | **WhatsApp**: Meta Cloud API integration (queued messaging, Chatter button, automations, webhook) | ✅ config+log schema, queued `sendTemplateMessage()`, message-log lifecycle, secure webhook (verify + HMAC), admin Settings tab, **POS auto-receipt** (`PosOrderPaid` event → `SendPosOrderReceiptViaWhatsApp` listener → `pos_receipt` template; phone captured at checkout via dial-code dropdown + local digits, composed with leading-zero strip via `PosWhatsAppCountries`) — templates table/UI · Chatter button · other event automations · media/PDF attachments = next increments |

**Phase 8 — Settings (where things live):**

| Concern | Location |
|---|---|
| Schema | `database/migrations/..._create_ir_config_parameter_table` — `key`(uniq), `value`(text), `type`(string\|bool\|number), `group`, `label`, `description`, `sort` |
| Model | `App\Models\Ir\IrConfigParameter` |
| Service | `App\Erp\Settings\SettingManager` (whole set cached `rememberForever` under `erp.settings.all`; `get/set/setMany/grouped/flush`; writes flush) + `App\Erp\Settings\Setting` facade (singleton bound in `AppServiceProvider`) |
| UI | `App\Livewire\Pages\SettingsPage` (**admin-only** `abort 403`; index-keyed `$form` so dotted keys aren't read as nested; group→tabs; bool=toggle/number/string controls; bulk Save → `setMany` → cache flush) → `resources/views/livewire/pages/settings.blade.php` |
| Route/Nav | `/app/settings` (named `settings`, registered **before** `/app/{module}`); admin-only link in `Sidebar` |
| Seed | `SettingSeeder` (General: `company.name`, `currency.default`, `company.timezone`, `company.language`) — non-destructive (keeps saved values), in default `DatabaseSeeder` chain |

Usage: `Setting::get('company.name')`, `Setting::set('currency.default', 'EUR')`. App-switcher still shows the demo `settings` tile to all (ACL enforced on click → non-admins 403), like other apps.

**Phase 9 — Inventory scaffold (where things live):**

| Concern | Location |
|---|---|
| Schema | `Modules/Inventory/database/migrations/*` — `warehouses`, `stock_locations` (hierarchical `parent_id` + `type`), `stock_operation_types`, `stock_moves` (src→dest, state, `lot_name`/`barcode` scaffold), `stock_quants` (on-hand per location, unique loc+product+lot) |
| Enums | `Modules\Inventory\Enums\{LocationType,MoveState}` — LocationType: Vendor/View/Internal/Customer/Inventory/Production/Transit |
| Models | `Modules\Inventory\Models\{Warehouse,StockLocation,StockMove,StockOperationType,StockQuant}`. `StockMove::affectsValuation()` = the double-entry rule (value only changes crossing the Internal boundary to/from Customer/Vendor). `product_id` is a **logical** ref (Inventory decoupled — no FK to a catalogue) |
| Dashboard | `Modules\Inventory\Livewire\InventoryOverview` (`/app/inventory`) → one Kanban card per `StockOperationType` with **live** `toProcessCount()`/`lateCount()` + KPI strip; New/View-All deep-link to the transfers pages |
| Pickings flow | `StockMove::process()` = atomic validate (one `DB::transaction`: debit source quant, credit dest quant via `firstOrNew`, state→Done; idempotent). `StockTransfers` (`/app/inventory/transfers?type=`) list + Validate; `StockTransferForm` (`/app/inventory/transfers/new`) create (op-type defaults). Logical moves (`product_id` null) skip quant changes |
| Seed | `InventorySeeder` (Main Warehouse, full location topology incl. WH/Stock/Aisle A/Shelf 1, 4 operation types, demo moves) — guarded/idempotent, in `DatabaseSeeder` |
| Install | demo `inventory` was a placeholder; real install = reset its `ir_module` state then `module:install inventory` (runs migrations), then `db:seed --class=Database\Seeders\InventorySeeder` |

`models:[]` in the manifest (not yet `DefinesIrModel`), so no sidebar list entries / index routes this increment — the Overview is the entry point.

**Phase 10 — WhatsApp module (where things live):**

| Concern | Location |
|---|---|
| Manifest | `Modules/WhatsApp/module.json` (`depends:[base]`, `application:false`, `models:[]`, sequence 14) — surface is a Settings tab + (future) Chatter button, not a standalone app screen |
| Schema | `2026_05_19_300001_create_whatsapp_configuration_table` (single-row config; secrets are **TEXT holding APP_KEY-encrypted ciphertext**) + `2026_05_19_300002_create_whatsapp_messages_log_table` (`wamid`, `direction`, `status`, `payload`, `related_*` for future doc correlation) |
| Models | `WhatsAppConfiguration` (`encrypted` casts on the 3 secrets; `current()` firstOrNew **never null** — note unsaved instance has null `api_version`/`enabled`, callers must coalesce; `isConfigured()`, `graphEndpoint()`) · `WhatsAppMessageLog` (`payload` array cast; `DIRECTION_*` consts) |
| Service | `WhatsAppService::sendTemplateMessage($to,$template,$variables,$lang)` — builds Graph payload (positional `$variables` → body `{{1}},{{2}}`), normalises number, **creates a `queued` log row**, **dispatches** the job (never blocks UI). Singleton in `WhatsAppServiceProvider`; resolvable zero-config even when module not installed |
| Queued send | `SendWhatsAppMessage` (`ShouldQueue`, `tries=3`, 30s backoff, `?logId`) — POSTs via injected `Http\Factory`; success → log `sent` + stores `wamid`; non-2xx → log `failed` + throw → retry → `failed_jobs`. Queue driver `database` (already in `.env`) |
| Webhook | `Http\Controllers\WebhookController` — **GET** verify (constant-time `hub.verify_token` check, echoes `hub.challenge`) · **POST** verify `X-Hub-Signature-256` HMAC of raw body keyed by `app_secret`, then status callbacks advance the matching outbound log by `wamid`, inbound messages stored as `received` rows. Routes `Modules/WhatsApp/routes/web.php`: `/whatsapp/webhook` GET+POST are **public (not in `auth`)**; `/app/settings/whatsapp` is `auth` |
| CSRF | `bootstrap/app.php` → `validateCsrfTokens(except: ['whatsapp/webhook'])` — module routes load inside the `web` group, so the Meta POST needs this global exception (path, no leading slash) |
| Settings UI | `Modules\WhatsApp\Livewire\WhatsAppSettings` (**admin-only** `abort 403`) + `whatsapp::settings`; secrets are **write-only** (never echoed; blank on save = keep). Surfaced as a tab via `resources/views/partials/settings-nav.blade.php` (`@include`d by both the core settings view and this one; shows the WhatsApp pill only when the module is `Installed`) |
| Errors | `Modules\WhatsApp\Exceptions\WhatsAppException` (config missing/disabled, or non-2xx Graph response) |
| Tests | `tests/Feature/WhatsAppModuleTest.php` (11) — install schema, encrypted-at-rest save, admin gate, service→queued-log, job sent/failed, webhook verify/signature/status+inbound. Webhook tested by calling the controller directly (module routes only register on boot **after** install — the known engine gap) |

Install (migrations run via the engine): `php artisan module:install whatsapp`. Then
configure under **Settings → WhatsApp** (admin) and register the webhook URL shown there
in Meta. **Not yet built** (next increments): `whatsapp_templates` table + parser UI, the
Chatter "WhatsApp" button, event-triggered automations (e.g. POS Paid → receipt),
media/PDF attachment URLs, and inbound→Chatter document correlation (`related_*`).

---

## 6. Known Environment Caveats

- **PHP version:** project standard is **8.3+**, but the dev host runs **PHP 8.2.12**.
  `composer.json` requires `^8.2` for local compatibility. Laravel 11 supports 8.2, so
  everything works today. Before relying on 8.3-only syntax (e.g. typed class constants,
  `json_validate()`, `#[Override]`), confirm the host has been upgraded.
- **DB:** dev uses SQLite. Production target is PostgreSQL/MySQL — keep migrations
  driver-agnostic (avoid SQLite-specific column types).
- **Test DB isolation:** `phpunit.xml` pins tests to in-memory SQLite
  (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). This is mandatory — without it,
  `DatabaseMigrations`/`RefreshDatabase` tests run against and **wipe the dev DB**.
  Do not re-comment those lines.
- **Module install atomicity:** `ModuleManager` wraps migrate+registry in a
  transaction. SQLite/PostgreSQL have transactional DDL so rollback is clean; **MySQL
  auto-commits DDL**, so a mid-install failure on MySQL can leave partial tables.
- **Incremental module migrations are NOT auto-applied to the live DB.** A module's
  migrations run only at `module:install`/`uninstall` (the engine does not
  `loadMigrationsFrom`, so plain `php artisan migrate` ignores module dirs). A migration
  added to an **already-installed** module sits *Pending* on `database/database.sqlite`
  forever and the running app 500s — yet the whole PHPUnit suite stays green because
  `DatabaseMigrations` rebuilds in-memory from scratch every test. After adding an
  incremental migration to an installed module, run it on the live DB yourself:
  `php artisan migrate --path=Modules/<Module>/database/migrations --force` (idempotent;
  check with `migrate:status --path=...`). Likewise, after editing a model's
  `irModelDefinition()` (fields/arch, no schema change) run `php artisan module:resync
  <module>` to re-reflect it into `ir_model(_fields)` / `ir_ui_view`. Always do this for
  every module touched and state it in the hand-off — don't let the user find it via a 500.
- **OS:** development host is Windows. Prefer cross-platform tooling and forward slashes
  in code; never hardcode `C:\` paths.

---

## 7. Definition of Done (every task)

1. Strict types + full return types in all touched PHP files.
2. `composer analyse` green (PHPStan level 6).
3. `composer test` green.
4. Phase status table in §5 updated if a phase milestone was reached.
