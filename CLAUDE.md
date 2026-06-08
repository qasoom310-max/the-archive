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
9. **i18n + RTL discipline.** The app supports English and Arabic (Phase 12). Every
   new user-facing string MUST be wrapped in `__()` AND added to `lang/ar.json` in the
   same change — missing keys silently render as the English source, regressing the
   Arabic experience without warning. Any new layout utility with directionality
   (`mr-`/`ml-`/`pr-`/`pl-`/`right-X`/`left-X`/`text-left`/`text-right`/`rounded-l-*`/
   `border-l-*`/`origin-top-left` etc.) MUST be a logical equivalent
   (`me-`/`ms-`/`pe-`/`ps-`/`end-X`/`start-X`/`text-start`/`text-end`/`rounded-s-*`/
   `border-s-*`/`origin-top-start`) so `dir="rtl"` mirrors the layout. Carve-outs that
   stay English by design: WhatsApp settings tab content, brand names ("OpenERP" /
   "WhatsApp"), ISO codes + currency symbols, user-entered data, CLI snippets,
   keyboard shortcuts. Money values go through `App\Erp\Money\Currencies::format()` —
   never raw `number_format()` on amounts.

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
| Kanban view | `App\Livewire\Views\KanbanView` — group-by state, native HTML5 drag-drop → `moveCard()` transition (logs to Chatter if `Chatterable`), rotting cue. Toolbar `$search` (URL-bound, `LIKE` across arch-declared `searchable`) + IntersectionObserver lazy-load `loadMore()` for ungrouped catalogue boards (initial = `arch.per_page` ?? 12, scroll bottom → bump by the same step). Grouped (state-machine) boards skip lazy-load — they're workflows, not catalogues. **Rigid card layout**: each card is `flex h-full flex-col`, the image strip is a **fixed pixel height** (`h-40` ≈ 160 px, NOT an aspect ratio — aspect ratios scale with column width and produced uneven heights across photos with different intrinsics); body uses `flex-1` + `mt-auto` on the footer block so meta/badges pin to the bottom and titles top-align. Grid wrapper carries `auto-rows-fr` so every row in the catalogue grid shares the tallest row's height |
| Demo | `App\Livewire\Pages\Playground` (`/playground`), `DemoViewSeeder` registers `demo.ticket` model+fields+list/kanban arch |

`arch` schema — **list:** `{columns:[{field,label,sortable,sum,avg,align,format,hidden_by_default,sort_field}], default_sort:[{field,dir}], per_page, filters, custom_date_field, searchable:[fieldName,...]}`.
**kanban:** `{group_by, stages:[{value,label}], card:{title,subtitle,badges[],image,meta:[{field,label,format}]}, rotting:{field,days}, per_page, searchable:[fieldName,...]}` (the last two activate the toolbar search box and IntersectionObserver lazy-load on ungrouped boards).
**form:** `{cols, fields:[{field,label,widget,required,placeholder,help,options,optionsFrom,translatable}]}`. A
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

**Phase 4 increments (shipped 2026-05-23 / 2026-05-24):**

- **`format: toggle`** — list-view column type that renders an inline iOS-style switch
  in the cell. One click flips the value server-side via
  `ListView::toggleBoolean(int|string $id, string $field)` — arch-whitelisted (only
  fields declared with `format: toggle` are mutable) and Write-gated through
  `AccessControl`. Used by `PosProduct.active` so staff hide a discontinued product
  without opening the form. `'toggle'` added to the format whitelist in
  `ViewArch::parseColumns`; the Blade switch lives in `list-view.blade.php`.
- **`hidden_by_default: true`** on a column — declared in arch, parsed into
  `ColumnDef::$hiddenByDefault`. Default state for the per-user column picker
  (below). User explicitly toggling a hidden-by-default column ON wins and
  persists across sessions. Used to declutter `PosProduct` list (tax/margin/
  barcode/stock are off by default).
- **Per-user column picker** (engine-generic). 3-dots icon in the list-view toolbar
  opens a popover that lists every arch column with a show/hide toggle + drag handle
  for reorder. Persisted in DB per (user, model). Schema:
  `2026_05_24_100001_create_user_view_preferences_table` — `user_id` (FK cascade),
  `model_key`, `hidden_columns` (JSON), `column_order` (JSON), `unique(user_id,
  model_key)` named `uvp_user_model_unique` (short to dodge MySQL's 64-char index
  cap — memory: `[[mysql-index-name-64-char-cap]]`). Model:
  `App\Models\UserViewPreference::forUserAndModel($userId, $modelKey)`. ListView
  state: `$hiddenColumns`, `$columnOrder`; helpers `loadUserColumnPreferences()`,
  `visibleColumns()`, `toggleColumn()`, `reorderColumns()`,
  `persistColumnPreferences()`. Dropdown wears `wire:ignore` so Alpine drag
  listeners survive Livewire morphs.
- **Alpine `$wire` proxy gotcha** — storing `this.wire = wire` in `Alpine.data(...)`
  wraps the Livewire shim in Alpine's reactivity proxy, which intercepts `.call()`
  and routes through Vue's `__v_raw` accessor → `MethodNotFoundException`. Always
  closure-capture: `Alpine.data('foo', () => ({ init(el, wire) { /* use wire
  directly */ } }))`. Memory: `[[livewire-wire-on-alpine-this]]`.
- **Kanban card image + meta** — `KanbanCard` extended with `?string $image` and
  `array $meta` (list of `{field, label, format}`). `ViewArch::parseCard` parses
  both; meta `format` whitelisted to `money|number|date|datetime|bool`.
  `kanban-view.blade.php` renders a square image hero (with neutral SVG
  placeholder when the column is declared but the row is empty — same height
  cards) and a `<dl>` meta footer with label-on-start, value-on-end. Money rows
  go through `Currencies::format()`. Used by `PosProduct` for Odoo-style product
  cards (`image_path` + price/stock meta).
- **Sliding-window pagination** (`resources/views/vendor/pagination/compact.blade.php`).
  Always shows `[1, 2, …, current−1, current, current+1, …, last]` collapsed to
  unique sorted pages with gap-insertion. Replaces the prior layout which hid the
  active page behind an ellipsis on deep pages.
- **Ungrouped kanban → responsive grid** — `kanban-view.blade.php` branches on
  `$groupBy === null` (catalogue-style boards with no stages). Those render as
  a `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4` of
  cards (no swimlane wrapper, no drop handlers — there's nothing to transition
  between). Grouped boards (POS orders by state, demo tickets) keep the
  horizontal swimlane + drag-drop. Used by `PosProduct` so the product
  catalogue tiles across the page instead of stacking in a single 288px column.
- **Toolbar free-text search** — `arch.searchable: [field1, field2, ...]` opts a
  list view into a primary-themed pill search input rendered next to "X total"
  in the toolbar. Empty / absent = no input rendered. `ListView::$search`
  (`#[Url(except: '')]`) carries the query; `applySearch()` applies a single
  OR-grouped `LIKE '%q%'` across the declared fields, and is run on BOTH the
  paginated query AND every aggregate query so footer totals track the search
  scope. `wire:model.live.debounce.300ms` so results stream as the user types
  without spamming the server. Typing on a deep page calls `updatedSearch() →
  resetPage()` to avoid orphan page indexes. `PosProduct` opts in with
  `['name', 'barcode']`. Spatie translatable JSON columns (e.g. `name`) still
  substring-match the raw envelope; once non-English translations land we'll
  widen to per-locale `json_extract` paths.

**Phase 5 — Contacts module (the reference addon):**

| Concern | Location |
|---|---|
| Manifest | `Modules/Contacts/module.json` (`application`, `depends:[base]`, `models`, `providers`) |
| Model | `Modules\Contacts\Models\Partner` — `implements Chatterable, DefinesIrModel; use HasChatter` |
| Schema | `Modules/Contacts/database/migrations/...create_partners_table.php` |
| UI | `Modules\Contacts\Livewire\{Partners,PartnerForm}` + `resources/views/{partners,partner-form}.blade.php` |
| Routes | `Modules/Contacts/routes/web.php` → `/app/contacts/partner[/new|/{id}]` |
| Form engine | `App\Livewire\Views\FormView` + `App\Erp\Views\FormFieldDef` (added in Phase 5). Record-navigation arrows (prev / next, Odoo-style) appear next to the title on existing records — `prevId()` / `nextId()` order by the model's primary key (ascending). The target URL is derived from a `$baseUrl` **captured at mount** (not re-read at render) so Livewire's AJAX endpoint URL (`/livewire/update`) can never poison the chevron links during auto-save re-renders. Hidden on new records and disabled at list ends. **Auto-save** (Odoo-style): on existing records every field change persists silently — `wire:model.live.debounce.500ms` on text inputs (`wire:model.live` on checkbox/select), Livewire `updated($name)` hook routes `form.*`/`translations.*`/`imagePaths.*` changes through `autoSave()`. ValidationException is swallowed so partial/invalid edits show inline errors but don't commit anything; the next valid keystroke saves. `switchLocale()` and the image-upload Alpine wrapper both end by triggering auto-save. **Status pill** (replaces the Save button on existing records): Alpine wrapper hooks `Livewire.hook('commit')` and flips state between `saved`/`saving`/`error` — wire:loading directives weren't reliable because wire:model.live commits target the *property*, not the autoSave method. **Create flow** on a new record: explicit Save button (`wire:loading.attr="disabled"` to block double-clicks), then on first successful create `save()` redirects to the canonical edit URL (`/app/pos/product/new` → `/app/pos/product/{newId}` via Livewire `navigate`). Auto-save kicks in from there. The redirect both prevents refresh-creates-a-duplicate AND swaps the button for the status pill. Host components no longer listen for `record-saved` to redirect to lists (PosProductForm + PosCategoryForm both stripped the listener — the form is the durable workspace now) |

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

**Phase 7 increments shipped 2026-05-21 / 2026-05-22:**

- **WhatsApp auto-receipt** — see Phase 10 row. `pos_orders.customer_phone` column +
  Terminal phone capture (dial-code + local digits) + listener that queues the
  `pos_receipt` template message on `PosOrderPaid`. Phone composer in
  `Modules\Pos\Support\PosWhatsAppCountries` (13 countries, default +973). Test:
  `tests/Feature/PosWhatsAppReceiptTest.php`.
- **Customer flow rework** — `PosTerminal` got an Odoo-style customer picker modal
  (live search + Create/Edit/Delete partner inline). Trigger button is purple, 1/3
  width. Pencil edit + trash delete per row, red trash, aligned. Test:
  `tests/Feature/PosAddCustomerTest.php` (26).
- **Processed By column** — `PosOrder::user()` (cashier; `pos_orders.user_id`,
  nullable plain-indexed *logical ref*) + `processed_by` accessor renders `User.name`.
  Engine list arch declares `'sort_field' => 'user_id'` because the engine can't
  ORDER BY accessors. Test: `tests/Feature/PosProcessedByTest.php` (5).
- **Status colors** — `OrderState::label()` renamed "Posted" → "Paid". New
  `OrderState::color()` method drives engine list/kanban badges (Done=emerald,
  Draft=amber, Cancelled=red). Kanban arch removed the transient `paid` stage (it's
  immediately followed by `done` inside `finalizeSale()`s transaction — no order ever
  sits in `paid` in steady state).
- **POS Reporting + date-filter chips + custom range** —
  `Modules\Pos\Livewire\PosReporting` at `/app/pos/reporting`: KPI strip
  (revenue / orders / AOV) + preset switcher (Today / Yesterday / This Week / This
  Month / Custom), re-keyed list-view embed beneath that scopes to the active preset.
  List arch on `pos.order` declares 4 date filters + `custom_date_field: ordered_at`;
  engine renders the chip row + Custom popover generically. New engine pieces:
  `App\Erp\Views\FilterDef`, `App\Erp\Views\DatePreset`,
  `ColumnDef::sortField`/`sortColumn()`, `ListView::applyFilter()`/`applyCustomRange()`.
  Test: `tests/Feature/PosFilterAndReportingTest.php` (18).
- **Import/Export dropdown** — Import button on `/app/pos/product` is an Alpine
  dropdown; sibling Export item streams CSV via
  `Modules\Pos\Http\Controllers\PosProductExportController` (chunked). `pos_user`
  group sees Export but not Import (Read vs Create ACL).
- **Money columns** — `total` / `paid_total` (PosOrder) and `price` / `cost_price` /
  `profit` (PosProduct) arch flipped from `'format' => 'number'` to `'format' =>
  'money'` so cells + footer aggregates render via `Currencies::format()` (Phase
  11). After arch changes you'd run `module:resync pos` — the deploy workflow does
  this automatically now (§6).
- **Template button icon** — `⬇` Unicode emoji on `/app/pos/product` swapped for
  a Heroicons outline arrow-down SVG so it inherits the toolbar's text colour
  instead of rendering as a chunky OS emoji.

**Phase 7 increments shipped 2026-05-25:**

- **New product form defaults Active=true** — `PosProduct::$attributes = ['active' => true]`.
  DB column defaulted `true` on insert, but a fresh `new PosProduct()` in memory had
  `null` for unset attributes — the engine FormView coerced that null to `false` on
  save, so a cashier creating a new product had to remember to tick Active or the
  product hid itself from the terminal. Pinned by
  `test_new_product_form_defaults_active_to_true`.

**Phase 7 increments shipped 2026-05-23 / 2026-05-24:**

- **Direct image upload (Livewire pipeline bypassed)** —
  `App\Http\Controllers\FormImageUploadController` is a single-action endpoint
  (POST `/form/upload-image`, `throttle:30,1`, named `form.upload-image`) that
  accepts a multipart file + `bucket` field, validates
  `mimes:jpg,jpeg,png,gif,webp,bmp,avif,heic,heif` (**no SVG** — XSS surface) and
  `max:4096` (4 MB), and stores under `storage/app/public/<bucket>/...`. Bucket
  whitelist: `pos_products`, `pos_categories`, `partners`, `avatars`. **Every
  bucket name here MUST also appear in the rsync `--exclude` list in
  `.github/workflows/deploy.yml`** — without an exclude, `rsync --delete` wipes
  the directory on every push and leaves DB rows pointing at gone files (was
  the cause of the 2026-05-24 "Qassim avatar broken-icon" regression — only
  `pos_products/` was protected). Returns
  `{path, url}` JSON. `FormView::$imagePaths` holds `<attribute => path>` and
  `save()` writes those straight onto the record. The Blade `image` widget is an
  Alpine block that `fetch()`-POSTs and assigns `$wire.imagePaths.<field>` on
  success. **Why:** Livewire's two-phase async upload kept 500ing on Hostinger
  (`livewire-tmp/livewire-tmp.` phantom path on WebP, `FileNotPreviewable` on
  AVIF/HEIC) — the synchronous controller sidesteps the entire `livewire-tmp/` +
  `temporaryUrl()` chain.
- **AVIF / HEIC accepted** — `lang/ar.json` + form-view hint string:
  "Accepted: JPG, PNG, GIF, WebP, AVIF, HEIC, BMP · max 4 MB". `config/livewire.php`
  was published and `temporary_file_upload.preview_mimes` extended with
  `avif/heic/heif` — kept around for any legacy callers still on Livewire's path.
  Memory: `[[livewire-temporary-url-preview-mimes]]`.
- **Per-user column picker on `/app/pos/product`** — uses the Phase 4 engine
  primitives. List arch flips `tax_rate` / `profit` / `stock_on_hand` / `barcode`
  to `hidden_by_default: true`; adds `category_name` (accessor on `PosProduct`,
  reads through `category` relation; sorts via `sort_field: pos_category_id`
  because accessors can't be SQL-ordered).
- **Active column = inline iOS toggle** — `PosProduct` list arch
  `active` column uses `format: toggle`; one click flips the boolean via
  `ListView::toggleBoolean` (Write-gated).
- **Odoo-style kanban product cards** — `PosProduct` kanban arch now declares
  `card: {title: 'name', image: 'image_path', meta: [{price, money}, {stock_on_hand,
  number}]}`. No subtitle / badges — meta footer carries the same info more legibly.
  Renders via the Phase 4 KanbanCard image + meta extension.
- **Import/Export Category round-trip** — `PosProductExportController` eager-loads
  `category:id,name` and emits a Category column between Name and Barcode (renders
  through `category_name` accessor). `PosProductImportTemplateController` includes
  the column with "Hot Drinks" / etc. example values. `PosProductImporter` has
  `HEADER_MAP` aliases `category` / `category name` / `category_name`; resolves
  names → ids in one pre-pass (no N+1). `Modules\Pos\Imports\ImportRow` gained a
  readonly `categoryName` property with Livewire serialization. Empty cell =
  leave `pos_category_id` unchanged (or null on create).
- **All dinar currencies → 2 decimals** — `App\Erp\Money\Currencies` flipped BHD /
  KWD / OMR / JOD / LYD / TND / IQD from `decimals: 3` to `decimals: 2` per user
  request ("0.45 BD, not 0.450 BD, for the whole system"). 3-dp dinar formatting
  notes elsewhere in this file are historical — actual behaviour is now 2-dp
  everywhere. Default DB column is still `decimal(12,2)` so no schema work was
  needed; rendering just pads two trailing digits now.

**Phase 7 increments shipped 2026-06-01 / 2026-06-02 (engine + KDS hardening):**

- **`PosCategory.station` — custom Attribute mutator (cast removed)** —
  the standard `'station' => PrepStation::class` enum cast rejected the engine
  FormView's empty-string option ("— None (no KDS routing) —") with
  `ValueError: "" is not a valid backing value` at `setAttribute` time, before
  any saving hook could normalise it. Cast removed; replaced with an explicit
  `protected function station(): Attribute` whose `set` closure coerces
  `null` / `''` / `'null'` / unrecognised string → null, and accepts either
  a `PrepStation` instance or its `value` string. Read path still returns the
  enum (or null). Fixed the 500 a cashier hit when opening the category form
  and saving with no station chosen.
- **Engine `FormView` flattens `BackedEnum` on mount** ([app/Livewire/Views/FormView.php:139](app/Livewire/Views/FormView.php#L139)) —
  `$record->getAttribute($field)` on an enum-cast column returns the enum
  instance. The instance landed in `$form[<field>]`, and the next auto-save
  fed it to validator rules like `in:` which string-cast each value — a
  `BackedEnum` has no `__toString` so the validator 500ed with "Object of
  class X could not be converted to string". This was the *actual* cause of
  the AR-pill 500 on the category form (the earlier mutator fix above was a
  prerequisite but didn't address the read path). Fix: in mount, coerce
  `$value instanceof \BackedEnum ? $value->value : $value` before storing.
  The save path is unaffected — Eloquent's enum cast / our custom mutator
  converts the scalar back. Memory: `[[livewire-backed-enum-in-array-prop]]`.
  Pinned by `test_form_hydrates_backed_enum_attributes_as_scalar` on
  `PosCategoryTranslationTest`.
- **KDS routing listener — bypass Eloquent enum accessor**
  ([Modules/Pos/Listeners/QueueLinesForKitchen.php](Modules/Pos/Listeners/QueueLinesForKitchen.php)) —
  was `PosCategory::query()->pluck('station', 'id')` which routes through the
  `station` Attribute accessor and returns `PrepStation` enum instances. The
  next `map(static fn (?int $catId): ?string => ...)` then `TypeError`-ed.
  Because `event(PosOrderPaid)` fires AFTER `finalizeSale()`'s DB transaction,
  the sale persisted but the listener crashed silently — `prep_status` never
  got stamped, KDS screens stayed empty. Both plucks now use `DB::table()`
  so the raw string column comes back unmolested. Locked in by
  `PosKitchenRoutingTest::test_finalize_sale_stamps_prep_status_only_on_routed_lines`
  (covers kitchen-routed, shisha-routed, AND no-station categories in one sale).
- **KDS state machine — `markOrderPreparing()` for the Pending column**
  ([Modules/Pos/Livewire/KitchenDisplay.php](Modules/Pos/Livewire/KitchenDisplay.php))
  ([Modules/Pos/resources/views/kitchen-display.blade.php:148](Modules/Pos/resources/views/kitchen-display.blade.php#L148)) —
  the Pending column's "Start preparing" button was wired to `markOrderReady`,
  which loops `advancePrep()` until every line is Ready. One tap walked the
  ticket Pending → Preparing → Ready in a single click, skipping the
  Preparing column entirely. New `markOrderPreparing()` advances Pending
  lines by exactly one step (stamps `prep_started_at`). Preparing column's
  "Mark ready" button still uses `markOrderReady` (it short-circuits any
  late-stage Pending line forward). Pinned by
  `test_mark_order_preparing_advances_pending_lines_exactly_one_step`.
- **KDS sound + visible flash — reliable trigger across browsers**
  ([Modules/Pos/resources/views/kitchen-display.blade.php:182-280](Modules/Pos/resources/views/kitchen-display.blade.php#L182-L280)) —
  the original Web-Audio ping hooked `Livewire.hook('morph.updated', ...)`,
  but that hook fires per *changed* element only. A brand-new ticket arrives
  as a NEW `<article>` (Livewire dispatches `morph.added`), so the diff
  check never ran for new arrivals. Switched to `Livewire.hook('commit',
  { succeed })` scoped to this component's `wire:id` — fires reliably once
  per round-trip after the DOM patch. Also defensive:
  `AudioContext.resume()` awaited inside `enableAudio()` so the confirmation
  beep on the first click actually plays; defensive resume before each
  `ping()` and on `visibilitychange` so a backgrounded tab doesn't silently
  drop to `suspended` (Safari/iOS especially). Ping is now a two-tone beep
  (1040 Hz then 1560 Hz, ~0.22 s each) at higher gain. Even with sound
  muted, a 0.9-second green inset ring flashes on the wrapper via
  `.kds-new-flash` (CSS keyframes) — same component method
  `flashHeader()` invoked alongside `ping()`. Still requires the user to
  tap "Tap to enable sound" once per tab session (browser autoplay rule —
  no workaround).
- **POS Home KDS deep-link icons** ([Modules/Pos/resources/views/home.blade.php:38-58](Modules/Pos/resources/views/home.blade.php#L38-L58)) —
  Kitchen had a people-cluster glyph and Shisha had a thumbs-up — neither
  read as what the button does. Kitchen now uses Heroicons solid `fire`
  (universal cooking shorthand); Shisha uses a custom 3-curl smoke-wisp
  drawing (no Heroicon ships a hookah). Memory:
  `[[use-svg-icons-not-emoji]]`.

**Phase 7 OUT-of-scope adjustment:** "restaurant floors/tables/kitchen" became
"restaurant floors/tables" — the *Kitchen Display* slice now ships (Phase 15
below). Floor / table layouts (restaurant POS) remain out.

**Deliberately OUT of scope** (say so if asked, offer as follow-ups): offline/PWA &
hardware/IoT (scanners, cash drawer, customer display), restaurant floors/tables,
loyalty/gift cards/coupons, multi-currency *per-order* (the global default currency from
Phase 11 IS now applied), advanced tax (price-included, multi-tax, fiscal positions),
refunds/returns, and accounting/invoice posting. Known simplification: cash
reconciliation sums **payment amounts**; change given is computed (`change_due`) but
not posted as a drawer cash-out, so tendering over total slightly overstates expected
cash — use exact tender or treat `change_due` as informational.

---

## 5. Build Phases (roadmap & state tracker)

Keep this table current — it is how state survives across sessions.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Init: Laravel 11 + Livewire 3 + Tailwind + PHPStan/PHPUnit + this file | ✅ DONE |
| 2 | Modular addon arch + `ir_module` / `ir_model(_fields)` / `ir_ui_view` | ✅ DONE |
| 3 | Odoo 19 UX: master layout, app switcher, ⌘K command palette, sidebar, Chatter (`mail.thread`) | ✅ DONE |
| 4 | Dynamic view engine: List (sort/bulk/aggregate/filter/custom-range, per-user column picker, hidden-by-default columns, inline `toggle` format, arch-driven toolbar search) + Kanban (drag-drop, rotting indicator, image hero + meta footer, responsive grid for ungrouped boards) | ✅ DONE |
| 5 | First module: **Contacts** (`Partner` model + Form/List/Kanban + Chatter) | ✅ DONE |
| 6 | Auth & access control: login, `res_groups`, `ir_model_access`, enforced in views; **Profile self-service** (avatar / email-change via signed link / password) | ✅ DONE |
| 7 | **Point of Sale** module: sessions, terminal, payments, receipts, reconciliation, customer picker, Processed By, status colors, Reporting + date-filter chips + custom range, Import/Export dropdown (Category round-trip), money columns, WhatsApp auto-receipt **with per-order PNG header image** (DomPDF + Imagick), 12-hour-clock receipts + customer phone on overlay, direct image upload (AVIF/HEIC + safety hardening), per-user column picker, inline Active toggle, Odoo-style kanban product cards in responsive grid, toolbar search (name + barcode), new-product form defaults Active=true | ✅ DONE |
| 8 | **Settings**: `ir_config_parameter` + cached `SettingManager`/`Setting` facade + role-gated Settings page (admins see all; non-admins see only `company.language`) + generic `$selects` Alpine combobox (currency + language pickers) | ✅ DONE (General tab + dropdowns + role-gated access; POS/Inventory tabs = next increments) |
| 9 | **Inventory**: double-entry schema + Overview Kanban + atomic pickings/transfer flow | ✅ (adjustment/replenishment/lots/valuation+forecast/barcode = next increments) |
| 10 | **WhatsApp**: Meta Cloud API integration (queued messaging, webhook, admin Settings tab, **POS auto-receipt** with PNG image header, configurable template language) | ✅ DONE; templates table/UI · Chatter button · other event automations · richer attachment types = next increments |
| 11 | **Currency engine**: `App\Erp\Money\{Currency,Currencies}` (27 currencies, Arab-world heavy — **all dinars now display at 2 decimals per user policy**, originally modelled as 3) + `ValueFormat::money()` + `format: money` column type + Settings dropdown | ✅ DONE |
| 12 | **Locale & RTL Arabic (Pass 1)**: `SetLocale` middleware + `lang/ar.json` + `<html dir="rtl">` + logical Tailwind utilities + auto-reload on language flip + app-switcher per-module icons | ✅ Pass 1 (foundation + chrome + login + profile + settings + dashboard). Pass 2 (POS interiors, Contacts, Inventory, Chatter, engine list/kanban/form chrome, validation messages) = next |
| 13 | **Translatable data**: `spatie/laravel-translatable` + engine `translatable: true` arch flag + Odoo-style EN/AR pills in FormView + `PosProduct.name` and `PosCategory.name` opted in | ✅ DONE (POS Product + Category names). Follow-ups: `Partner.name`, add `description` columns then opt them in |
| 14 | **Accounting**: double-entry COA + balanced journal entries (draft→posted) + sequence-generated numbers + auto-post on POS sale & purchase invoice + Trial Balance / P&L / Balance Sheet | ✅ Backend (schema/models/services/listeners/seeder). Follow-ups: Livewire screens (statements pages, journal-line inline editor), bank reconciliation, taxes module, manual-entry form, fixed-asset depreciation |
| 15 | **Kitchen Display System (KDS)**: per-category station routing (`kitchen` / `shisha`), `PosOrderPaid` listener stamps `prep_status=pending` on routed lines, 3-column kanban screen polling every 5s (`/app/pos/kitchen/{station}`), single-tap state machine (Pending → Preparing → Ready → Completed), late-ticket flash, Web Audio ping + green-flash on new arrivals | ✅ DONE |
| 16 | **Purchases** (`Modules/Purchases/`): vendor bills with line items; **Confirm** atomically raises POS `stock_on_hand`, posts an Inventory receipt move (Vendor → Stock, updating `stock_quants` keyed by the same product id) AND books the accounting entry (Dr Inventory/Expense · Cr A/P) via `PurchaseInvoiceConfirmed` → the pre-existing `RecordPurchaseInJournal` listener. Custom master/detail Livewire editor; engine list | ✅ DONE |

**Phase 8 — Settings (where things live):**

| Concern | Location |
|---|---|
| Schema | `database/migrations/..._create_ir_config_parameter_table` — `key`(uniq), `value`(text), `type`(string\|bool\|number\|image), `group`, `label`, `description`, `sort`. `image` is a UI-only flag — the column stores a string (relative path on the `public` disk, e.g. `company/logo.webp`); `cast()` returns it verbatim. The settings view renders it as an upload widget routed through `FormImageUploadController` |
| Model | `App\Models\Ir\IrConfigParameter` |
| Service | `App\Erp\Settings\SettingManager` (whole set cached `rememberForever` under `erp.settings.all`; `get/set/setMany/grouped/flush`; writes flush) + `App\Erp\Settings\Setting` facade (singleton bound in `AppServiceProvider`) |
| UI | `App\Livewire\Pages\SettingsPage` — **role-gated**: admins see all keys; non-admins (POS cashiers, sales users) see only the keys in `SettingsPage::NON_ADMIN_KEYS` (currently `['company.language']`). Index-keyed `$form` so dotted keys aren't read as nested; `mount()` filters `$form` to the allowed set; `save()` re-filters before `setMany` so a crafted payload can't escalate. The `company.language` row is **per-user** — `mount()` reads `Auth::user()->language`, `save()` writes it (not `setMany`); see Phase 12 row. Group→tabs; bool=toggle/number/string controls; bulk Save → `setMany` → cache flush → `resources/views/livewire/pages/settings.blade.php` |
| Route/Nav | `/app/settings` (named `settings`, registered **before** `/app/{module}`); Settings link shown in `Sidebar` to every authenticated user (the page itself enforces what each role can edit) |
| Seed | `SettingSeeder` (General: `company.name`, `company.logo`, `currency.default`, `company.timezone`, `company.language`) — non-destructive (keeps saved values), in default `DatabaseSeeder` chain. `company.logo` ships with an empty value; once an admin uploads a logo the path is rendered on the login page (guest layout), the topbar brand, and the POS receipt overlay |
| Logo render path | Admins set `company.logo` under Settings → General. The file goes to `storage/app/public/company/<hash>.<ext>` via `FormImageUploadController` (bucket `company` — must stay in lockstep with deploy.yml's rsync `--exclude` list, see [[rsync-delete-wipes-user-uploads]]). Helpers: `Setting::get('company.logo')` returns the relative path; `App\Erp\Branding\Logo::url()` is the single render path (returns null if no logo OR file missing — same guard pattern as `User::avatarUrl()`). Used in `resources/views/components/layouts/{app,guest}.blade.php` and `Modules/Pos/resources/views/terminal.blade.php` |

Usage: `Setting::get('company.name')`, `Setting::set('currency.default', 'EUR')`. App-switcher still shows the demo `settings` tile to all (ACL enforced on click → non-admins land on the restricted view), like other apps. To grant non-admins another setting, append its key to `SettingsPage::NON_ADMIN_KEYS` — no other changes needed (view is data-driven). The settings-nav partial's WhatsApp pill stays admin-only.

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
| Settings UI | `Modules\WhatsApp\Livewire\WhatsAppSettings` (**admin-only** `abort 403`) + `whatsapp::settings`; secrets are **write-only** (never echoed; blank on save = keep). Surfaced as a tab via `resources/views/partials/settings-nav.blade.php` (`@include`d by both the core settings view and this one; shows the WhatsApp pill only when the module is `Installed`). **Template language** field (added 2026-05-24) is the Meta locale code the outbound template was approved under — `en` (default), `en_US`, `ar`, etc. — flipping it requires no redeploy. Wrong code = `#132001` "Template name does not exist in the translation" and silent fail; column added by migration `2026_05_24_300002_add_template_language_to_whatsapp_configuration` (auto-applied by `deploy.yml`'s WhatsApp migrate step) |
| Errors | `Modules\WhatsApp\Exceptions\WhatsAppException` (config missing/disabled, or non-2xx Graph response) |
| Tests | `tests/Feature/WhatsAppModuleTest.php` (11) — install schema, encrypted-at-rest save, admin gate, service→queued-log, job sent/failed, webhook verify/signature/status+inbound. Webhook tested by calling the controller directly (module routes only register on boot **after** install — the known engine gap) |

Install (migrations run via the engine): `php artisan module:install whatsapp`. Then
configure under **Settings → WhatsApp** (admin) and register the webhook URL shown there
in Meta. **Not yet built** (next increments): `whatsapp_templates` table + parser UI, the
Chatter "WhatsApp" button, other event-triggered automations beyond POS receipt,
media/PDF attachment URLs, and inbound→Chatter document correlation (`related_*`).

**POS auto-receipt (shipped 2026-05-21, refined 2026-05-24/25):**
`Modules\Pos\Events\PosOrderPaid` fires from `PosOrder::finalizeSale()`.
`Modules\Pos\Listeners\SendPosOrderReceiptViaWhatsApp` (registered by hand in
`PosServiceProvider::boot()` — not via `EventServiceProvider` because POS is a module
that only activates on install) builds **4 ordered body variables**: store name (from
`Setting::get('company.name')`) / order ref / total via `Currencies::format()` /
`ordered_at` as **`M j, Y g:i A`** (12-hour with AM/PM — every retail POS in the region
prints AM/PM). The customer-name slot was dropped — cashiers rarely capture a partner,
so the legacy "Hello Walk-in" header was noise; the template now opens "Hello, thank you
for your order at {store}." instead. Sends via
`WhatsAppService::sendTemplateMessage($phone, 'pos_receipt', $vars, $config->template_language, $imageUrl)`.
Failures are swallowed and logged to the order's Chatter — a misconfigured WhatsApp
must NEVER break checkout. Phone capture lives in `PosTerminal` (dial-code dropdown +
local digits, composed via `Modules\Pos\Support\PosWhatsAppCountries` which strips
leading zeros, default `+973`). `pos_orders.customer_phone` column added via
`2026_05_21_200001`.

**Receipt overlay (on-screen, in `Modules/Pos/resources/views/terminal.blade.php`):**
mirrors the WhatsApp variables — header reads `{ref} · {M j, Y h:mm A}` (12-hour) and
shows `Phone: +{customer_phone}` underneath the customer name when one was captured.
Walk-ins with no phone get no extra line. Logo above the company name via
`App\Erp\Branding\Logo::url()` (returns null when the logo path is unset OR the file
is missing on disk — same defensive guard `User::avatarUrl()` uses to avoid broken-img
icons after a rsync regression).

**POS receipt PNG image (Phase 7, shipped 2026-05-25):** every paid order is also
rendered as a PNG that goes into the WhatsApp template as a `HEADER:IMAGE` component,
so the customer sees the receipt VISUALLY above the body text on their phone. Pipeline
is pure server-side, no external service:

| Concern | Location |
|---|---|
| Library | `barryvdh/laravel-dompdf ^3.1` — pure PHP, no system binaries beyond Imagick |
| Blade | `Modules/Pos/resources/views/receipt-pdf.blade.php` — inlined-CSS single-page receipt layout (DomPDF can't share Tailwind/Vite). Reads logo from filesystem path (`Storage::disk('public')->path(...)`), not URL, because DomPDF's HTTP fetcher is disabled |
| Renderer | `Modules\Pos\Services\PosReceiptImageRenderer` — DomPDF → in-memory PDF → Imagick (200 DPI, links to Ghostscript library directly so the shell-`exec` block on Hostinger doesn't matter) → PNG (`png`, q90, ~80–150 KB). Saves to `storage/app/public/whatsapp-receipts/{safeRef}-{id}.png`, returns public URL. Throws `RuntimeException` if Imagick disappears; listener catches and falls back to text-only send |
| Wiring | `WhatsAppService::sendTemplateMessage()` extended with optional `?string $headerImageUrl` param — emits a `{type: header, parameters: [{type: image, image: {link}}]}` component **before** `body`. Null = no header (back-compat with text-only templates) |
| Bucket | `storage/app/public/whatsapp-receipts/` — same `--exclude` contract as the other user-content buckets in `deploy.yml` rsync (memory: `[[rsync-delete-wipes-user-uploads]]`) |
| Cleanup | `routes/console.php` scheduled task `prune-whatsapp-receipts` runs daily and deletes PNGs older than 7 days — Meta fetches the URL once at send time, never re-fetches, so anything older is disk clutter |
| Tests | `tests/Feature/PosWhatsAppReceiptTest.php` — `PosReceiptImageRenderer` Mockery-stubbed (not final per the project convention) so the suite doesn't need Imagick locally. New `test_payload_includes_a_header_image_component_with_renderer_url` pins the components shape (header at [0], body at [1]). Pre-fix tests reading body params from `components[0]` were shifted to `[1]` |

Meta requires the template's header type to be locked at template-creation time — so
the user re-registered `pos_receipt` on the Test WABA with `Header → Image` selected
and a placeholder PNG (Meta needs a sample to approve; the real image is supplied per
send). 4 body placeholders: `{{1}}` store name, `{{2}}` order ref, `{{3}}` total,
`{{4}}` datetime (12-hour).

**Note on Test WABA:** auto-receipt requires the configured `business_account_id` (in
`whatsapp_configuration`) to be the SAME WABA the `pos_receipt` template lives under.
Different WABAs = Meta returns `#132001 Template name does not exist in the translation`
even when the template name is correct (the sender can only use templates owned by its
own WABA).

**Phase 11 — Currency engine (`App\Erp\Money\`):**

| Concern | Location |
|---|---|
| Value object | `App\Erp\Money\Currency` (readonly) — `code` / `name` / `symbol` / `decimals` / `position` (before\|after); `format($amount)` does the actual padding |
| Registry | `App\Erp\Money\Currencies` — **27 currencies**, Arab-world heavy: dinars (BHD/KWD/OMR/JOD/LYD/TND/IQD) = **2 decimals** (originally modelled as 3; flipped 2026-05-24 — "0.45 BD, not 0.450 BD, for the whole system"); SAR/QAR/AED/LBP/SYP/YER/EGP/SDG/DZD/MAD/MRU/SOS = 2 decimals; DJF/KMF = 0 decimals; plus USD/EUR/GBP/INR/PKR/TRY (Western majors prefix the glyph, Arab abbreviations suffix). `all()` / `find(code)` / `active()` (reads `Setting::get('currency.default')` with USD fallback) / `format(amount, code?)` / `flushCache()` for tests |
| Engine wiring | `App\Erp\Views\ValueFormat::money()` delegates to `Currencies::format()`. `resources/views/livewire/views/list-view.blade.php` `$fmt` closure routes `format: money` columns through it; aggregate footer also detects `'money'` and uses the same path so footer totals match the row format |
| Whitelist gotcha | `App\Erp\Views\ViewArch::parseList()` had a hardcoded format whitelist that silently downgraded unknown values to `'text'`. `'money'` was added; regression test `CurrencyFormatTest::test_view_arch_whitelist_accepts_money_format` pins it so a future tidy can't undo it |
| Form widget | `resources/views/livewire/views/form-view.blade.php` — number widget gains `step="any"` so 2-/3-decimal currencies (8.5, 12.345) don't trip browser `step=1` validation ("nearest valid 8 and 9") |
| Settings dropdown | `App\Livewire\Pages\SettingsPage::$selects['currency.default']` populated from `Currencies::all()`; rendered by `resources/views/livewire/pages/settings.blade.php` as an Alpine combobox (button + popover with search input + filtered list + click-pick + Esc/click-outside to close). Generic across any setting key listed in `$selects` — `$selects['company.language']` and `$selects['company.timezone']` reuse the same template. The timezone list comes from `DateTimeZone::listIdentifiers()` deduplicated by current UTC offset (~38 entries instead of 400) — each row's label embeds the offset + first few sample cities so the combobox search hits a country name like "Riyadh" or "Bahrain" even when the IANA representative for that offset is a different city |
| Tests | `tests/Feature/CurrencyFormatTest.php` (10) — BHD 3-decimal suffix, USD 2-decimal prefix, zero-decimal currencies, active-from-setting, fallback-to-USD-on-unknown, explicit-code override, null→zero, dropdown population, arch whitelist |

DB stores `decimal(12,2)` and all currencies now display at ≤ 2 decimals (DJF/KMF 0, everything else 2). The `step="any"` form widget gotcha and `flushCache()` test helper still matter — both predate the 2-dp policy and aren't affected by it. Production change: admin picks currency in **Settings → General → Default Currency**, save flushes the settings cache, next page render reformats every money cell + the WhatsApp receipt template variable.

**Phase 12 — Locale & RTL Arabic (Pass 1, shipped 2026-05-22):**

| Concern | Location |
|---|---|
| Preference scope | **Per-user.** `users.language` (`nullable(5)`, no default) stores each user's choice; the system-wide `company.language` setting is the fallback (= the default for new users without a preference, and the locale of the guest /login page). Faraj on `ar` and Qassim on `en` simultaneously work as expected — the middleware reads `Auth::user()->language` first |
| Middleware | `App\Http\Middleware\SetLocale` — `Auth::user()?->language ?? Setting::get('company.language', 'en')`, then whitelists against `['en','ar']` (unknown silently falls back to `en` so a misconfigured row can't 4xx the site). Appended to `web` group in `bootstrap/app.php` |
| Strings | `lang/ar.json` — English-string-keyed JSON. New `__()` calls without a matching entry render the English key (not a crash); add an entry in the same change (memory: `[[translate-changes-to-arabic]]`) |
| Direction | Master + guest layouts set `<html dir="rtl">` when locale is `ar`. Pass-1 surfaces converted to **logical Tailwind utilities** so layout mirrors against `dir`: `ms-`/`me-`/`ps-`/`pe-` (margins/padding), `start-`/`end-` (positioning), `text-start`/`text-end` (alignment), `border-s-`/`border-e-` (borders), `rounded-s-`/`rounded-e-` (corners), `origin-top-start` (transform origin) |
| Auto-reload | `SettingsPage::save()` snapshots the *effective* language (`user.language ?? company.language`) before write; if it changed, `$this->dispatch('language-changed')`. Master layout `<body>` has `x-on:language-changed.window="window.location.reload()"` — a Livewire partial re-render can't flip the parent `<html dir>` or rebuild the layout, so a full reload is required when locale flips. Saves for *other* settings don't trigger reload |
| SettingsPage routing | The "Language" row is special-cased: `mount()` reads `Auth::user()->language` (falling back to `company.language`); `save()` writes to `Auth::user()->language` instead of `setMany`. Other rows (Company Name, Default Currency, Timezone) still go to `ir_config_parameter` as before. So Settings → Language picker = personal; admin still owns `company.language` via DB (no separate "default for new users" UI yet — small follow-up if needed) |
| App-switcher icons | `resources/views/livewire/navigation/app-switcher.blade.php` — 2-letter abbreviations replaced with per-module Heroicons mini: contacts=user-group, crm=building-office-2, pos=shopping-bag, sales=currency-dollar, inventory=archive-box, project=briefcase, settings=cog-6-tooth, accounting=wallet. Falls back to a neutral 3×3 grid for unrecognised modules. Module label flows through `__('module.<slug>')` so "Point of Sale" → "نقطة البيع" |
| Tests | `tests/Feature/LocaleTest.php` (7) — fallback to English on missing setting / unknown code; `dir="rtl"` + translated chrome on Arabic; dropdown population; reload event fires only on language flip (not on other-setting saves); login page renders in Arabic |

**Pass 1 scope (translated + RTL-mirrored):** master `app.blade.php` layout, `guest.blade.php` layout, login (`Auth\Login`), profile (`ProfilePage` + verification controller flashes), settings (header / tabs / combobox / "No matches"), settings nav partial, sidebar, app switcher, command palette, dashboard.

**Pass 2 increment shipped 2026-05-24** — POS home + POS session pages, breadcrumb path segments (`app`/`pos`/`session`/`contacts`/…), sidebar `ir_model` labels (`POS Order`/`POS Session`/`POS Product`/`POS Category`/`Partner`), `OrderState::label()` + `SessionState::label()` outputs (Draft/Paid/Cancelled/In progress/Closed) — these run through `__()` at the call-site so the enum stays untouched. All sweeps also flipped `ml-`/`text-right` → `ms-`/`text-end` for RTL mirroring.

**Pass 2 increment shipped 2026-06-02** — full Arabic + RTL sweep of (a) **Kitchen Display** (`kitchen-display.blade.php` + `PrepStation`/`PrepStatus`/`JournalEntryState`/`AccountType` enum labels via `__()`) and the POS-home KDS deep-link buttons, (b) the **POS terminal** interior (`terminal.blade.php` — cart, product grid, customer picker, add/edit-customer modal, payment overlay, on-screen receipt) plus the two `PosTerminal::addError()` validation strings, (c) engine **list/kanban/form** chrome strings (All/Columns/Configure columns/Drop cards here/Loading more…/Yes/No/Saved/Not saved) and the 419 page, and (d) the **Accounting** screens (Chart of Accounts / Journal Entries forms + lists). All physical-direction utilities on the touched views (`ml-`/`mr-`/`pl-`/`pr-`/`text-left`/`text-right`/`rounded-l/r-`/`right-N`) flipped to logical (`ms-`/`me-`/`ps-`/`pe-`/`text-start`/`text-end`/`rounded-s/e-`/`end-N`). ~120 new keys added to `lang/ar.json` (now ~293 keys). Carve-out left English: the `customer@example.com` placeholder (format example).

**Pass 2 still pending:** POS products/orders/reporting interiors, Contacts module, Inventory module, Chatter, page `#[Title(...)]` browser-tab titles (all 26 are static PHP attributes — need conversion to dynamic `->title(__())`), validation messages (`lang/ar/validation.php` not yet added — Laravel's built-in `required`/`email`/`max` messages still render in English).

**Carve-outs (deliberately English-only):** WhatsApp settings tab content (brand-aligned), brand names ("OpenERP" / "WhatsApp"), ISO codes + currency symbols ("BHD" / "BD" / "USD"), CLI snippets in code blocks (`php artisan ...`), keyboard shortcuts ("⌘K"). When in doubt: brand + identifier = stay English. User-entered *display* data (product / partner names) is **translatable per-record** via Phase 13, not a UI-string carve-out.

**Phase 13 — Translatable data (shipped 2026-05-23):**

| Concern | Location |
|---|---|
| Package | `spatie/laravel-translatable` ^6.11. Stores per-locale values as a JSON object on a single column (e.g. `pos_products.name` = `{"en":"Espresso","ar":"إسبريسو"}`); reading `$p->name` returns the active-locale value driven by `app()->getLocale()` (which `SetLocale` middleware sets from `company.language`) |
| Contract | `App\Erp\Translation\TranslatableModel` — narrow interface declaring `getTranslations()` + `setTranslations()`. Spatie doesn't ship one; we declare ours so PHPStan can type-narrow at engine call sites (`FormView` only sees `class-string<Model>`). Models opt in via `use HasTranslations` **and** `implements TranslatableModel` |
| Engine flag | `FormFieldDef::$translatable` + `isTranslatable()` (widget guard — only `text` / `textarea` honour it; numbers / checkboxes silently drop the flag). Parsed from arch `'translatable' => true` by `ViewArch::parseFormFields()` |
| Form UI | `App\Livewire\Views\FormView` — `$translations` (`<field> => <locale> => string`) buffers all locale values across pill switches; `$translationLocale` (`<field> => locale`) tracks the active pill per field. `switchLocale($field, $locale)` flushes the in-progress edit for the OLD locale into the buffer, then loads the NEW locale's value into the input. `save()` writes via `$record->setTranslations(...)` instead of `setAttribute`. Pills render right of the field label (active = `bg-primary-600 text-white`, inactive = `bg-chrome-100`) |
| Supported locales | `FormView::LOCALES = ['en', 'ar']` — aligned with Phase 12. Adding a third locale = add the code here + a `lang/<code>.json` file; no schema work |
| Migrated models | `Modules\Pos\Models\PosProduct` and `Modules\Pos\Models\PosCategory` (both `name` only). Migrations `2026_05_23_200001_make_pos_products_name_translatable.php` and `2026_05_24_300001_make_pos_categories_name_translatable.php`: ALTER column → TEXT (no-op on SQLite — dynamic typing), data-migrates existing plain-string values to `{"en": value}`. Idempotent (`where name NOT LIKE '{%'`) — safe to re-run on a partial migration. Auto-applied to prod by deploy.yml's POS migrate step |
| Tests | `tests/Feature/PosProductTranslationTest.php` (~13) + `tests/Feature/PosCategoryTranslationTest.php` (10) — JSON storage, locale-driven read, plain-string round-trip, arch-flag presence, mount hydration, switchLocale buffering, save via setTranslations, unknown-locale ignored, end-to-end `company.language` flip changes displayed name. Same contract on both models — divergence in the engine (FormView buffer, ViewArch parsing, fallback rules) fails both suites at once |
| Importer category lookup | `PosProductImporter::resolveCategoryIds()` queries `where('name->en', ...)->orWhere('name->ar', ...)` because `whereIn('name', $names)` can't match the JSON envelope. New categories are created with a locale-keyed `name` array under the script-detected locale (`detectLocale()`). Pinned by `test_importer_resolves_category_by_name_and_creates_missing_ones` |

**Lookup gotcha:** With `name` as JSON, `where('name', 'X')` no longer matches — the column literally holds `{"en":"X"}`. Use `where('name->en', 'X')` (Laravel JSON-path; works on SQLite + MySQL natively). `PosTerminal::products()` `LIKE '%search%'` survives because LIKE substring-matches the raw JSON envelope, and `orderBy('name')` still mostly sorts alphabetically because the `{"en":"` prefix is constant for English-only rows — both degrade once Arabic translations land, so swap to `orderByRaw("json_extract(name, '$.en')")` (driver-aware) and locale-scoped JSON-path search next time you touch the terminal.

**Phase 14 — Accounting module (`Modules/Accounting/`, depends on `contacts`):**

| Concern | Location |
|---|---|
| Manifest | `Modules/Accounting/module.json` (`application:true`, `depends:[base,contacts]`, models: Account + JournalEntry, sequence 20) |
| Schema | 4 tables — `accounts` (COA, hierarchical `parent_id`, translatable JSON `name`, `is_reconcilable`, `active`), `journal_entries` (`number` unique, `date`, `reference` indexed, `narration`, `state`, `user_id`, `posted_at`), `journal_items` (`debit`/`credit` decimal(15,2), `partner_id` logical ref, `memo`), `accounting_sequences` (per-(prefix,year) counter, unique `acc_seq_prefix_year_unique`) |
| Enums | `Modules\Accounting\Enums\{AccountType,JournalEntryState}`. `AccountType::normalBalance()` returns `'debit'` (Asset/Expense) or `'credit'` (Liability/Equity/Income) — that single fact drives every balance + statement query. `signedBalance(debit,credit)` flips the sign per convention. `isProfitAndLoss()` / `isBalanceSheet()` split the COA for statement scoping |
| Models | `Modules\Accounting\Models\{Account,JournalEntry,JournalItem,AccountingSequence}`. `Account` uses Spatie `HasTranslations` (`name`), is `DefinesIrModel`, exposes `byCode()`, `totalDebit()`/`totalCredit()`/`balance()`/`signedBalance()` (posted-only, date-windowed), and `subtreeBalance()`+`subtreeIds()` for parent rollups (iterative + visited-set, cycle-safe like PosCategory). `JournalEntry` is `DefinesIrModel + Chatterable`, exposes `assertBalanced()` (throws `UnbalancedJournalEntryException`) + `isBalanced()`/`isPosted()`. `JournalItem` has builder helpers `asDebit($n)` / `asCredit($n)` that null out the opposite side |
| Posting service | `Modules\Accounting\Services\JournalPoster` — sole entry point. `createDraft(date,lines,reference,narration,prefix)` persists header+lines in one transaction with an auto-generated `number`. `post(JournalEntry)` validates balance, flips state to Posted, stamps `posted_at`, logs to Chatter. Idempotent (no-op on already-posted). `record(...)` = create+post for the automated listeners (rolls back the whole entry if unbalanced — no orphan drafts). `Auth::id()` stamps `user_id` |
| Sequence service | `Modules\Accounting\Services\SequenceGenerator::next(prefix,year?)` returns `"MISC/2026/0001"`-style strings under a `lockForUpdate` on `accounting_sequences` — race-safe across concurrent workers (MySQL/Postgres); SQLite's single-writer model is the second safety net, and `journal_entries.number` UNIQUE is the third |
| Validation | `Modules\Accounting\Exceptions\UnbalancedJournalEntryException` carries `totalDebit`/`totalCredit` so the form can render "Out of balance by 0.50" without re-summing. Threshold `< 0.005` so 2-dp rounding noise can never trip it |
| Reports | `Modules\Accounting\Services\FinancialReports` — `trialBalance(from?,to?,includeZero=false)` (joined COA × posted items, grouped by account), `profitAndLoss(from?,to?)` (income − expense + net), `balanceSheet(asOf?)` (assets / liabilities / equity + **retained-earnings carry-forward** so Assets = Liabilities + Equity actually balances after the first period closes; includes `is_balanced` self-check), `accountLedger(Account,from?,to?)` (per-line drill-down with running balance — sign-aware per the account's `normalBalance()`). All driver-portable DB-level aggregates; only posted entries count |
| Translatable name lookup | `accounts.name` is JSON envelope (Spatie); `FinancialReports::translatedName()` decodes the raw column value for the active locale at the query layer (DB raw aggregates can't go through Eloquent accessors). Falls back to `en` then first available key then raw |
| Auto-posting (POS) | `Modules\Accounting\Listeners\RecordPosSaleInJournal` — listens to `PosOrderPaid`, books `Dr Cash {total} / Cr Sales Income {subtotal} / Cr Sales Tax Payable {tax_total}` (the tax leg only when `accounting.accounts.sales_tax_payable` is configured; otherwise the full gross hits Sales Income). Errors are swallowed and logged to the order's Chatter — a missing COA row must NEVER break checkout (same convention as the WhatsApp listener) |
| Auto-posting (purchase) | `Modules\Accounting\Listeners\RecordPurchaseInJournal::handle(object $event)` — event-shape agnostic via duck-typing (`property_exists($event, 'invoice')`). Books `Dr Inventory|Purchase Expense {total} / Cr AP {total}` based on `$invoice->is_stock_purchase`. **Now live** — the Phase 16 Purchases module fires `Modules\Purchases\Events\PurchaseInvoiceConfirmed` (the `Purchase` model satisfies the `@phpstan-type Invoice` shape), wired in `AccountingServiceProvider::boot()` by the string event name. Direct `record(object $invoice)` entry point is also test-friendly |
| Config | `Modules/Accounting/config/accounting.php` — code→meaning mapping (`accounts.cash='1010'`, `bank='1020'`, `accounts_receivable='1100'`, `inventory='1200'`, `accounts_payable='2010'`, `sales_tax_payable=''` (off), `sales_income='4010'`, `purchase_expense='5010'`) + sequence prefixes (`misc=MISC`, `sales=SALE`, `purchase=PURC`). Merged via `mergeConfigFrom` in the provider; override per-project by publishing to `config/accounting.php`. **No `env()` calls** — the file sits outside the project `config/` dir, larastan rule `noEnvCallsOutsideOfConfig` forbids it there |
| Seeder | `Database\Seeders\ChartOfAccountsSeeder` (lives at the root `database/seeders/` — project convention — NOT under `Modules/Accounting/`; PSR-4 only maps `Database\Seeders\` → root `database/seeders/`, and Linux is case-sensitive on autoload paths). 13 accounts across all 5 types with EN/AR translations, parent groupings (1000 Assets / 2000 Liabilities / 3000 Equity / 4000 Income / 5000 Expense), idempotent (two-pass: insert then wire parent_id), `Schema::hasTable('accounts')` guarded so it's a no-op pre-install. Manual run — NOT in default chain |

Install: `php artisan module:install accounting` (auto-pulls Contacts), then
`php artisan db:seed --class="Database\Seeders\ChartOfAccountsSeeder"`.
The POS↔Accounting auto-posting wakes up immediately — any sale finalised after the
listener registers books a balanced journal entry. The Account list/form are mounted
at `/app/accounting/account` and Journal Entries at `/app/accounting/journal_entry`
by the engine (no explicit routes needed yet).

**Deliberately OUT of scope this increment** (say so if asked, offer as follow-ups):
Livewire screens for the three statements (`FinancialReports` returns plain arrays
ready to bind), a journal-line inline editor (manual entries today go through
`JournalPoster::createDraft()` programmatically), bank reconciliation, multi-currency
journal items (single-currency from `Setting::get('currency.default')`), tax codes /
fiscal positions, fixed-asset depreciation, year-end closing automation. The
retained-earnings carry-forward on the balance sheet is *computed live* — there's
no closing-entry concept yet.

**Phase 15 — Kitchen Display System (`Modules/Pos/`, shipped 2026-06-01 / 2026-06-02):**

| Concern | Location |
|---|---|
| Enum: station | `Modules\Pos\Enums\PrepStation` — backed-string `kitchen` / `shisha` + `label()`. Adding a new station = a new case + `/app/pos/kitchen/<value>` URL (the screen is the same component, parameterised). |
| Enum: line lifecycle | `Modules\Pos\Enums\PrepStatus` — `pending` → `preparing` → `ready` → `completed`. Provides `next()` (single-step forward), `nextLabel()` (button text per state), `color()` (Tailwind tone token), `label()` (translated user-facing name), `active()` (list of statuses still on screen). |
| Category → station | `pos_categories.station` (nullable VARCHAR(16), indexed) added by `2026_05_31_200002_add_station_to_pos_categories`. `PosCategory` exposes it via a **custom Attribute mutator** (not the standard enum cast — see Phase 7 increment 2026-06-01 for why); list arch declares `format: badge`, form arch declares `widget: select` with options `['' → "— None (no KDS routing) —", 'kitchen' → 'Kitchen', 'shisha' → 'Shisha']`. |
| Line lifecycle columns | `pos_order_lines.{prep_status, prep_sent_at, prep_started_at, prep_ready_at, prep_completed_at}` added by `2026_05_31_200001_add_kds_columns_to_pos_order_lines`. `PosOrderLine::advancePrep()` walks one step forward and stamps the matching transition timestamp; idempotent at the terminal state. |
| Routing listener | `Modules\Pos\Listeners\QueueLinesForKitchen` — on `PosOrderPaid` (fired post-transaction by `PosOrder::finalizeSale()`), stamps `prep_status = pending` + `prep_sent_at` on every line whose product → category → station resolves. Idempotent (a line already past Pending is left alone). **Uses `DB::table()`** for the station lookup pluck — Eloquent's `pluck` would route through the `station` Attribute accessor and hand back `PrepStation` enum instances that the typed map closure can't return. Wired in `PosServiceProvider::boot()` alongside the WhatsApp listener. |
| Screen | `Modules\Pos\Livewire\KitchenDisplay` — single component parameterised by `public PrepStation $station;`. Routes: `/app/pos/kitchen/kitchen` (food) and `/app/pos/kitchen/shisha` (shisha) under `web/auth/pos.session:Read`. `mount(PrepStation $station)` — typed as the enum because Livewire converts the URL segment via the typed property before `mount()` runs (an earlier `string` type 500ed with TypeError; route-level `whereIn` rejects unknown values upstream). |
| Component methods | `advance(int $lineId)` (per-line single-step), `markOrderPreparing(int $orderId)` (Pending → Preparing for every Pending line on the order — drives Pending column button), `markOrderReady(int $orderId)` (walks every Pending/Preparing line forward to Ready — drives Preparing column button), `completeOrder(int $orderId)` (forces every active line to Completed — drives Ready column button). All filter by station so a Shisha screen can never mutate a Kitchen line. |
| Ticket aggregation | `loadTickets()` queries active lines for THIS station, groups by order, returns `Collection<int, KitchenTicket>`. `KitchenTicket` (`Modules\Pos\Support\KitchenTicket`) is a readonly VO: `orderId`, `reference`, `sentAt` (earliest `prep_sent_at` on the order), `status` (least-progressed line's status — a multi-item ticket only graduates to Ready when every line is ready), `lines`. |
| View | `Modules\Pos\resources\views\kitchen-display.blade.php` — 3-column kanban (Pending / Preparing / Ready, hard-coded tone tokens so JIT scans every Tailwind class). Each card = one order's lines for this station. `wire:poll.5s` on the wrapping div. Big touch targets (`min-h-12`), late-ticket red ring (CSS `@keyframes lateFlash` after 15 minutes), per-line notes underlined. Mobile-first stack → `sm:grid-cols-3`. |
| Empty-state diagnostic | If `PosCategory::where('station', $station)->count() === 0`, the screen renders an amber banner ("No categories are routed to this station yet … Open POS → Categories, edit each one that belongs here, and set Kitchen station to <name>") with a "Go to Categories" link. Surfaces the wiring gap that would otherwise just look like an "always empty" screen. |
| Sound + visual cue | Web Audio API generates a 2-tone ping in-code (no audio file). Hooked via `Livewire.hook('commit', { succeed })` scoped to this component's `wire:id` — fires once per round-trip after DOM patch, regardless of whether the new ticket arrived as `morph.added` or `morph.updated`. AudioContext is `.resume()`-d defensively before every ping + on `visibilitychange` so backgrounded-tab suspensions don't silently mute the kitchen. A 0.9-second green inset-ring flash on the wrapper accompanies each ping so a muted device still has a visible cue. First ping requires "Tap to enable sound" once per session (browser autoplay rule). |
| Deep-link buttons | POS Home (`Modules/Pos/resources/views/home.blade.php`) shows two buttons next to the session controls: Kitchen (amber, Heroicons solid `fire`) and Shisha (fuchsia, custom 3-curl smoke-wisp SVG). `wire:navigate` — each device pins one screen on rendering. |
| Tests | `tests/Feature/PosKitchenRoutingTest.php` — (1) routing stamps only lines whose category has a station, leaves no-station lines null; (2) `markOrderPreparing` advances exactly one step; (3) re-dispatching `PosOrderPaid` doesn't reset a Preparing line back to Pending. |

A bill containing both food and shisha produces **two tickets on two different
screens** — the same `pos_orders` row, but only the lines whose category routes
here appear on this station. A Kitchen screen never sees Shisha lines and vice
versa; `lineBelongsToStation()` guards every mutation. Orders placed before a
category was assigned a station produce **no** KDS tickets (the listener
correctly routes nothing); the empty-state banner explains the wiring.

**Deliberately OUT of scope this increment** (say so if asked, offer as follow-ups):
restaurant floors / tables, multi-prep-step recipes (e.g. "first prepare the
sauce while the steak rests"), per-line cashier notes pinned to specific
preparation steps, KDS audit trail / replay, expediter view (one screen
showing all stations at once), runner mobile screen, customer-facing screen
showing ticket status, persisting `audio enabled` across browser sessions.

**Phase 16 — Purchases module (`Modules/Purchases/`, shipped 2026-06-08):**

| Concern | Location |
|---|---|
| Manifest | `Modules/Purchases/module.json` (`application:true`, `depends:[base,contacts,pos,inventory,accounting]`, models: Purchase, sequence 25) — depends on all four so the full receive-stock-and-post flow always has its pieces |
| Schema | `purchases` (header: `reference` unique/auto `BILL/<Y>/<id>`, `partner_id`/`user_id` logical refs, `date`, `state`, `is_stock_purchase`, `total`, `notes`, `confirmed_at`) + `purchase_lines` (`purchase_id` FK cascade, `pos_product_id` **logical ref** — the key that links both stock legs, `description`, `quantity`, `unit_cost`, `subtotal`) |
| Enum | `Modules\Purchases\Enums\PurchaseState` — Draft / Confirmed / Cancelled + `label()` (translate at call-site) + `color()` |
| Models | `Purchase` (`DefinesIrModel + Chatterable`; columns match the Accounting listener's `@phpstan-type Invoice` shape so the model IS the event payload; `recomputeTotal()`, `created` hook fills `reference`) · `PurchaseLine` (`saving` hook derives `subtotal = qty × unit_cost`) |
| Confirmer | `Modules\Purchases\Services\PurchaseConfirmer::confirm()` — the 3-way sync, idempotent (no-op once Confirmed). In **one DB transaction**: flip state + per line `raisePosStock()` (POS `stock_on_hand += qty`) and `receiveIntoWarehouse()` (create a Vendor→Stock `StockMove` keyed by `pos_product_id`, then `process()` → updates `stock_quants`). AFTER the txn commits, fires `PurchaseInvoiceConfirmed`; accounting failure is swallowed → logged to the bill's Chatter (a missing COA must never undo a received bill — same convention as the POS receipt/journal listeners). Inventory leg is defensive: if the warehouse topology isn't seeded it logs a skip and POS stock still syncs |
| Event → Accounting | `Modules\Purchases\Events\PurchaseInvoiceConfirmed(Purchase $invoice)`; wired in `AccountingServiceProvider::boot()` via the **string** event name (no compile-time dep on Purchases). `RecordPurchaseInJournal` books Dr Inventory (`is_stock_purchase`) or Purchase Expense · Cr A/P |
| UI | `Modules\Purchases\Livewire\Purchases` (engine list wrapper) + `PurchaseForm` (**custom** master/detail line editor — vendor/date/reference/notes header, repeatable product rows with live subtotal + total, **Save draft** + **Confirm**; a Confirmed bill is read-only with a green "stock + accounting updated" banner). Picking a product prefills description + unit cost from `cost_price` |
| Routes | `Modules/Purchases/routes/web.php` → `/app/purchases` (redirect), `/app/purchases/purchase[/new\|/{id}]` (all `auth`). List rows open the custom form |
| Seeder | `Database\Seeders\PurchaseSeeder` (root `database/seeders/` per convention) — `purchase_user` group + `purchases.purchase` ACL (RWC, no unlink) + a demo "Gulf Coal & Supplies" vendor. Idempotent; deliberately creates **no** confirmed bill (would mutate stock as a seed side effect). Manual run, not in default chain |
| Tests | `tests/Feature/PurchaseConfirmTest.php` (4) — confirm raises POS stock + warehouse quant (Stock up, Vendor down) + posts a balanced journal entry; idempotent (single entry, no double count); `is_stock_purchase=false` debits Expense not Inventory; end-to-end `PurchaseForm` Confirm. setUp re-registers `AccountingServiceProvider` so the event listener is wired (the "module listeners register on the boot AFTER install" gap) |

Install (auto-pulls contacts + pos + inventory + accounting): `php artisan module:install
purchases`, then `php artisan db:seed --class="Database\Seeders\PurchaseSeeder"`. For both
stock legs + accounting to actually post, the warehouse topology (`InventorySeeder`) and
Chart of Accounts (`ChartOfAccountsSeeder`) must be seeded. The buy-coal flow: app-switcher →
Purchases → New → pick vendor + add a coal line (qty × unit cost) → **Confirm** → POS tile
count and Inventory on-hand both rise, and a Dr Inventory / Cr A/P entry posts.

**Deliberately OUT of scope this increment** (say so if asked, offer as follow-ups):
editing/un-confirming a confirmed bill (reversal/credit note), partial receipts (receive less
than ordered), vendor price lists / purchase orders (request-for-quote → PO → bill), landed
costs, multi-warehouse destination picker (always receives into the Receipt op type's Stock
location), per-bill currency (uses the global default), and paying the bill (A/P settlement —
the credit sits in Accounts Payable, no payment/bank reconciliation yet).

**Profile self-service (shipped 2026-05-21):**

| Concern | Location |
|---|---|
| Page | `App\Livewire\ProfilePage` + `resources/views/livewire/profile-page.blade.php`, route `/profile` (auth-only, accessed via topbar user-menu dropdown) |
| Schema | `2026_05_21_300001_add_profile_fields_to_users_table` — `avatar_path` (nullable text) + `new_email` (nullable indexed string) |
| Email change | NOT direct write-through. Save parks new value in `users.new_email`; `App\Notifications\VerifyNewEmail` sends a 1-hour signed verification link to the **new** address via `Notification::route('mail', $new)->notify(...)` (anonymous notifiable — the user's default routing would deliver to the OLD address). URL: `URL::temporarySignedRoute('profile.email.verify', now()->addHour(), ['id', 'hash'])` where `hash = sha256(strtolower(trim(email)) . '|' . config('app.key'))` |
| Verification | `App\Http\Controllers\ProfileEmailVerificationController` (single-action invokable) — checks `hasValidSignature()`, re-derives hash from `users.new_email`, refuses on mismatch (stale link after the user changed their mind → 403). On match: `forceFill(email = new_email, new_email = null, email_verified_at = now())->save()` + redirect to `/profile` with flash. No pending change → friendly redirect (not 4xx). Login NOT required (signature is proof of intent, matches Laravel's stock email-verify convention) |
| Avatar | `WithFileUploads` → `Storage::disk('public')->store('avatars')`; replacing deletes the previous file (idempotent — `delete()` no-ops on missing). `User::avatarUrl()` is the single render path used by both the profile page and the topbar — returns null when `avatar_path` is set but the underlying file is missing on disk (so the initial-letter fallback renders instead of a broken-image icon). Pinned by `ProfilePageTest::test_avatar_url_falls_back_to_null_when_the_underlying_file_is_missing` after a deploy regression wiped the avatars bucket |
| Role | Read-only display via `User::roleLabel()`; form has no `is_admin` input, and `save()` never touches it — pinned by `test_save_does_not_let_user_promote_themself_via_form_state` |
| Password | Optional — requires correct `currentPassword`; `min:8` + `confirmed:newPasswordConfirmation`; all three fields have Alpine eye-toggle (purple `text-primary-600`, independent state). Same eye pattern on the login password field. **Printable-ASCII only** — blade inputs carry an `x-on:beforeinput` filter that `preventDefault`s any keystroke or paste whose `event.data` matches `/[^\x20-\x7E]/`, and `newPassword` carries a matching `regex:/^[\x20-\x7E]*$/` server-side rule (defence in depth for JS-disabled clients / crafted payloads). Each block's Alpine scope holds a debounced `blocked` flag flipped by `notifyBlocked()` on a vetoed press; reusable Blade component `<x-password-ascii-notice />` (in `resources/views/components/`) reads it and fades in an amber pill "English characters only." 2.5 s window past the last attempt. Login form is **not** filtered so users with pre-existing non-ASCII passwords aren't locked out |
| Tests | `tests/Feature/ProfilePageTest.php` (19) — mount prefill, name/avatar/password write-through, email parks + notifies new address (via `assertSentOnDemand` because anonymous notifiable doesn't match user instance), already-taken email rejected, cancel pending clears `new_email`, controller swap on valid sig, refuse on mismatched hash / unsigned / expired, idempotent flash on already-verified, role label correct for admin/non-admin, role can't be promoted via form state |

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
- **Incremental module migrations are NOT auto-applied to the live DB** (general rule).
  A module's migrations run only at `module:install`/`uninstall` (the engine does not
  `loadMigrationsFrom`, so plain `php artisan migrate` ignores module dirs). A migration
  added to an **already-installed** module sits *Pending* on the live DB and the running
  app 500s — yet PHPUnit stays green because `DatabaseMigrations` rebuilds in-memory
  every test. Run on the live DB yourself: `php artisan migrate
  --path=Modules/<Module>/database/migrations --force` (idempotent; check with
  `migrate:status --path=...`). Likewise, after editing a model's `irModelDefinition()`
  (fields/arch, no schema change) run `php artisan module:resync <module>` to re-reflect
  it into `ir_model(_fields)` / `ir_ui_view`. Always do this for every module touched
  and state it in the hand-off — don't let the user find it via a 500.
- **Deploy workflow auto-applies POS + WhatsApp module state.** `.github/workflows/deploy.yml`'s
  remote post-deploy now runs, in order: core `migrate --force` → POS `migrate
  --path=Modules/Pos/database/migrations --force` → WhatsApp `migrate
  --path=Modules/WhatsApp/database/migrations --force` → `SettingSeeder` →
  `PosStaffSeeder` → `module:resync pos` → cache rebuild. Idempotent every push
  to `main`. This means **POS + WhatsApp are self-healing on every deploy** — new
  migrations / arch tweaks land without SSH follow-up, and cashier accounts (`ramadan` /
  `faraj` / `osama`, `pos_user` group, null email) self-restore. `PosStaffSeeder` is
  kept OUT of the default seed chain (memory: `[[null-email-seed-breaks-migrate-rollback]]`)
  — it's invoked only by the workflow's remote step. **Other modules** (Contacts,
  Inventory) still need manual SSH after their own incremental migrations or arch edits.
- **Queue worker on Hostinger Cloud (no persistent processes).** Outbound WhatsApp
  sends go through the `database` queue (`SendWhatsAppMessage` job). Hostinger Cloud
  doesn't run daemons (no systemd / supervisor available), so we hook Laravel's
  scheduler in `routes/console.php`: `Schedule::command('queue:work --stop-when-empty
  --max-time=50')->everyMinute()->withoutOverlapping()->runInBackground()`. A single
  `* * * * *` cron in hPanel runs `php artisan schedule:run` and drains the queue
  every minute. **If the cron is missing, queued jobs sit in the `jobs` table forever**
  (symptom: `whatsapp_messages_log.status='queued'` never advances to `sent`). Use
  `ps aux | grep queue:work` to verify nothing else is running first. Same scheduler
  also runs `prune-whatsapp-receipts` daily to drop PNGs older than 7 days.
- **`AuthSeeder` is deliberately NOT in the deploy workflow.** Adding it would reset
  `admin@example.com`'s password to the seeded value on every push — a footgun. Admin
  + sales user creation is a one-time bootstrap; once prod has them, leave them alone.
- **Prod mail transport is environment-specific.** `.env` and per-host SMTP
  creds never ship from the repo (rsync excludes `.env*`). NOTE: `lang/` **does**
  ship — it is NOT in deploy.yml's rsync `--exclude` list, so committing an updated
  `lang/ar.json` and pushing to `main` deploys the new translations to prod (the
  workflow's `optimize:clear` clears stale caches). Dev typically uses
  Mailtrap sandbox (`sandbox.smtp.mailtrap.io`) — Bahrain ISPs frequently block
  outbound 2525, so port 587 with `MAIL_ENCRYPTION=tls` is the fallback. Prod uses
  Hostinger SMTP (mailbox created in hPanel → SMTP creds pasted into prod `.env` via
  SSH/File Manager → `php artisan config:clear`).
- **OS:** development host is Windows. Prefer cross-platform tooling and forward slashes
  in code; never hardcode `C:\` paths.

---

## 7. Definition of Done (every task)

1. Strict types + full return types in all touched PHP files.
2. `composer analyse` green (PHPStan level 6).
3. `composer test` green.
4. Phase status table in §5 updated if a phase milestone was reached.
