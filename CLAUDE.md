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
| App switcher | `App\Livewire\Navigation\AppSwitcher` → installed `application` modules. **As of 2026-06-09 this is an always-visible inline app BAR in the topbar, not a 9-square dropdown** (see Shell & branding increments below). **As of 2026-06-12 each app whose module registers readable models is a DROPDOWN of those models** (the same `ModuleMenu::items` entries as its app-home tiles — one shared source) so any list is one hop from the topbar; apps with no models stay a plain home link (see increment below) |
| Command palette | `App\Livewire\Navigation\CommandPalette` (⌘K/Ctrl+K, fuzzy, `open-command-palette` event) |
| Contextual sidebar | **REMOVED 2026-06-10.** Every app now lands on its own tile dashboard (see below); app-to-app nav is the topbar app bar. `App\Erp\Navigation\ModuleMenu` (the old sidebar's model-list logic) lives on and drives the tiles |
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

**Shell increment — Odoo-style app-home tile dashboards (shipped 2026-06-10):**

- **Every app landing page is now a dashboard of clickable containers** —
  one tile per registered `ir_model` the user may Read, navigating to that
  model's List view (mirrors the contextual sidebar). New shared engine
  piece `App\Erp\Navigation\ModuleMenu::items(IrModule, ?Authenticatable)`
  returns the ACL-filtered, slug-resolved menu (the sidebar's logic was
  **extracted into it**, so `Sidebar`, `ModuleHome` and `PosHome` all read
  the same source and can't drift). Shared view: `resources/views/partials/
  module-tiles.blade.php` — responsive grid of icon-badge + label + chevron
  cards (per-model Heroicons map keyed by model id; coloured letter-badge
  fallback; brand-yellow hover carries `text-chrome-900` per the rebrand
  rule; chevron flips under `dir="rtl"`).
  - `App\Livewire\Pages\ModuleHome` (`/app/{module}`) — was a bare grid of
    raw dotted model ids with **no ACL filter**; now renders the partial,
    ACL-filtered. Covers Contacts / Accounting / Project and any future app
    whose home isn't custom-overridden.
  - `Modules\Pos\Livewire\PosHome` keeps its register/KDS cards and gains a
    **"Manage"** tile section below them (Customer Discount / POS Category /
    Condiment / Order / Product / Session — each respecting the viewer's
    Read ACL, so cashiers don't see the admin-only Customer Discount tile).
  - Tests: `tests/Feature/ModuleMenuTest.php` (admin sees all + correct URLs,
    non-admin only readable models, guest sees nothing, ModuleHome renders
    tiles). AR keys added: `Manage`, `Application module`.

- **Sidebar removed — every app lands on its own dashboard (shipped 2026-06-10)** —
  the contextual left sidebar (`App\Livewire\Navigation\Sidebar` + its view)
  was **deleted** from `components/layouts/app.blade.php` (along with the
  collapse toggle + mobile drawer). Each app's landing now IS its dashboard,
  reachable from the topbar app bar:
  - **Contacts / Accounting** — `/app/{module}` falls through to the core
    `/app/{module}` → `ModuleHome` tile dashboard (no module home route).
  - **POS** — `PosHome` (register/KDS controls + a "Manage" tile section).
  - **Project** — `ProjectHome` (the project-board picker) **gained** the same
    "Manage" tile section (Project + Task) so its models stay reachable without
    the sidebar. Tiles come from `ModuleMenu::items('project', user)`.
  - **Purchases** — the `/app/purchases` **redirect was removed** so it now
    falls through to `ModuleHome` (Purchase tile). Test:
    `PurchaseConfirmTest::test_purchases_app_lands_on_its_tile_dashboard`.
  - **Inventory** — keeps its custom `InventoryOverview` (operation-type cards
    + KPIs); it registers **no** `DefinesIrModel` models, so it has no model
    tiles — the Overview already IS its dashboard.
  - **Main dashboard** (`/`, daily report + KPIs) is reached via the **brand
    logo** (now `wire:navigate` to `/`, title "Home") or the breadcrumb
    **Home** link — there's no longer a sidebar "Dashboard" entry.
  - `ProjectHomeTest`'s old `Sidebar` render test was rewritten to assert
    `ModuleMenu` primary-model→home URL mapping directly. `ModuleMenu` doc +
    the §3 shell table row updated. Unused AR keys (`Toggle sidebar`, sidebar
    `Workspace`/`Pick an app to begin`) left in place (harmless).

**Shell increment — app-bar dropdowns (shipped 2026-06-12):**

- **Each app in the topbar app bar is now a DROPDOWN of its models** —
  `AppSwitcher` loads, per installed `application` module, the same
  `App\Erp\Navigation\ModuleMenu::items($module, $user)` list its app-home
  tile dashboard uses (one shared, ACL-filtered source — they can't drift),
  and renders each app as a click-to-open dropdown: a header link to the
  app home + one row per readable model → its resource URL. Apps whose
  module registers **no** `DefinesIrModel` (Inventory, Settings, WhatsApp)
  have an empty list and stay a **plain home link** (prior behaviour).
  **Each app is its OWN isolated Alpine scope** (`x-data="{ open, coords }"`)
  — the proven orders-3-dot-menu pattern — with `@click.outside` on the root
  + `@keydown.escape.window` to close; clicking another app's trigger bubbles
  a document click that closes the previous one. **The panels use
  `position: fixed` with viewport coords captured on open** — the app bar is
  `overflow-x-auto`, which also clips vertical overflow, so an in-flow
  `absolute` panel would be cut off; a `fixed` panel anchors to the viewport
  and escapes the clip (no transformed ancestor exists to trap it), while
  staying a DOM child of its root so `@click.outside` still works. RTL-aware:
  anchors to the trigger's end edge under `dir="rtl"`. **Avoid both a shared
  open-state AND `x-teleport` here** — the first iteration teleported panels
  to `<body>` and a shared `openApp`; across `wire:navigate` the panels
  stranded/stacked (every app's menu showing at once). Isolated per-app
  scope + in-tree `fixed` is what actually holds up. Test:
  `ShellNavigationTest::test_app_dropdown_lists_an_apps_models` (POS dropdown
  surfaces "POS Category" + its `/app/pos/category` URL). Reused the existing
  per-module Heroicon set; no new `lang/ar.json` keys (model labels flow
  through the existing `__()` entries).

**Shell & branding increments (shipped 2026-06-09):**

- **Always-visible topbar app bar** (replaced the 9-square dropdown) —
  `AppSwitcher` now renders every installed `application` module as an inline
  row of icon+label links in the topbar (flexible middle region, horizontal-
  scroll on overflow), with the current module highlighted. New
  `AppSwitcher::$activeModule` prop (passed `:active-module="$activeModule"`
  from the layout, mirrors `Sidebar`). The grid trigger button is gone.
  `app-switcher.blade.php` is now a `<nav>` of links (no Alpine dropdown);
  active = filled pill, hover = subtle overlay. Test:
  `ShellNavigationTest::test_app_switcher_lists_only_installed_applications`
  still green (each app still renders its label). Added `module.project` AR key.
- **Breadcrumb moved to its own bar under the topbar** — the breadcrumb left
  the topbar (which the app bar now fills) and became a dedicated full-width
  strip directly below `</header>` in `app.blade.php`: light `bg-white`
  border-bottom strip, muted dark breadcrumb text, `hidden md:flex` (off on
  phones to save vertical space). Same segment-link logic +
  `breadcrumb_terminal_label` request-attribute override as before. AR key
  `Breadcrumb` added.
- **Rebrand: purple → bright yellow `#F5EF1A`** — the `primary` Tailwind
  palette (`tailwind.config.js`) flipped from Odoo aubergine to a **monotonic
  lemon-yellow ramp**: bright `#F5EF1A` at `400` (chrome backgrounds), dark
  gold/olive at `600–900` (so the many `text-primary-*` accents stay legible
  on white). Because white text is unreadable on the bright fill, every solid
  brand surface flips to **dark text** (`text-chrome-900`) + black/N hover
  overlays: topbar, app bar, `o-btn-primary` (`bg-primary-400 text-chrome-900`),
  filter/preset/locale chips, pagination current page, settings tabs, avatars/
  badges, KPI + daily-sale tiles, module-home tile, condiment checkbox, file-
  input button, login page. Toggles use the deeper `500`. Brand favicon/
  wordmark SVGs (`public/brand/openerp-{mark,logo}.svg`) recoloured to a yellow
  tile with dark glyphs; leftover `#714b67` project-colour defaults → `#f5ef1a`.
  **Rule for new chrome: any solid `bg-primary-400/500` fill carries
  `text-chrome-900`, never `text-white`.**

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

**Phase 4 increment shipped 2026-06-09 — engine `file` (document) widget:**

- **Generic `file` widget** — the document sibling of the `image` widget, for
  any `DefinesIrModel` form. Declare `'widget' => 'file'` in form arch and the
  field renders a PDF/image uploader showing the current file as a "view" link.
  Pieces: `App\Http\Controllers\FormFileUploadController` (POST
  `/form/upload-file`, `throttle:30,1`, `form.upload-file`; accepts
  `mimes:pdf,jpg,jpeg,png,gif,webp,bmp,avif,heic,heif`, `max:8192` (8 MB), **no
  SVG** — same stored-XSS reasoning as the image controller; bucket whitelist,
  bucket = the model's table name like the image widget). `FormView::$filePaths`
  (`<attr> => path`) mirrors `$imagePaths` — written to the record in `save()`,
  routed through `autoSave()` by the `updated()` hook (`filePaths.` prefix),
  and the field is skipped in the `$form` loop + `rules()` (validated in the
  controller). `FormFieldDef::isFile()`, `'file'` added to `ViewArch`'s widget
  whitelist, full-width in the form grid. Blade `@case('file')` is an Alpine
  `fetch`-POST uploader (same shape as `image`) that sets
  `$wire.filePaths.<field>`. **Every bucket the file widget writes to must also
  be in `deploy.yml`'s rsync `--exclude` list** (memory:
  `[[rsync-delete-wipes-user-uploads]]`). First consumer: Accounting `Account`
  (Phase 14 increment). Test: `tests/Feature/AccountFileFieldTest.php`.

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

**Engine hardening — privilege escalation closed (2026-08-24).** A code review found
the engine view components could be repointed by the browser into a full account
takeover. Three defences, all now required whenever this code is touched:

1. **`HasAccessControl::may()` DENIES on an empty `$modelKey`.** It used to
   short-circuit to *allow* — which made a blank key a wildcard past every
   permission check. All 45 `<livewire:views.*>` call sites pass an explicit
   `model-key`, so a blank key only ever means tampered/misconfigured.
2. **`#[Locked]` on the binding properties** — `$model`, `$modelKey`, `$recordId`
   (+ `$redirectTo` on FormView) in `FormView`/`ListView`/`KanbanView`. Livewire 3
   lets the browser set ANY unlocked public property, so without this a user could
   point a form they may legitimately open at `App\Models\User` row 1. **Any new
   public property that identifies a record or drives a permission check must be
   `#[Locked]`.**
3. **`FormView::save()` whitelists the upload buffers.** `$imagePaths`/`$filePaths`/
   `$uploads` are public properties, so their KEYS are attacker-controlled; each is
   now matched against the arch's declared `isImage()`/`isFile()` fields before
   `setAttribute()`. Without it, anyone with legitimate Write on a model could set
   any column of it (a settled order's `total`, a user's `is_admin`), bypassing the
   `readonly` skip and every recompute hook.

Tests: `AccessControlTest::{test_an_empty_model_key_denies_instead_of_allowing,
test_the_binding_properties_cannot_be_repointed_by_the_browser,
test_upload_paths_cannot_write_undeclared_attributes}`.

**Screens that were missing their ACL (2026-08-24).** `mount()`-only gates are not
gates — Livewire runs `mount()` once and then dispatches straight to methods, so
**every mutating action re-checks**:

- `ProductionForm` (mount + `save`/`delete`/`reverse`/`reopen`/`saveAsFormula`) and
  `Productions` (mount + `moveToShop`/`moveToStore`) → **`pos.product`** Read/Write,
  the same key the recipe editor uses. Cashiers hold no `pos.product` grant, so
  production stays with managers — previously any logged-in account could deduct raw
  materials, rewrite `cost_price` and inflate `store_stock`. (`recomputeCosts` /
  `removeFromStore` keep their extra `isAdmin()` check.)
- `KitchenDisplay` (mount + `advance`/`markOrderPreparing`/`markOrderReady`/
  `completeOrder`) → **`pos.session`**, which the `pos_user` cashier group holds and
  an unrelated staff account does not. Previously anyone logged in could clear the
  whole kitchen queue (and, in postpaid mode, unlock the dine-in pay gate).

Test: `PosKitchenRoutingTest::test_the_kitchen_screen_requires_pos_session_access`.
NOTE: the three existing "non-admin is blocked" production tests now assert
`->assertForbidden()` on the **mount**, not on the action — the gate fires earlier.

**Audit follow-up — high & medium findings closed (2026-08-24).** A full-codebase
review produced a ranked list; the critical two shipped first (above), then these.
Each has a regression test that fails without the fix.

*Money & stock*

- **Purchase confirm counted warehouse stock twice.** `raisePosStock()` fires
  `PosProduct::saved` → the POS→Inventory quant mirror, and `receiveIntoWarehouse()`
  then applied the SAME quantity again (4 on hand + a 10-unit bill showed 24, not 14).
  New `PosInventoryBridge::withoutQuantSync(Closure)` suppresses **only** the mirror
  (the WooCommerce push and every other `saved` listener still fire); `PurchaseConfirmer`
  wraps the product leg in it. **Rule: code that posts its own stock move for a
  quantity must not also let the generic mirror apply it.** Test:
  `PurchaseConfirmTest::test_confirming_a_purchase_does_not_count_the_warehouse_stock_twice`
  (registers `PosServiceProvider` so the hook is live — without that the bug is invisible).
- **Prepaid credit could be spent twice.** `consumeCustomerCredit()` reconciled
  `credit_applied` down to what `drawCredit()` actually gave but left `total` computed
  against the PREVIEWED credit — so if another terminal emptied the wallet first, the
  goods left for free. It now `recalculate()`s (the frozen `credit_consumed` figure is
  used) and logs the re-pricing to Chatter; `finalizeSale()`/`confirmUnpaid()` draw the
  wallet **before** `markPaid()` so `paid_total`/`change_due` see the final total.
- **Splitting a credit-paid order invented revenue.** The new order carried no credit,
  re-priced at full price and came out unpaid. `PosOrderSplitter::apportionCredit()`
  shares the consumed credit across both orders by the goods each holds and marks both
  `credit_consumed` (neither draws the wallet again). Done-splits only; a draft split
  still previews live and is reconciled at checkout by the fix above.
- **A stale terminal tab could rewrite a PAID order.** Every mutating `PosTerminal`
  action goes through `editableOrder()`, which throws a `ValidationException`
  ("This order is already closed") unless the order is Draft; the read paths (`mount`,
  `render`, receipt) keep using `order()`. `$sessionId` / `$orderId` / `$tableId` /
  `$receiptOrderId` are `#[Locked]`.
- **A discounted sale stopped reaching the books.** With `sales_tax_payable` mapped,
  `RecordPosSaleInJournal` credited `subtotal + tax` (list price) while debiting the
  total — so any discount / prepaid credit / delivery charge unbalanced the entry,
  `JournalPoster` refused it and the sale posted NOTHING (the error went only to the
  order's notes). Sales income is now `total − tax`, so it always balances, and a
  failure is `Log::error`'d as well. Test: `tests/Feature/PosSaleJournalTest.php` (3).
- **Two accounting listeners deleted a POSTED entry outside a transaction**
  (`RecordSettlementInJournal`, `RecordDeliveryCostInJournal`) — a rewrite that stopped
  part-way lost the entry for good. Both wrap clear + record in one `DB::transaction`.
- **Money in Transit (1150) existed only in `ChartOfAccountsSeeder`**, which no deploy
  runs, so affected databases silently skipped the delivery postings. Data migration
  `2026_08_24_400010_add_missing_core_accounts` inserts it (and its parents) **only if
  absent** — an existing account, renames and all, is left untouched.

*Production*

- **Reversing recomputed quantities from the CURRENT pack size.** Correct "Each unit
  is" between the run and the reversal and it handed back a different amount than it
  took, losing the difference. `pos_production_lines.stock_qty` (migration
  `2026_08_24_700070`) records what `applyStock()` actually deducted and `reverseStock()`
  returns exactly that; lines from before the column fall back to the old maths.
- **Recording a run was not atomic** and the button stayed live while saving. The run,
  its lines and its stock are one `DB::transaction` now, and the Record button carries
  `wire:loading.attr="disabled"`.

*Access control (see also the two sections above)*

- **Rental + Limousine bespoke screens had NO permission check** — 12 hand-written
  forms (orders, quotations, invoices, receipts, bookings, maintenance, replacements,
  car costs) that manage records themselves instead of embedding the engine's
  list/form. New shared trait **`App\Livewire\Concerns\GuardsModelAccess`**
  (`accessModelKey()` + `guardAccess()` / `mayAccess()` / `guardSave(bool $isNew)`):
  Read to open, Write to act, Create for a new record. Their `$id` is `#[Locked]`.
  **Any new bespoke module screen must use this trait** — an engine-free screen is
  otherwise protected by nothing but `auth`. Test: `tests/Feature/RentalAccessControlTest.php`.
  New helper `Tests\TestCase::grantEveryone(...$models)` for tests whose subject is a
  ROLE rule (accountant / manager) rather than the ACL itself.
- **Payroll / employee / monthly-profit screens** checked admin only in `mount()`;
  every action re-checks via a shared `guardAdmin()`.
- **The Chatter panel had no check at all** and its target came off the browser, so any
  logged-in user could read the history of records they cannot access and post forged
  notes into them. It now takes the same permissions as the record it hangs off
  (Read to view, Write to post) via the model's `irModelDefinition()->model` key, and
  `$modelClass` / `$modelId` are `#[Locked]`. A model outside the registry has no key
  and falls through as before.
- **The admin email OTP could be guessed indefinitely.** `admin_otp_challenges.attempts`
  (migration `2026_08_24_100001`): five wrong codes destroy the challenge, and
  `TwoFactorGate::challenge()` is rate-limited to 6 fresh codes per (user, action) per
  hour — it now returns `bool`, and `ConfirmsWithEmailOtp` refuses to open a prompt it
  cannot satisfy.

*Performance & multi-database*

- **Editing a material cost re-priced the whole catalogue on every keystroke** (the
  ethanol-cost 504). Two fixes: `PosProduct::{beginCostBatch,endCostBatch}` memoise
  `productionCost()` for the pass (an offer reads its perfume components' costs, and the
  same perfume backs several offers), and `ProductionCostSync::refreshOncePerRequest()`
  coalesces the per-save trigger to one run per request (the flag lives in the
  **container**, so it is naturally per-request and per-test). The manual "Recompute
  costs" button still calls `refreshAll()` directly.
- **List views eager-load what their cells read.** New arch key **`'eager' => [...]`**
  (parsed by `ViewArch`, applied in `ListView::buildQuery()`). `PosProduct` declares
  `category` + `recipeLines.*` — its "Category" and "Available servings" columns are
  accessors, so a 20-row page ran ~68 queries and repeated it on every sort click and
  every keystroke. **Declare `eager` whenever a list column reads through a relation.**
  Test: `tests/Feature/ListViewEagerLoadTest.php`.
- **WhatsApp sends from a tenant used MAIN's credentials** and stamped an unrelated
  message log, because the queue is pinned to Main. `SendWhatsAppMessage` now carries
  `workspaceId` and re-activates it — via the new shared
  **`WorkspaceManager::runFor(?int $workspaceId, Closure)`** (WooCommerce solved this
  its own way first). **Any new queued job touching per-database data must use it.**
- **Daily jobs only ever ran against Main** — a second database never got its 6 AM
  report and its lapsed discounts stayed "active". New
  **`App\Erp\Tenancy\EachDatabase::run(Closure)`** (Main + each workspace; one failure
  never stops the rest) now wraps `expire-customer-discounts` and `daily-pos-report`
  in `routes/console.php`.
- **A workspace ran on Main's timezone.** The timezone is read at boot, before the ERP
  knows which database it serves. Extracted to `App\Erp\Settings\CompanyTimezone`
  (`apply()` / `current()` / `restore()`), re-applied by `WorkspaceManager::activate()`
  and inside `withTenant()` (restored afterwards). Test:
  `tests/Feature/ScheduledTenantTasksTest.php` (3).
- **A restore rolled back the list of databases.** `workspaces` is now in
  `DatabaseBackup::EXCLUDED` — it points at real SQLite FILES, so rewinding it made
  databases created since unreachable and brought deleted ones back pointing at
  nothing. The confirm dialog now also says staff accounts, passwords and roles are
  rolled back (they still are — that is what restoring a database means).
- **WooCommerce:** a create whose reply was lost duplicated the product on retry —
  `findRemoteId()` adopts an existing listing by SKU (then exact name) before POSTing.
  "Sync all" works to a 20-second budget and reports `remaining` so the admin presses
  again instead of dying past the web server's timeout. The `PosOrderPaid` push is
  wrapped in try/catch — unlike every other checkout listener it could show the cashier
  an error screen AFTER the money was taken.
- **`ModuleHome` ignored the business type**, so typing `/app/rental` opened Rent A Car
  in a perfume shop. Now `abort_unless(Features::moduleAllowed($module), 404)` — the
  same gate every menu uses.
- **`deploy.yml`** gained the missing `migrate --path=` steps for **Contacts** and
  **Project**, and `module:resync` for **contacts / purchases / inventory** (resync is a
  silent no-op when a module is not installed).

**Everything on that list is now done** except the Rental/Limousine ledger question —
see the section below, which closes the low-severity tail.

**Audit follow-up — the low-severity tail closed (2026-08-24).** The remainder of
the same review. With these the whole ranked list is done except the Rental /
Limousine ledger question, which is a business decision, not a defect.

- **Arabic coverage is now a TEST, not a habit.** `tests/Feature/ArabicCoverageTest.php`
  scans every `__('literal')` under `app/`, `Modules/`, `resources/`, `routes/` and
  `database/` and fails when one has no `lang/ar.json` entry — a missing key renders
  the English source silently, so an Arabic screen used to regress one phrase at a
  time unnoticed. 86 keys were added to clear the backlog. **A string that is meant
  to stay English goes in the test's `ENGLISH_BY_DESIGN` list with its reason** (today:
  the WooCommerce + Cloudflare Stream settings tabs, `PDF`, `ml`, `—`, `…`), so an
  exemption is a decision on the record rather than an oversight.
- **The product-import and inventory-transfers screens were entirely untranslated** —
  every string was raw text, which is why the `__()` scan had never seen them. Both are
  wrapped now, and their physical-direction utilities (`text-left`/`text-right`/`mr-`/
  `right-0`/`origin-top-right`) flipped to logical ones so they mirror under `dir="rtl"`,
  along with the product list's Import/Export dropdown.
- **Money that bypassed the currency setting.** The printed rental agreement and the
  rental sales matrix hard-coded **3** decimals against the 2-decimal policy; both read
  `Currencies::active()->decimals` now (the agreement keeps plain digits — its form
  already prints "BD"). The journal-entry screen's debit/credit figures went through
  raw `number_format()` and now use `ValueFormat::money()`. Pinned by
  `RentalAgreementTest::test_agreement_renders_the_order_values`.
- **Changing your email never worked inside a workspace.** The verify route is
  deliberately open to guests (so the link works from any device), which means no
  workspace cookie routes the request — it resolved against **Main** and looked up a
  different account with the same row id, normally dead-ending on "your email is
  already up to date". The signed link now carries a `ws` parameter and the controller
  resolves through `WorkspaceManager::runFor()`. The signature covers the parameter, so
  it can't be pointed at another database. Test:
  `tests/Feature/ProfileEmailWorkspaceTest.php` (2).
- **Bulk collection of remote orders could time out.** Collecting fires the paid-order
  automations, one of which renders a receipt image (PDF → PNG) per order — a dozen
  orders is a dozen renders in one request. `RemoteOrders::collectSelected()` now works
  to a 20-second budget (`BULK_BUDGET_SECONDS`), leaves the unprocessed orders ticked
  and says how many are left, so a second press continues. Each order commits on its
  own, so what got done stays done. Same shape as the WooCommerce "sync all" budget.

**Still open, deliberately:** Rental and Limousine revenue does not post to the general
ledger. POS sales and purchases do. That may be intended — decide it as a business
question before building it.

**Second security pass — two gaps the first audit missed (closed 2026-08-24).** A
full-codebase run by the `security-auditor` subagent (`.claude/agents/security-auditor.md`)
found the earlier audit's fixes all intact but surfaced two it hadn't reached:

- **Rental & Limousine index / report / home screens had NO permission check.** The
  2026-08-24 audit added `GuardsModelAccess` to the *forms* and gated the engine-list
  screens (Customers/Vehicles), but the bespoke *list / report / dashboard* components
  render business data straight from `render()` and were protected by nothing but `auth`
  — so any logged-in low-privilege account (a POS cashier, a view-only staff account
  granted only Contacts) could browse to `/app/rental/order`, `/app/rental/reports`,
  `/app/rental/sales`, `/app/rental`, `/app/limousine/booking`, `/app/limousine/reports`,
  `/app/limousine` etc. and read every customer's PII, all orders/invoices/receipts, and
  the revenue/target reports. **Fix:** the same `GuardsModelAccess` trait now gates
  `mount()` (Read) on all 15 screens — Rental `Orders`/`Invoices`/`Receipts`/`Quotations`/
  `MaintenanceRecords`/`Replacements`/`Reports`/`Sales`/`RentalHome` and Limousine
  `Bookings`/`Invoices`/`Receipts`/`Quotations`/`Reports`/`LimoHome` — keyed to each
  screen's model (`rental.order`/`.invoice`/`.receipt`/`.quotation`/`.maintenance`/
  `.replacement`, `limousine.booking`/`.invoice`/`.receipt`/`.quotation`; the reports/
  home/sales screens use the module's primary model key). **Rule reaffirmed: every
  bespoke module screen — list AND form AND report AND home — uses `GuardsModelAccess`;
  an engine-free screen is otherwise protected by nothing but `auth`.** Test:
  `tests/Feature/RentalAccessControlTest.php` (data-provider over all 15 screens: a
  non-granted staff account is forbidden, a granted one gets 200).
- **POS receipt PNGs sat at enumerable public URLs.** `PosReceiptImageRenderer` writes
  each paid order's receipt PNG to the **public** disk (Meta must fetch it unauthenticated)
  under `whatsapp-receipts/{sanitised-ref}-{id}.png` — a fully predictable name over small
  sequential integers, so an unauthenticated attacker could enumerate URLs and harvest
  every customer's phone number, name and order details inside the 7-day retention window.
  **Fix:** `safeFilename()` now appends `Str::random(32)` after the id. The filename is
  never re-derived (the only caller — `SendPosOrderReceiptViaWhatsApp` — hands the returned
  URL straight to WhatsApp; the print controller uses HTML view-data, the prune task scans
  the directory), so no token needs persisting and no migration is required; a re-render
  just writes a fresh file and the daily prune reaps the orphan. Test:
  `tests/Feature/PosReceiptFilenameTest.php` (unit — recognisable prefix kept, random
  suffix present, two renders differ). **Rule: anything written to the public disk that
  embeds customer data must carry an unguessable filename** (a random token), not just a
  sequential id.

**Bespoke forms scroll to the first invalid field (shipped 2026-08-27).** On a tall
hand-written form (the limousine booking was the report — a required *Requested by*
sitting below the fold) the stock `$this->validate()` rendered the inline `@error` but
left the viewport put, so pressing **Save** looked like it did nothing — the message the
user needed was off-screen and the page never scrolled. New reusable trait
**`App\Livewire\Concerns\ScrollsToFirstError`** exposes `validateFocusing(...)`, a drop-in
for `$this->validate(...)` with the same signature: on failure it reads the first key from
the validator's error bag and `$this->dispatch('scroll-to-error', field: <key>)` **before
re-throwing** (the exception is unchanged, so every inline `@error` still renders exactly
as before — the Livewire dispatch survives the thrown `ValidationException`, verified by
test). The master layout's `<body>` carries
`x-on:scroll-to-error.window="window.scrollToFieldError($event.detail.field)"` (same pattern
as `record-saved`/`theme-changed`), and `window.scrollToFieldError` (in `resources/js/app.js`)
scans `input/select/textarea` for the control whose `wire:model` (any modifier) value equals
the field — nested keys like `legs.0.start_at` included — `scrollIntoView({block:'center'})`s
it and focuses it (both honour `prefers-reduced-motion`). Wired into **all 11 bespoke
Rental + Limousine forms** (every `*Form` that calls `$this->validate()` — Rental
Customer/Invoice/Maintenance/Order/Quotation/Receipt/Replacement, Limousine
Booking/Invoice/Quotation/Receipt). **Rule: a bespoke form's save uses `validateFocusing()`,
not `validate()`** — the engine FormView already scrolls its own errors, so this is only for
the hand-written module forms. No new user-facing strings (nothing to translate); the JS
ships via deploy's `npm run build`. Test: `tests/Feature/ScrollsToFirstErrorTest.php` (failed
save dispatches the first failing field, a later required field is the one pointed at, a valid
save dispatches nothing).

**Mobile form polish (shipped 2026-09-03).** Two touch-device fixes in the ERP chrome
(not the WordPress plugin — that's a separate codebase):

- **No iOS auto-zoom on inputs.** iOS Safari zooms the page whenever a focused input's
  font-size is `< 16px`. Rather than lock the viewport scale (which also kills pinch-zoom,
  an accessibility regression), a `@media (max-width: 767px)` block at the **end of
  `resources/css/app.css`** forces `font-size: 16px` on `input` (except checkbox/radio/range),
  `select`, `textarea`. Light-mode/desktop untouched.
- **Native OS date picker on phones.** The shared **`<x-date-field>`** component
  (`resources/views/components/date-field.blade.php`) draws a desktop-only formatted text
  overlay + calendar button on top of a real (hidden) `datetime-local`/`date` input. On a
  phone that overlay is useless — the scripted picker is unreliable — so a
  `@media (pointer: coarse)` block in `app.css` **reveals the real native input** (class
  `date-field-native`) as the visible, tappable control and **hides** the desktop text box
  + calendar button (`date-field-text` / `date-field-cal`). So mobile taps open the phone's
  own OS date/time wheel. **Do NOT "fix" the empty/hidden desktop overlay or the
  0-opacity native input — that layering is deliberate** (the native input carries the
  Livewire binding on both platforms; only its visibility swaps by pointer type).

Also that day: the limousine leg **Rate/Discount/VAT** number fields now show a **`0`
placeholder** instead of a literal `'0'` value (`HandlesTripLegs::emptyLeg()` seeds `''`,
`legs.blade.php` adds `placeholder="0"`), so typing doesn't have to clear a leading zero.
Rate stays `required|numeric`, discount/VAT are `nullable|numeric` (Laravel `nullable`
accepts `''`), and `buildLegs()` coerces blank → 0 at save.

**Phase 7 — Point of Sale module (`Modules/Pos/`, depends on `contacts`):**

| Concern | Location |
|---|---|
| Schema | 7 base tables: `pos_categories/products/payment_methods/sessions/orders/order_lines/payments` + `product_recipes` (static BoM); `pos_products.stock_on_hand`, `pos_orders.components_consumed`. **User binding:** `pos_sessions.user_id` = who *opened* the shared register; `pos_orders.user_id` (cashier audit — `2026_05_19_200004`, nullable plain-indexed *logical ref*, populated in app logic); `pos_session_participants` (`2026_05_19_200005`: session+user unique, `last_activity` heartbeat) |
| Models | `Modules\Pos\Models\*` — `PosProduct/PosCategory/PosSession/PosOrder` are `DefinesIrModel`; `PosSession/PosOrder` are `Chatterable`; line/order recompute (discount → tax). `PosSession::user()`(opener) / `participants()`; `PosOrder::user()` (cashier) `belongsTo(User)`; `PosSessionParticipant` (presence) |
| Categories | `pos_categories` gained `parent_id` (logical ref) + `slug` (unique) + `image` via `2026_05_19_200006`. `PosCategory` self-nests (`parent()`/`children()`/`subtreeIds()` — iterative, visited-set, cycle-safe); a `saving` guard nulls a parent that is self/descendant and normalises the slug. **The slug is EDITABLE (2026-07-08):** a `Slug` `text` field in the form arch (+ registry `FieldDefinition`); the `saving` hook runs `Str::slug()` on whatever the admin types then `uniqueSlug()` (excludes self, so unchanged = no-op; a collision appends `-2`), and a **blank** slug still regenerates from the name. Slug is used only within the model (no routes/terminal depend on it), so editing is safe. Tests: `PosModuleTest::{test_category_slug_is_editable_and_sanitised,test_a_hand_typed_slug_stays_unique}`. Managed by the **engine** (`DefinesIrModel`, `pos.category` in manifest `models[]`): List+Form CRUD at `/app/pos/category[/new|/{id}]` (`PosCategories`/`PosCategoryForm`), parent picker = dynamic `optionsFrom` select (`excludeSelf`). `PosProduct` form arch has a dynamic `pos_category_id` select. Terminal filters by **subtree** (`whereIn(categorySubtreeIds())`) and shows top-level + sub-category chips; search stays category-aware. **As of 2026-06-23:** (a) `pos_categories.active` (boolean, default true; migration `2026_06_23_700010`) — an **inactive category is hidden from the register** (the terminal's top-level + sub-category chip queries filter `where('active', true)`; admin forms still list all). Form arch gained an **Active checkbox** (model `$attributes['active']=true` so a new category form defaults on — the FormView-coerces-unticked-checkbox-to-false pitfall) + a list **toggle** column (`format: toggle`). (b) The category **`image` field flipped from a `text` "emoji or image path" input to the engine `image` upload widget** (bucket = `pos_categories`, already whitelisted + deploy-excluded). The terminal chip render now branches: a `/`-bearing value renders as an `<img>` (uploaded), else as an emoji `<span>` (back-compat with existing emoji categories). Arch changed ⇒ `module:resync pos` (deploy runs it); migration auto-applies via deploy's POS migrate step. Tests: `PosModuleTest::{test_new_category_form_defaults_active_to_true,test_inactive_category_is_hidden_from_the_register,test_category_list_toggle_active_flips_and_persists}` |
| Static ingredient consumption | `PosProductRecipe` (parent→component, `quantity_consumed`). A component is **a product (`component_product_id`), a condiment (`component_condiment_id`), OR an ingredient (`component_ingredient_id`)** — all stock-tracked (`pos_condiments.stock_on_hand` added 2026-06-22, migration `2026_06_22_700001`; `product_recipes.component_condiment_id` + `component_product_id` made nullable, migration `2026_06_22_700002`; `product_recipes.component_ingredient_id` added 2026-06-24, migration `2026_06_24_700012`). `PosProductRecipe::{isCondiment(),isIngredient(),componentName(),componentStock()}` abstract over the three (`resolveComponent()` returns the inline `PosProduct|PosCondiment|PosIngredient|null` union so the `name`/`stock_on_hand` accessors stay statically typed). `PosOrder::finalizeSale()` wraps `markPaid` + Done + `consumeComponents()` in **one DB transaction**; `consumeComponents()` decrements the component's `stock_on_hand` by `qty_consumed × line.qty` (product → also Inventory-bridge synced; condiment + ingredient → not on the Inventory ledger), idempotent via `components_consumed`. `PosProduct::theoreticalYield()` / `available_servings` accessor = limiting `floor(componentStock / qty)` across product, condiment **and** ingredient components. Recipe editor `PosRecipeEditor` on the product page: the component picker is a searchable combobox over **products + condiments + ingredients** (composite key `p:{id}`/`c:{id}`/`i:{id}`, `componentKey` prop) with inline New-product create; terminal tiles warn at ≤0 yield. Consumption is **strictly static**. Arch changed ⇒ `module:resync pos` (deploy runs it). Tests: `tests/Feature/PosRecipeEditorTest.php` (6), `tests/Feature/PosIngredientTest.php` (7) |
| Ingredients (raw materials) | **Added 2026-06-24.** `pos.ingredient` (`PosIngredient`, `DefinesIrModel` + translatable `name`) — a stock-tracked raw material (flour, oil, beans…) that is a recipe component + purchasable line item but is **never sold or offered at the register** (no price surcharge / category scoping, unlike a condiment). Fields: `name`, `cost_price` (drives valuation), `stock_on_hand`, `unit` (reuses `PosProduct::UNIT_OPTIONS`), `active`, `sequence` (table `pos_ingredients`, migration `2026_06_24_700011`). Engine-managed List+Form CRUD at `/app/pos/ingredient[/new|/{id}]` (`PosIngredients`/`PosIngredientForm` + `ingredients`/`ingredient-form` blades), surfaced as a Manage tile + app-bar dropdown via the manifest `models[]` entry. Admin-only (deny-default ACL, no cashier grant — same as condiments). Wired into: recipe editor/consumption/yield (above), the **Stock Report** (`StockRow` type `ingredient`, valuation = stock × cost — unlike condiments which contribute 0; adjustable via the inline Adjust action; CSV/print labels), and **Purchases** (`purchase_lines.pos_ingredient_id`, migration `2026_06_24_100005`; `PurchaseForm` picker key `i:{id}`; `PurchaseConfirmer::raiseIngredientStock()` raises on-hand). Seeded by `PosSeeder::seedIngredients()` (5 demo materials). **Inventory visibility (added 2026-06-24):** ingredient purchases + recipe consumption post **Done audit `StockMove`s** (`stock_moves.item_type` = `'ingredient'`, migration `2026_06_24_210006`) so the Inventory dashboard/Transfers show raw-material movement — a receipt (Vendor→Stock) on `PurchaseConfirmer` confirm, a consumption (Stock→Inventory) on `PosOrder::consumeComponents`, both via `PosInventoryBridge::recordIngredient{Receipt,Consumption}()`. These are recorded directly as Done and are **never `process()`ed**, so they never touch the product-keyed `stock_quants` ledger (no id collision; ingredient on-hand stays authoritative in `pos_ingredients`). Tests: `tests/Feature/PosIngredientInventoryTest.php` (2). |
| Terminal | `Modules\Pos\Livewire\PosTerminal` — live cart (cart = a draft `PosOrder`), product grid + category filter + barcode search, customer search, split payments with change due, receipt. Draft order is stamped with the creating cashier (`user_id` = `Auth::id()` in `resolveDraftOrder`). Header shows the **current** cashier chip. `wire:poll.30s="heartbeat"` keeps the presence row fresh |
| Sessions | **Single GLOBAL register (singleton — replaced the earlier one-session-per-user model on 2026-05-19).** `Modules\Pos\Services\PosSessionManager` is the only entry point: `getActiveSession()` (the one open session or null), `openOrResume()` (locked get-or-create — any authorised user joins the existing session, never a 2nd), `heartbeat()`/`activeParticipants()`. `PosHome` shows **Open** (closed) or **Resume selling** (open, any user) + live cashier list. `PosSessionPage` has **no per-user ownership gate** (any `pos.session` Read may view/manage); **closing is manager-only** (`User::isAdmin()` + `pos.session` Write). Presence panels (`wire:poll.30s`) on home & session page; orders table shows per-order cashier. `PosSession::openForUser`/`isAccessibleBy` and the `$others`/`session-card` UI were **removed** |
| Routes | `/app/pos`, `/app/pos/order`, `/app/pos/product`, `/app/pos/category`, `/app/pos/session`, `/app/pos/session/{id}`, `/app/pos/session/{id}/terminal`, `/app/pos/product/{id\|new}`, `/app/pos/category/{id\|new}` (all `auth`); index routes back the sidebar's `pos.order/product/category/session` model entries |
| Seed | `PosSeeder` (8 products, **3 payment methods — Benefit · Card · Cash in that order** via `sequence` 10/20/30; Benefit = Bahrain's BENEFIT debit network, an ordinary `is_cash=false` method with no special handling, added by data migration `2026_06_22_700008` (idempotent, auto-applied to Main + tenants on deploy) + the seeder's `firstOrCreate`), `pos_user` group + ACLs; no-ops pre-install). Cashier policy: `pos_user` = **orders only** — full operate on `pos.session`/`pos.order` (no `unlink`), **no `pos.product` ACL at all**. `PosStaffSeeder` re-asserts this (idempotent) and deletes any stale `pos.product` grant |

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
  `tests/Feature/PosAddCustomerTest.php` (26). **⚠️ REMOVED 2026-06-08** — the whole
  picker + inline-Partner CRUD was ripped out and replaced by a phone-entry box (the
  terminal no longer saves customers); see the "No saved customers" note in the
  per-phone customer-discount increment below. `PosAddCustomerTest.php` was deleted.
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

- **Product unit of measure (shipped 2026-06-15)** — `pos_products.unit`
  (string(16), migration `2026_06_15_700001`, default `qty` which backfills
  existing rows; **plain string, NOT an enum cast** — dodges the engine
  FormView empty-option pitfall `[[livewire-backed-enum-in-array-prop]]`).
  Codes: `qty|pcs|kg|g|l|ml|box|pack|dozen` (labels in
  `PosProduct::UNIT_OPTIONS`). Surfaced as a **Unit `select` right beside
  "Stock on hand"** on the product form (engine static-options select; the
  engine prepends an empty `—` option but the column default + model
  `$attributes['unit']='qty'` keep new products on `qty`). Also a
  `hidden_by_default` list column (paired with the Stock column). Arch
  changed ⇒ `module:resync pos` (deploy runs it automatically). AR key `Unit`
  added; the option labels stay English (international units). Tests:
  `PosModuleTest::test_product_form_has_unit_field_defaulting_to_qty_and_saves_it`
  + the hidden-by-default set assertion updated (`+unit`).

- **Stock Report + 6 follow-ups (shipped 2026-06-15)** — `Modules\Pos\Livewire\PosStockReport`
  at `/app/pos/stock-report` (`pos.stock_report`, `pos.product` Read-gated):
  an Odoo-style report bucketing every product into **In stock** (`stock_on_hand
  > 0`), **Low stock** (`0 < stock ≤ reorder point`), and **Out of stock** (`≤ 0`).
  Summary chips double as the status filter; search by name/barcode; rows sorted
  out → low → in (lowest qty first); paginated. All query/summary/status logic
  lives in one shared `Modules\Pos\Services\PosStockReportData` (used by the
  screen + export + print so they can't drift). The Inventory "Products in stock"
  KPI card links here. Six increments shipped together:
  1. **Restock action** — inline **"Adjust"** per row opens a modal to set
     on-hand (`openAdjust`/`saveAdjust`, Write-gated); out-of-stock rows also get
     a **"Buy"** link to a new purchase (when Purchases installed).
  2. **Export / print** — `PosStockReportExportController` (CSV, `pos.stock_report.export`)
     + `PosStockReportPrintController` (`pos::stock-report-print`, auto-print,
     `pos.stock_report.print`); both honour the current filter/search/inactive
     scope via query params.
  3. **Valuation** — per-row on-hand value (`PosProduct::stockValue()` = stock ×
     cost) + a total **Inventory value** (`summary['value']` = `SUM(stock × cost)`).
  4. **Per-product reorder point** — `pos_products.reorder_point` (nullable,
     migration `2026_06_15_700002`); `PosProduct::stockStatus($global)` uses it,
     falling back to `DailyReport::LOW_STOCK_THRESHOLD` (10). SQL bucketing uses
     `COALESCE(reorder_point, ?)`. Added to the product form (blank = global).
  5. **Inactive toggle** — `includeInactive` (default false = active only, which
     keeps the report's "In stock" count equal to the **active-only** Inventory KPI).
  6. **Real-time POS → Inventory sync** — `Modules\Pos\Services\PosInventoryBridge::sync()`
     mirrors a product's on-hand onto the Inventory ledger's quant at the main
     internal **Stock** location + records a Done adjustment `StockMove` for the
     delta (counterpart = an `Inventory`-type location). Fully guarded by
     `Schema::hasTable()` (no hard Inventory dependency; silent no-op when absent
     or topology unseeded). Hooked two ways: `PosProduct::saved` in
     `PosServiceProvider::boot()` (stock edits, incl. the Adjust modal), and
     **explicitly inside `PosOrder::consumeComponents()`** because `decrement()`
     bypasses model events — so a sale's ingredient consumption shows as a stock
     movement on the Inventory dashboard. NOTE: finished goods without a recipe
     still don't decrement their own stock on sale (existing design) — the sync
     tracks ingredient consumption + manual stock changes.
  Tests: `tests/Feature/PosStockReportTest.php` (7 — buckets, out filter, Read
  gate, valuation, reorder point, inactive toggle, adjust) +
  `tests/Feature/PosInventorySyncTest.php` (4 — bridge mirror+move, no-op on
  unchanged, saved-hook edit, sale consumption sync). AR keys added.

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
  `pos_products/` was protected). **This is NOT only about this controller's
  whitelist**: any `->store('<dir>', 'public')` anywhere creates a bucket with the
  same requirement. It bit again on 2026-08-24 — `PosTerminal` stores proof-of-payment
  photos under `storage/app/public/pos/payment-proofs/`, a NESTED path that none of the
  flat top-level excludes covered, so every deploy wiped them. Fixed by excluding the
  whole `storage/app/public/pos/` subtree. When adding an upload of any kind, grep
  `->store(` and cross-check the exclude list. Returns
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
  muted, the **NEW (Pending) column glows** with a looping warm-amber pulse
  via `.kds-new-glow` (CSS keyframes, `animation … infinite`). The glow is
  **server-driven**: the Blade adds `kds-new-glow` to the Pending column
  whenever `$columnTickets->count() > 0`, so it pulses continuously while an
  un-accepted order sits there and stops the instant the column empties (the
  cook taps "Start preparing"). No JS timer — only the audio `ping()` is
  JS-driven (on genuine new arrivals). (Superseded the timed 3-pulse
  `glowNewColumn()` on 2026-06-08, itself superseding the whole-wrapper
  `flashHeader()` / `.kds-new-flash` ring.) Still requires the user to tap
  "Tap to enable sound" once per tab session (browser autoplay rule).
- **Condiments / add-ons (shipped 2026-06-08)** — a cashier attaches add-ons
  (extra cheese, no ice…) to any cart line at the register. **One global
  priced list** (`pos.condiment`, `pos_condiments` table — translatable
  `name`, `price` decimal where 0 = free, `active`, `sequence`; managed via
  the engine list/form at `/app/pos/condiment`, admin-level like `pos.product`
  — cashiers operate the picker but don't manage the catalogue). Selection is
  stored as a **price/name snapshot** on the line via
  `pos_order_lines.condiments` JSON (`'array'` cast; migration
  `2026_06_08_300002`) — a snapshot so a later catalogue edit can't rewrite a
  finalised order. `PosOrderLine::condimentsSurcharge()` sums the attached
  prices and `recompute()` treats it as a **per-unit** surcharge
  (`qty × (unit_price + surcharge)`), so 2 burgers with cheese pay cheese
  twice. Terminal: each cart line has an **"add-ons"** button →
  `PosTerminal::openCondiments($lineId)` opens a picker overlay; tapping a row
  `toggleCondiment($id)` adds/removes the snapshot and recomputes line + order
  live. `addProduct` merges only into a PLAIN line (no discount AND no
  condiments) so a condiment'd line is never silently bumped. Condiments
  render on the cart line, the **KDS ticket** (`+ name, name` in primary
  colour), the on-screen receipt, and the WhatsApp PNG receipt
  (`PosReceiptImageRenderer` emits a `condiments` string list per line).
  `PosCondiment` added to the manifest `models[]` (needs `module:resync pos`).
  Demo set seeded by `PosSeeder::seedCondiments()` (Extra cheese 0.50, Extra
  sauce 0.30, Ice cubes free, No ice free, Extra shot 0.40 — idempotent,
  independent of the products guard; seeded category-less = global). Test:
  `tests/Feature/PosCondimentTest.php` (4 — per-unit surcharge, live toggle
  on/off, no-merge-into-condiment'd-line, category-scoped picker).
  - **Category scoping + Active removed from the UI (shipped 2026-06-10)** —
    a condiment can be scoped to a product **category** so the register only
    offers relevant add-ons. New nullable+indexed logical ref
    `pos_condiments.pos_category_id` (migration `2026_06_10_200001`; null =
    **global**, shown for every product). `PosCondiment` gained a `category()`
    belongsTo + `category_name` accessor; the engine **form** swaps the Active
    checkbox for a category `optionsFrom` select (empty = all products) and the
    **list** swaps the Active toggle column for a Category column. The `active`
    **column stays** (defaults true, terminal/seeder still query
    `where('active', true)`) — condiments are simply always active now; it's
    just no longer user-editable. Picker filter:
    `PosTerminal::condimentOptions()` returns condiments whose
    `pos_category_id` = the edited line's product category **OR** is null
    (global), so ringing a burger surfaces burger add-ons + universal ones, not
    drink add-ons. Empty state reworded to "No add-ons for this item." Arch
    changed ⇒ `module:resync pos` (deploy runs it); migration auto-applied by
    deploy.yml's POS migrate step. AR keys: Category + the two help strings +
    empty-state.
  - **Per-product condiment assignment (shipped 2026-06-23)** — on top of the
    category/global scoping, a condiment can be assigned to a **specific
    product**. Many-to-many pivot `pos_condiment_product`
    (migration `2026_06_23_700009`, FK cascade both sides, unique
    `pos_cond_prod_unique`) + `PosProduct::condiments()` BelongsToMany. Managed
    by a new Livewire editor `Modules\Pos\Livewire\PosProductCondiments`
    (`pos::product-condiments`) embedded on the **product page** beneath the
    recipe editor (`product-form.blade.php`) — a checklist of every active
    condiment; `toggle($id)` attaches/detaches (Write-gated `pos.product`, so
    admin-only — cashiers have no product access). **As of 2026-06-23 the
    register picker is driven EXCLUSIVELY by this assignment** —
    `PosTerminal::condimentOptions()` returns ONLY the product's assigned
    condiments (`whereIn('id', $assignedIds)`); category-scoped / global
    condiments no longer auto-appear, and a product with no assignment shows an
    empty picker. (The earlier union-with-category/global behaviour was dropped
    per user request — the `pos_condiments.pos_category_id` column still exists
    but no longer scopes the terminal picker; it's now purely an organisational
    label.) No `irModelDefinition()` change ⇒ no resync; the
    new migration auto-applies via deploy.yml's POS migrate step (+ tenants via
    `workspaces:migrate`). Test: `PosCondimentTest::test_a_condiment_assigned_to_a_product_appears_in_the_register_picker`.
    AR keys: Add-ons / Condiments + the help + empty-state strings.
    **Category filter pills (2026-06-23):** the editor renders a pill row (All +
    every category, **active AND inactive** — inactive dimmed, since a condiment
    may sit under a now-inactive category) that filters the checklist by the
    condiment's `pos_category_id` (server-side `PosProductCondiments::$filterCategoryId`
    + `$set` per pill, mirroring the register's product-category filter). Test:
    `PosCondimentTest::test_condiment_editor_filters_the_checklist_by_category_pill`.
- **Camera barcode scanning in the terminal (shipped 2026-06-10)** — a
  scan icon **inside the product search bar** (Odoo-style) opens a camera
  overlay that decodes product barcodes and adds them to the cart. Frontend:
  a `barcodeScanner($wire)` Alpine component in `resources/js/app.js` that
  **lazy-`import()`s `@zxing/browser`** (ZXing — works on iOS Safari / Android
  / desktop, unlike the patchy native `BarcodeDetector`; Vite code-splits it
  into its own ~448 KB chunk loaded only on first scan, so the main bundle is
  untouched). `decodeFromConstraints({facingMode:'environment'})` drives a
  `<video>` in a `wire:ignore` overlay (so cart re-renders don't tear down the
  stream); each decode is de-duped (same code within 1.2 s ignored) and calls
  the Livewire action. Backend: `PosTerminal::scanBarcode(string $barcode)` —
  Write-gated, resolves an **active** product by `barcode`, `addProduct()`s it,
  and dispatches `scan-hit {name}` / `scan-miss {barcode}` for the overlay's
  inline feedback (+ a WebAudio beep on the client). Physical USB scanners
  still work unchanged (keyboard-wedge into the same search input). New npm
  dependency `@zxing/browser` (in `package.json`/lock → deploy's `npm install`
  + `npm run build` bundle it; `public/build` is gitignored, built fresh on
  deploy). Test: `tests/Feature/PosBarcodeScanTest.php` (5 — hit adds + flashes,
  double-scan increments one line, unknown/inactive → miss, blank no-op). AR
  keys added for the overlay strings.
- **POS Home KDS deep-link icons** ([Modules/Pos/resources/views/home.blade.php:38-58](Modules/Pos/resources/views/home.blade.php#L38-L58)) —
  Kitchen had a people-cluster glyph and Shisha had a thumbs-up — neither
  read as what the button does. Kitchen now uses Heroicons solid `fire`
  (universal cooking shorthand); Shisha uses a custom 3-curl smoke-wisp
  drawing (no Heroicon ships a hookah). Memory:
  `[[use-svg-icons-not-emoji]]`.
- **Per-phone customer discounts (shipped 2026-06-08)** — an **admin** assigns
  an *open* discount **%** to a customer **phone number**; when the cashier
  **adds that customer at the register**, the percentage comes off the whole
  order total automatically. **Admin-only catalogue** (`pos.customer_discount`,
  `pos_customer_discounts` table — `phone`, `discount_percent` decimal(5,2)
  clamped 0–100 on save, optional `label`, `active`; engine list/form at
  `/app/pos/customer_discount`). Admin-only by **deny-default ACL** — no
  `pos_user` grant exists for the model, so cashiers never see the
  list/sidebar entry (engine Read guard 403s them) while the superuser
  bypasses. **Matching** (`PosCustomerDiscount::findForPhone()`) normalises
  both sides to bare digits (leading trunk-zero dropped): exact match first,
  then suffix match (≥ 7 digits) so a stored local "33123456" still resolves a
  register phone carrying the country code "97333123456" and the human
  "+973 33123456" form. **Application** is a snapshot on the order:
  `pos_orders.customer_discount_percent` + `customer_discount_total` columns
  (migration `2026_06_08_400002`). `PosOrder::recalculate()` reduces only the
  final `total` by the percentage (subtotal/tax stay raw line sums for
  display; the discount is its own line). `PosOrder::applyCustomerDiscount(?phone)`
  resolves + snapshots the percent then recalculates; the percent persists on
  the row so adding more products keeps the discount applied.
  `PosTerminal::syncCustomerDiscount()` applies the discount from the cashier-typed
  phone (`countryCode` + `localPhone` → `compose()`), fired from `updatedLocalPhone`
  / `updatedCountryCode` / `closePhoneEntry` / `startPayment` / `clearPhone`. The
  discount line renders in the cart totals, the payment overlay summary, the
  on-screen receipt, and the WhatsApp PNG receipt (`receipt-pdf.blade.php` +
  `PosReceiptImageRenderer` pass `customerDiscount`/`customerDiscountPercent`).
  - **No saved customers (changed 2026-06-08).** The earlier Odoo-style "Choose
    Customer" picker + inline create/edit/delete-Partner flow was **removed** from
    the terminal per user request ("don't save customer phone numbers — just apply
    the discount when the cashier adds the phone number"). The **"+ Customer
    discount"** button now opens a lightweight **phone-entry modal**
    (`$enteringPhone`, `openPhoneEntry`/`closePhoneEntry`/`clearPhone`): the cashier
    types a phone, the matching discount applies live, and **nothing is persisted as
    a customer** (`pos_orders.partner_id` is left null). The **same typed number**
    still drives the WhatsApp receipt for that one sale (`order.customer_phone` set at
    `validateOrder`, as before). All the removed methods (`openCustomerPicker`,
    `pickCustomer`, `saveNewCustomer`, `saveEditedCustomer`, `deleteCustomer`,
    `clearCustomer`, `customerListQuery`, etc.) and the `newCustomer*`/`pickingCustomer`
    props are gone; `tests/Feature/PosAddCustomerTest.php` was deleted with the feature.
    The Contacts module + `Partner` model are untouched — only the POS terminal stopped
    creating/listing them.
  `PosCustomerDiscount` added to the manifest `models[]` (needs
  `module:resync pos` — the deploy workflow does this automatically). Demo row
  seeded by `PosSeeder::seedCustomerDiscounts()` (`+973 33000000` → 10%,
  idempotent). The core **dashboard "Getting started" card was removed**; in
  its place an **admin-only** "Customer Discounts" card links to the manager
  (`resources/views/livewire/pages/dashboard.blade.php`).
  - **Rolling 30-day expiry (renews on purchase).** `pos_customer_discounts.expires_at`
    (migration `2026_06_08_400003`, backfills existing rows to `created_at + WINDOW_DAYS`).
    `PosCustomerDiscount::WINDOW_DAYS = 30` (was 90 until 2026-06-08; change the
    constant to retune — the whole feature derives from it). The `saving` hook
    starts/restarts the clock (`expires_at = now + 30`) whenever the discount is **active** but has no
    live window — i.e. on create AND when an admin re-enables a lapsed one (an
    already-future expiry is left untouched, so editing the percent/label doesn't
    reset it). `findForPhone()` only returns discounts whose window is still open
    (`expires_at >= now`), so a lapsed one stops applying at the register
    immediately regardless of the `active` flag. **Renewal:** `RenewCustomerDiscount`
    listens to `PosOrderPaid` (wired in `PosServiceProvider::boot()`); when a paid
    order actually used a discount (`customer_discount_percent > 0`) it pushes that
    discount's `expires_at` to `ordered_at + 30` via `renewFrom()`. **Sweep:** a
    daily scheduled task (`expire-customer-discounts` in `routes/console.php`) calls
    `PosCustomerDiscount::deactivateLapsed()` — a mass update (bypasses the saving
    hook, so it doesn't restart the window) flipping `active = false` on every
    expired row so the admin list reflects it (cron-independent for correctness —
    the register guard above already blocks expired discounts between ticks).
    The list shows an **Expires** datetime column. Re-enabling a lapsed discount
    starts a fresh 30 days.
  Test: `tests/Feature/PosCustomerDiscountTest.php` (11 — phone normalisation /
  suffix match, inactive skipped, percent clamp, apply-on-add, persist + clear,
  admin-only ACL, 30-day window on create, findForPhone skips lapsed, paid-order
  renews window, sweep deactivates only expired, re-enable restarts window).
  - **Prepaid balance / store credit (shipped 2026-08-04).** A per-phone discount
    can carry a **`prepaid_balance`** (migration `2026_08_04_700055`, a "Prepaid
    balance" number field + a **Balance** money column on the engine list). Each
    order draws the wallet down at **FULL price** until it reaches 0; only the
    part the customer actually pays out of pocket (after the credit) gets the
    discount %. So: credit 30, order 8 → pays 0, balance → 22; once balance 0 →
    the 50% applies. A bill bigger than the balance splits: credit covers its
    amount at full price, the remainder gets the %. Order-side snapshot columns
    (migration `2026_08_04_700056`): `pos_orders.{customer_discount_id, credit_applied,
    credit_consumed}`. `PosOrder::recalculate()` = stage 1 credit (`min(available,
    gross)`, previewed against the LIVE balance while `!credit_consumed`, frozen
    after), stage 2 `%` on the goods remaining after credit. `applyCustomerDiscount()`
    snapshots the matched discount id. The wallet is decremented **once** at
    checkout via `PosOrder::consumeCustomerCredit()` (guarded by `credit_consumed`;
    calls `PosCustomerDiscount::drawCredit()` which `saveQuietly()`s so it can't
    restart the rolling window), wired into `finalizeSale()` + `confirmUnpaid()`.
    Because a fully-covered order has **total 0**, `PosTerminal::canPay()` no longer
    blocks a 0 total (only an empty cart) and `validateOrder()` uses `isPaid()` (not
    `isFullyPaid()`) + the overlay's Validate button too — so the cashier can close
    a no-cash order. Terminal shows a **"Prepaid credit −X"** line in the cart totals
    + payment overlay + on-screen receipt, and the customer panel shows the live
    **"Prepaid balance: X"**. Arch change ⇒ `module:resync pos` (deploy runs it);
    both migrations auto-apply via deploy's POS migrate step. Test:
    `tests/Feature/PosCustomerCreditTest.php` (6 — covers-whole-bill, draws-wallet-
    once + idempotent, bill-bigger-than-balance splits credit-then-%, balance-0 →
    discount applies, 0-total order closes at the register, balance never negative).
    AR keys: Prepaid credit / Prepaid balance.

**Sweileh Café afternoon happy hour (shipped 2026-07-14):**

A time-of-day deal, hardcoded to the **Sweileh Café database only**: between
**12:00 (noon) and 18:00 (6 PM) Bahrain time** (`Asia/Bahrain`, always — never
the server/app tz): **shisha** is **capped at 1.400** (`min(price, cap)` — a
1.600 shisha drops to 1.400, but one already cheaper like **Zaglol at 1.200**
keeps its price; the deal only ever lowers), **food** gets **25% off**, and
**drinks and sweets are excluded** (no discount — sweets added 2026-09-12 on the
owner's request, same mechanism as drinks). (Window hours = `START_HOUR` /
`END_HOUR` constants in `HappyHour`.)

| Concern | Location |
|---|---|
| Engine | `Modules\Pos\Services\HappyHour` — `active()` = `isSweilehCafe() && withinWindow(now())`; `withinWindow($instant)` tests the Bahrain-local hour is in `[12,18)` (isolated for unit tests); `isSweilehCafe()` reads the per-database `company.name` setting, normalises to letters only, matches `sweileh`/`swelieh` (tolerant of "Café"/"Cafe" + the spelling transposition); `isShisha($product)` = the product's category routes to the KDS **Shisha** station (`PrepStation::Shisha`); `isDrink($product)` / `isSweet($product)` = the product's category **NAME contains "drink"** / **"sweet"** (case-insensitive, checked across every locale value of the translatable name via the shared private `categoryNameContains()` — so "Drinks", "drinks", "Hot Drinks", "Arabic Sweets" all match); `priceLine($product)` returns `{unit_price, discount}` — **shisha → `min(price, SHISHA_PRICE)`/0** (a cap, never raises a cheaper one), **drink or sweet → price/0** (excluded), **food → price/`FOOD_DISCOUNT_PERCENT`**, and price/0 when the window's closed or it's another database. Constants `SHISHA_PRICE=1.4`, `FOOD_DISCOUNT_PERCENT=25.0`. **Drinks and sweets are matched by category NAME, NOT a flag** — deliberately, so nothing is added to any other database: both are only consulted while the (Sweileh-gated) window is active, so there's **no category checkbox anywhere** (the earlier `pos_categories.is_drink` checkbox was removed 2026-08-10; the DB column is left in place, unused/harmless). Renaming a category to include "Drinks"/"Sweets" opts it out of the discount; no resync needed for a data rename |
| Application | `PosTerminal::addProduct()` calls `HappyHour::priceLine()` and stamps the new line's `unit_price`+`discount` — **at ring-up**, so a line added at 12:30 keeps the deal even if the bill is settled after 18:00, and a line added at 11:50 stays full price. The merge-into-existing-line check now matches on (product, unit_price, discount, no condiments) with PHP-side rounding — so identical happy-hour taps stack onto one line, but a full-price unit added after the window closes lands on a NEW line. Bill **split** copies `unit_price`/`discount` verbatim (`PosOrderSplitter`), so the deal survives a split |
| UI | Terminal shows an amber **"Happy hour"** banner while `active()` (so the cashier knows why prices dropped), and a small **`−25%`** badge on any discounted cart line. Shisha shows as a reduced unit price; food as the existing per-line discount (already in totals + receipt); drinks and sweets unchanged |
| Scope note | It's the **database identity** (company name), NOT the café **business type** — a different café won't get it. Renaming the Sweileh Café database's company name away from "sweileh…" silently turns it off (per the user's chosen trade-off over a toggle). To retune: change the constants / window hours in `HappyHour`, or the name match in `isSweilehCafe()`. Stacks with the per-phone customer discount (line-level deal, then order-level %) |
| Tests | `tests/Feature/PosHappyHourTest.php` (12 — window bounds incl. Bahrain-vs-UTC, database gate + spelling tolerance, not-active-elsewhere, shisha cap, **shisha cheaper than the cap keeps its price (Zaglol)**, food 25%, **drinks excluded by category name**, **sweets excluded by category name**, taps stack, normal outside window, a windowed line keeps its price after close). AR keys added for the banner |

**Production lifecycle — reverse / reopen (shipped 2026-08-11):**

A completed production run is corrected through a **state machine keyed to its
reference (`PRD/…`)**, never by editing stock directly. `pos_productions.state`
(`done | draft | reversed`, migration `2026_08_11_700059`, + `reversed_at` /
`reversed_by_user_id`; existing rows backfill to `done`) via `PosProduction::{isDone,
isDraft,isReversed,isEditable,outputHasLeftStore,reverse}`. A **done** run is
**read-only** in `ProductionForm` (all inputs disabled) with two actions:

- **Reverse production** (`ProductionForm::reverse()`, done → reversed): undoes the
  run's stock effect (materials back to ingredient stock via the ledger, bottles out
  of the STORE) and **locks** it — kept in the list read-only with a red **Reversed**
  badge for the audit trail. `PosProduction::reverse($userId)` stamps `reversed_at` +
  actor; logged to `activity_logs` (`production_reversed`).
- **Reopen to edit** (`ProductionForm::reopen()`): a **done** run is reversed first
  (→ draft, stock undone) so you edit from a clean slate; a **reversed** run is simply
  unlocked (no stock change). Either way it becomes an editable **draft** (amber badge);
  **saving re-records it** (`state → done`, applies stock, logs `production_reopened`).
  `save()` **aborts 403 on a done/reversed run** — you can't edit one in place — and
  no longer reverses-then-reapplies (a draft's stock was already returned on reopen, so
  the old `priorMl`/`priorPack` add-back is gone; short-stock is checked against live
  `availableMl()`).
- **Block until returned:** reverse / reopen / delete of a **done** run is refused when
  `outputHasLeftStore()` (`store_stock < produced_units` — bottles moved to the shop or
  sold), with an inline `state` error telling the user to pull them back from the shop
  first. So a reversal can never drive STORE stock negative.
- **Admin force override (shipped 2026-08-17):** when a run's output can NEVER be fully
  returned (bottles sold, or an over-entered `produced_units` that exceeds the bottles
  that physically exist), the lock is unsatisfiable and the run is stuck forever.
  `ProductionForm::{reverse,reopen,delete}` now take a `bool $force`; `mayForce($force)`
  = `$force && Auth::user()->isAdmin()` bypasses `outputHasLeftStore()`. Safe because
  `reverseStock()` floors `store_stock` at `max(0, …)`. The blocked-run panel shows an
  admin-only red **"Reverse anyway" / "Delete anyway"** box (`$outputLeft && $isAdmin`),
  each behind a `wire:confirm`. Tests: `PosProductionTest::{test_an_admin_can_force_reverse_when_bottles_have_left_the_store,
  test_a_non_admin_cannot_force_reverse}`.
- `delete()` is state-aware (only reverses if still `done`, else the run's stock was
  already unwound). Tests: `PosProductionTest::{test_reopening_then_re_recording_reverses_then_reapplies_stock,
  test_a_recorded_run_cannot_be_saved_without_reopening, test_reversing_a_run_undoes_its_stock_and_locks_it,
  test_reversing_is_blocked_when_bottles_have_left_the_store}` (+ the old "editing" tests
  retargeted through reopen). AR keys + `ActivityLog` labels/colors added.

**Production & store — move provenance + store correction (shipped 2026-08-11):**

The perfumes-POS Production feature mixes raw materials into finished bottles
that land in a product's **STORE** (`pos_products.store_stock`); a **"Move to
shop"** action (`Modules\Pos\Livewire\Productions`) then transfers bottles
STORE → SHOP (`stock_on_hand`, what the register sells), logging a
`PosStockTransfer` (ref `TRF/…`, qty, direction, user, date). Two additions:

- **Move-to-shop is taggable with a production run.** `pos_stock_transfers.pos_production_id`
  (nullable **logical ref**, migration `2026_08_11_700058`, auto-applied by
  deploy's POS migrate step + tenants via `workspaces:migrate`). The "Move to
  shop" card shows a **"From production (optional)"** picker of the selected
  product's runs (ref · bottles · date); the chosen run is validated to belong
  to that product and stored on the transfer, and the **"Recent stock moves"**
  history shows its `PRD/…` reference + date. `PosStockTransfer::production()`
  belongsTo `PosProduction`. Not a `DefinesIrModel` ⇒ no resync.
- **Ingredient Adjust — "clear Used in total" (keeps the count).**
  The Stock Report ingredient Adjust posts an `Adjustment` move (excluded from
  `PosIngredient::usedTotal()`), so it raises on-hand but leaves "Used in total"
  untouched — correct for a re-count. But stock a **since-deleted production
  consumed** leaves an orphaned negative `Production` move (no run left to
  reverse), stuck in "used" forever. `PosStockReport::$adjustClearUsed` (an
  ingredient-only checkbox in the Adjust modal): when ticked, saving calls
  `PosIngredient::cancelRecordedUsage()` — which posts a `Production` **credit**
  that zeroes the consumption tally (`usedTotal` is `abs(sum)` of Production/Sale/
  Damage) **plus an equal, opposite `Adjustment`** so the **on-hand count is
  unchanged** (the typed on-hand is ignored in this mode). This is deliberately
  NOT a return-to-stock — the owner's case was "those bottles are gone, just
  remove them from the tally." Write-gated like Adjust. Tests:
  `PosStockReportTest::{test_clear_used_removes_it_from_used_total_without_changing_the_count,
  test_a_plain_ingredient_adjust_is_a_recount_and_leaves_used_untouched}`.
- **"Move back to store" (shop → store).** `Productions::moveToStore()` — the
  reverse of "Move to shop": pulls finished bottles off the register
  (`stock_on_hand`) back into the STORE (`store_stock`), so bottles that already
  left can be returned (e.g. to unblock reopening/reversing a run). Uses the
  existing `PosStockTransfer::SHOP_TO_STORE` direction (`applyMove()` already
  handled it — only the UI action + card were missing); qty is guarded against
  the shop's on-hand and it's logged as a shop→store transfer. Not admin-gated
  (mirrors "Move to shop"). Tests: `PosProductionTest::{test_moving_stock_from_shop_back_to_store,
  test_cannot_move_back_more_than_the_shop_holds}`.
- **"Remove from store" correction (admin-only).** `Productions::removeFromStore()`
  (`abort_unless(isAdmin, 403)`) takes bottles a run put in the STORE by mistake
  back OUT — **without** adding them to the shop and **without** returning
  materials (to also restore materials, delete the production run instead). New
  `PosStockTransfer::STORE_REMOVE` direction; `applyMove()` reduces `store_stock`
  only. Logged as a transfer (red "removed from store" row) so it's auditable.
  Confirm dialog + amber warning in the card. Tests:
  `PosProductionTest::{test_a_move_to_shop_can_be_tagged_with_a_production,
  test_remove_from_store_takes_bottles_out_without_touching_the_shop,
  test_remove_from_store_is_admin_only}`. AR keys added.

**Production packaging no longer wiped by the product auto-fill (fixed 2026-08-12):**
`ProductionForm::updatedProductId()` overwrote `$this->packaging` from the product's
FORMULA **unconditionally** (unlike `$this->lines`, which was guarded by
`if ($liquid->isNotEmpty())`). So picking a product whose formula was **liquid-only**
**wiped any manually-added packaging**, and re-recording then saved the run without it
(reported: "where are the packaging materials — they were there when we made a
production?"). Two guards: (a) the auto-fill is skipped entirely for an **existing**
run (`$this->id !== null` — a reopened run's materials/packaging come from the run,
never the formula); (b) packaging is only overwritten `if ($pack->isNotEmpty())`, so a
liquid-only formula never clears manual packaging on a new run either. Tests:
`PosProductionTest::{test_picking_a_product_does_not_wipe_manually_added_packaging,
test_a_reopened_run_keeps_its_packaging}`. NOTE: a run whose packaging was already lost
must have it **re-added** (reopen → add packaging → re-record) — the fix prevents
future loss, it can't restore a run recorded without packaging lines.

**Production costs follow material prices (shipped 2026-08-12):**

A perfume's/offer's **stored `cost_price` now tracks the live material cost**, so a
corrected material price (e.g. a mistyped ethanol cost) can't leave stale,
confusing figures behind. `Modules\Pos\Services\ProductionCostSync::refreshAll()`
re-derives every made-in-house `cost_price` from CURRENT prices — a **perfume**
(has a production run) from `PosProduct::productionCost()` (recomputes its latest
run at today's ingredient prices), an **offer** (has a recipe) from `recipeCost()`
(whose lines already read each component's live production cost). Idempotent
(writes only changed rows) + `saveQuietly()` (no hooks/loops). Run two ways:

- **Automatically:** `PosIngredient::saved` fires `refreshAll()` when `wasChanged('cost_price')`
  (false on a fresh insert, so only a genuine cost EDIT triggers it). So fixing the
  ethanol cost flows straight through to every perfume + offer.
- **Manually:** an admin **"Recompute costs"** button on the Production & store page
  (`Productions::recomputeCosts()`, `pos` admin, activity-logged) for a one-click
  cleanup. Both flash the count changed.

**Production cost falls back to the formula's packaging (fixed 2026-08-16):**
`productionCost()` sums the RUN's own lines — so a batch recorded with **no
packaging lines** (older runs, and any run reopening is blocked on because its
bottles left the store) counted **zero packaging**, understating each perfume's
cost (materials only, no bottle/cap/bag). Now when a Done run has no packaging
line, the cost adds the product's **standard per-bottle packaging** from its
formula (`PosProduct::formulaPackagingPerBottle()` — the packaging KIND lines ×
current material price), so packaging is counted everywhere it's defined without
editing a locked run. A run that DID record its own packaging is left exactly as
entered (no double-count). `formulaCost()` reuses the same helper. So the fix
per perfume is a **data** step the owner does on the editable product page: add
the bottle/cap/bag to the product's **formula**, then the cost includes it on
every run (old locked ones included) + the "Recompute costs" button applies it.
Tests: `PosProductionTest::{test_a_run_without_packaging_falls_back_to_the_formula_packaging,
test_a_run_with_its_own_packaging_is_not_double_counted}`.

**Cost is derived from the latest DONE run only (fixed 2026-08-16):** both
`PosProduct::productionCost()` and `ProductionCostSync::refreshAll()` filter
`where('state', PosProduction::STATE_DONE)` before picking the latest run. Before
this they used `latest('id')` regardless of state, so a perfume whose most recent
run had been **reopened (draft)** or **reversed** was priced off that undone /
mid-edit run — while the production screen still showed the real Done batch. An
**offer** built from such a perfume (e.g. SHABH OFFER) then displayed a component
cost that disagreed with the perfume's actual production ("its not the same
cost"). Now a perfume with no Done run falls back to `formulaCost()` (or its
stored price) and is left out of the recompute entirely. Test:
`PosProductionTest::test_reversing_a_run_stops_it_driving_the_cost`.

Behaviour change: the old design deliberately kept a perfume's `cost_price` as a
**stale snapshot** while `productionCost()` showed live — that mismatch WAS the
"why is my cost wrong" confusion. Now the stored value follows. Tests:
`PosProductionTest::{test_correcting_a_material_cost_re_derives_the_perfume_cost,
test_the_recompute_costs_button_re_derives_costs_and_is_admin_only}` (+ the
recompute test updated to assert the stored cost now follows). AR keys added.

**POS product secondary (gallery) images (shipped 2026-06-25):**

- A product can carry **extra photos** on top of the single primary
  `image_path` the engine form owns — stored as a JSON path list in
  `pos_products.gallery_images` (`'array'` cast; migration
  `2026_06_24_700016`, auto-applied by deploy's POS migrate step + tenants
  via `workspaces:migrate`). **NOT an `irModelDefinition()` arch field** ⇒ no
  `module:resync`. `PosProduct::galleryImages(): list<string>` returns the
  cleaned, ordered list.
- **Editor**: `Modules\Pos\Livewire\PosProductGallery` (`pos::product-gallery`)
  — embedded on the product page **beneath the engine form + recipe + condiment
  editors** (`product-form.blade.php`), **always shown** (no Business-Type gate
  — harmless when a database has no store). A thumbnail grid with a per-image
  **remove** (×), plus an uploader that reuses the existing
  `FormImageUploadController` + `pos_products` bucket (already whitelisted +
  deploy-excluded) — the Alpine block POSTs the file straight to the controller
  then calls `$wire.addImage(path)` (de-dupes; `removeImage(index)` drops the
  DB entry, file stays on disk). Admin-only in practice: `pos.product` Write
  gates mutations, cashiers have no `pos.product` access.
- **WooCommerce**: `gallery_images` added to `WooCommerceServiceProvider::SYNCED_FIELDS`
  (a gallery change re-pushes) and `WooCommerceService` emits `images[]` as the
  primary **first then every secondary path** — so a store listing shows the
  whole gallery, primary as featured. Tests:
  `tests/Feature/PosProductGalleryTest.php` (3 — add/remove + de-dupe, blank
  ignored, non-admin forbidden) + `WooCommerceModuleTest::test_secondary_gallery_images_push_after_the_primary`.
  AR keys: Gallery images / the help string (keeps "WooCommerce" English) /
  empty-state.

**Automated daily report (dashboard + emailed PDF, shipped 2026-06-09):**

| Concern | Location |
|---|---|
| Recipients | `report_recipients` table (`email` unique, `active`; core migration `2026_06_09_100001`) + `App\Models\ReportRecipient` (`activeEmails()` helper). Managed **admin-only** from **Settings → Daily Report** tab (moved out of the Dashboard 2026-06-10 — see below) |
| Report engine | `Modules\Pos\Services\DailyReport` — business day is **noon → 6 AM** (`OPEN_HOUR=12`, `CLOSE_HOUR=6`). `lastClosedWindow()` = yesterday 12:00 → today 06:00 (the night that just closed); `currentWindow()` drives the live dashboard card (open-now → up to now; closed → last night). Window bounds computed in the **company timezone** then converted to the app/DB timezone for the `ordered_at` query (timestamps store in `config('app.timezone')`). `sales()` = Done-only revenue/orders/AOV/tax/discount + payments-by-method + top-5 products; `stock()` = every active product's `stock_on_hand`, **out-of-stock then low (≤ `LOW_STOCK_THRESHOLD`=10) first**, each flagged. `LOW`/`OUT` highlighting |
| PDF + email | `pos::daily-report-pdf` (professional inline-CSS A4, DomPDF — pure PHP, no Imagick) rendered by `DailyReport::renderPdf(['data'=>...])`; `Modules\Pos\Mail\DailyReportMail` attaches the PDF (`Attachment::fromData`) + `pos::daily-report-email` body. `sendForWindow()`/`sendLastClosedReport()` mail to `ReportRecipient::activeEmails()` (no-op + return 0 when none) |
| Dashboard | `App\Livewire\Pages\Dashboard` (core) — **admin-only** "Daily sale" + "Daily stock report" cards (live `currentWindow()` figures). Card data only computed when `Schema::hasTable('pos_orders')` (guarded core→POS coupling). `resources/views/livewire/pages/dashboard.blade.php`. **Blade uses FQN `\App\Erp\Money\Currencies::format(...)`** — a `@php use ... @endphp` inside the `@if` compiles to an illegal mid-block `use`. **The email-list recipient manager moved to Settings on 2026-06-10** (below) — the dashboard now only shows the two read-only KPI cards |
| Recipient manager (moved 2026-06-10) | `App\Livewire\Pages\SettingsPage` (core) — the `addRecipient`/`removeRecipient`/`sendNow` actions + `newRecipientEmail` prop (`#[Validate('required\|email\|max:200')]`) now live here, surfaced as an **admin-only "Daily Report" tab** (`$reportTab = isAdmin && posReady()`; ASCII Alpine tab key `__reports` so the localised label can't break tab state; General stays the default tab). Actions still `guardAdmin()` → 403 and fire immediately (independent of the page's top Save, which only persists `ir_config_parameter`). `resources/views/livewire/pages/settings.blade.php`. AR key `Daily Report` added |
| Schedule | `routes/console.php` — `Schedule::call(... DailyReport::sendLastClosedReport())->dailyAt('06:10')->timezone($companyTz)->name('daily-pos-report')`. Company tz read once at build time (guarded by `Schema::hasTable('ir_config_parameter')`); closure re-guards POS + recipients tables and try/catches the send so a mail failure never breaks `schedule:run`. **Depends on the same hPanel `schedule:run` cron** as the WhatsApp queue + discount sweep — no cron ⇒ no 6 AM email (`[[hostinger-cron-needed-for-queue-worker]]`) |
| Mail transport | Sending needs prod SMTP configured in `.env` (`[[prod-mail-transport-environment-specific]]` — Hostinger SMTP). Without it the 6 AM send + "Send now" silently fail (logged) |
| Tests | `tests/Feature/PosDailyReportTest.php` (9 — window math via `Carbon::setTestNow`, Done-only-in-window sales, stock low/out flags + ordering, PDF emailed to active recipients only, no-recipients no-op, **Settings** add/remove + invalid-email reject + non-admin 403 + send-now — these 4 retarget `SettingsPage` after the 2026-06-10 move) |

`report_recipients` is a **core** migration, so the deploy workflow's core `migrate --force` applies it automatically. The 6 AM email needs prod SMTP set **and** the minute cron running.

**Phase 7 OUT-of-scope adjustment:** "restaurant floors/tables/kitchen" — the
*Kitchen Display* slice ships (Phase 15) AND **restaurant floors/tables now ship
too** (table-first ordering, 2026-06-10 — see below), AND the **free-position
floor-plan editor** ships too (drag-to-arrange, 2026-06-22 — see below). What's
still out of the restaurant slice: table merge/transfer, and course/firing.

**Restaurant floors & tables — table-first ordering (shipped 2026-06-10):**

| Concern | Location |
|---|---|
| Schema | `pos_floors` (name/sequence/active) + `pos_tables` (`pos_floor_id` logical ref, name, `seats`, `shape` string `square\|round`, sequence, active) + `pos_orders.{pos_table_id nullable, guest_count nullable}` (migrations `2026_06_10_500001/2/3`) |
| Models | `Modules\Pos\Models\{PosFloor,PosTable}` (both `DefinesIrModel` → engine list/form + app-home tiles; **admin-managed, deny-default ACL like products** — no `pos_user` grant). `PosOrder` gained `pos_table_id`/`guest_count` + `table()` relation. `shape` is a **plain string, not an enum cast** (dodges the engine FormView enum-empty-string pitfall — see `[[livewire-backed-enum-in-array-prop]]`). **`PosFloor.name` is translatable** (Spatie `HasTranslations` + `TranslatableModel`; migration `2026_06_22_700003` wraps to `{"en":…}` JSON; form arch field `translatable: true` → EN/AR pills, like product/category names; needs `module:resync pos` which deploy runs) |
| Floor plan | `Modules\Pos\Livewire\PosFloorPlan` (`/app/pos/session/{id}/floor`) — floor tabs + a **free-position canvas** (each table at its own **pixel** position `pos_x`/`pos_y` from the canvas top-left, so the layout mirrors the real room): table cards (number + order badge, **coloured by KITCHEN status** via `kitchenStatus($order)` — the least-progressed routed line wins, mirroring the KDS ticket rollup: **red** (`bg-red-500`) = sent/pending (cook hasn't started), **yellow/amber** (`bg-amber-400`) = preparing, **green/emerald** (`bg-emerald-500`) = ready OR the order has a kitchen-less item (awaiting payment), **white** = free (**no draft, OR a draft with no line items** — an empty draft reads white so a paid table visibly clears; fixed 2026-06-23). The old `guests/seats` pill + the `ATTENTION_MINUTES`=20 "needs-attention" timer were **removed 2026-06-23** when guest counts were dropped — see the Postpaid-flow row below. A colour **legend** sits under the floor tabs; the wrapper carries `wire:poll.15s` (paused while arranging via `{{ $editing ? '' : 'wire:poll.15s' }}`) so colours advance live red→yellow→green as the kitchen works. Gated on `pos.order` Read (cashiers have it); reads PosFloor/PosTable directly (no per-model ACL). **`dir="ltr"` on the canvas** — the plan is a physical room, never mirrored under RTL. See the editor row below |
| Floor-plan editor (shipped 2026-06-22) | Admins (`pos.table` **Write**) get an **"Arrange tables"** toggle, then **click-to-place** (NOT drag — the pointer-drag UX was replaced after it proved fiddly): click a table to pick it up (`PosFloorPlan::selectTable` → `selectedId`; click again or Esc → `clearSelection`), then click the **circle in an empty square** (`placeAt(col, row)`) to seat it. The canvas renders a small circle at the centre of every **unoccupied** grid cell (occupied set derived in `render()` so two tables can't share a cell); circles brighten while a table is held. **Rendered as a REAL CSS grid** (`display:grid; grid-template-columns: repeat(GRID_COLS, CELLpx); grid-auto-rows: CELLpx`) of `GRID_COLS`=12 × `GRID_ROWS`=5 fixed cells (the bottom 3 rows were dropped 2026-06-23 per user request — was 8), NOT absolutely-positioned cards. `render()` builds a `$cells` map (`"col-row" => card`): each placed table's stored pixels snap to a cell (clamped to the grid), and the Blade double-loop emits one cell per grid slot — occupied → the table card, empty (edit mode) → a placement circle. **Because a table IS a grid cell it can never overflow the canvas, land between cells, or misalign** — this replaced the fragile absolute-positioning that let a table render outside the canvas. `placeAt(col,row)` still stores `col*96+6 / row*96+6` via `moveTable` (Write-gated, clamps `0..MAX_POS`, floor-scoped) — the stored pixels are just snapped back to a cell on render. **FIXED canvas (does NOT auto-grow)** so the plan never reflows / scroll-jumps. A genuine cell collision (only from old overlapping data) falls to the next free cell so no table is ever lost; distinct placements never collide (occupied cells hide their circle), so a neighbour is never shuffled. Dividers are an absolute overlay on the `relative` grid container (lines `pointer-events-none z-10`, clickable gutters `z-20`). **Double-click a placed table → `unplaceTable(id)`** (nulls `pos_x`/`pos_y`, returns it to the tray); single vs double click is disambiguated by a 220ms Alpine timer so a dbl-click doesn't also fire `selectTable`. **Canvas grows to fit** the furthest table (`width/height = max(MIN, maxX/Y + TABLE + PAD)`) in an `overflow-auto` `max-h-[72vh]` viewport; `cols`/`rows` (`ceil(size/CELL)`) drive circles + divider boundaries + bg grid. Tables with **null `pos_x`/`pos_y`** (newly added — coords NOT in the form arch, only set here) render in an **"unplaced" tray** below (tappable to sell; pickable to place). Migrations: `700004` added `pos_x`/`pos_y`; `700006` rescaled cell-index→pixels (×96); `700007` snapped existing tables to the grid. Shared inner-card partial `pos::partials.table-card-inner` |
| Floor-plan dividers (shipped 2026-06-22) | Full-span "walls" to carve a floor into zones. In edit mode the canvas renders a **clickable thin gutter** in every channel between columns (`1..COLS-1`) and rows (`1..rows-1`); clicking calls `PosFloorPlan::toggleLine('v'|'h', position)` (Write-gated, floor-scoped, validates orientation+position) which adds a `pos_floor_lines` row, or removes it if already there. Gutters sit in the 12px gap so they never overlap a table; tables are `z-30`, gutters `z-20`, the solid divider lines `z-10` + `pointer-events-none`. Dividers render in **both** sell + edit mode (chrome-400 line; primary-500 in the editor). Model `Modules\Pos\Models\PosFloorLine` (`pos_floor_lines`: `pos_floor_id`, `orientation` v\|h, `position`, `unique(pos_floor_id,orientation,position)` = `pos_floor_line_unique`) — **not** a `DefinesIrModel` (canvas-only, no resync). Migration `2026_06_22_700005` (POS, deploy auto-applies) |
| Terminal binding | `PosTerminal::mount(int $session, ?int $table = null)` — `tableId` scopes `resolveDraftOrder` to (session, table) so **every table keeps its own running draft**; null = the walk-in/quick-sale lane (`whereNull('pos_table_id')`). Header shows the table + floor + a "← Floor" link. The **guest stepper / `setGuests()` was removed 2026-06-23** (no party-size concept — see Postpaid-flow row). Routes: `/app/pos/session/{s}/table/{t}` (bound) + the existing `/terminal` (walk-in) |
| **Postpaid dine-in flow (shipped 2026-06-23; gated behind the `Postpaid` toggle 2026-06-24 — DEFAULT OFF / prepaid, see the per-app feature-toggle section)** | The POS flipped from **prepaid → postpaid**: an item reaches the kitchen the moment it's added, payment closes the table later. (1) **Auto-send to kitchen** — `PosTerminal::addProduct()` calls `app(KitchenRouter::class)->route($order)` after every add, so a kitchen-routed line is stamped `prep_status=pending` immediately (table turns red), no payment first. `Modules\Pos\Services\KitchenRouter::route(PosOrder): int` is now the **single source of truth** for "send to kitchen" (the station-lookup logic moved here out of the listener); `QueueLinesForKitchen` (still on `PosOrderPaid`) now just delegates to it as an idempotent **safety net** for orders finalised without passing through `addProduct`. (2) **Guest counts removed** — `setGuests()`, the terminal stepper, the floor card `guests/seats` pill, and the `guests`/`seats` keys in the card array are all gone (the `pos_orders.guest_count` column is left in place, unused/harmless). Splitting a bill no longer involves party size. (3) **Pay = close** — the existing terminal payment flow (`startPayment`→`addPayment`→`validateOrder`→`finalizeSale`) still finalises (stock consume + accounting + receipt) and frees the table (draft → Done → off the floor plan). The receipt overlay has an **X close** button (`newOrder` — dismiss + fresh order in place) and its **"New order"** button calls `finishToFloor()`: a **table** terminal redirects to the floor plan (pick the next table — never silently reopens the same one), a **walk-in** starts a fresh order in place. (4) **"Pay now" is gated on GREEN for dine-in** — the cart's pay button is renamed **"Pay now"** and `startPayment()` is refused (server-side + button `@disabled`) while a **table-bound** order is red (pending) or yellow (preparing); it unlocks only when the order is green via `PosTerminal::orderKitchenReady()` (no line pending/preparing — mirrors the floor plan's `kitchenStatus()` ready bucket). A muted "Pay now unlocks when the kitchen marks this order ready." hint (`$kitchenBusy`) shows under the locked button. **Walk-in / quick sale (no table) is UNGATED** — counter service pays immediately even with kitchen items pending (`canPay()` skips the kitchen check when `tableId === null`). Tests: `PosFloorTableTest` (`test_adding_a_kitchen_item_auto_sends_it_to_the_kitchen`, `test_floor_plan_colours_a_table_by_its_kitchen_status`, `test_dine_in_payment_is_gated_until_the_kitchen_is_ready`, `test_walk_in_payment_is_not_gated_by_the_kitchen`) |
| Entry flow | `PosHome::sellUrl()` — Open/Resume lands on the **floor plan when any active table exists**, else straight to the walk-in terminal (shops with no tables keep the original one-tap flow; an empty floor plan also offers "Sell without a table"). **Additive — never blocks selling** |
| CRUD | `PosFloors`/`PosFloorForm` (`/app/pos/floor[...]`) + `PosTables`/`PosTableForm` (`/app/pos/table[...]`) — thin engine list/form wrappers. Manifest `models[]` += `PosFloor`,`PosTable` (needs `module:resync pos` — deploy runs it; the 3 migrations are applied by deploy's POS migrate step) |
| Seed | `PosSeeder::seedFloorsAndTables()` — demo Main floor (1–8) + Patio (9, 10, round 11), idempotent. **NOT in the deploy chain** (only `SettingSeeder`/`PosStaffSeeder` run on deploy). For prod, the default **floors** ship instead as a **data migration** `2026_06_10_500004_seed_default_pos_floors.php` — seeds **Patio / Ground floor / First floor** (no tables), per-name idempotent (inserts each only if a floor of that name is missing, so it never duplicates or clobbers a renamed floor; `down()` deletes those three). Runs via the deploy's POS `migrate --path` step because `PosSeeder` isn't in the deploy chain — so the table form's Floor picker is never empty on a fresh install. Tables are still admin-added via **POS → POS Tables** |
| Tests | `tests/Feature/PosFloorTableTest.php` (22 — open→floor-when-tables / →terminal-when-none, table binds order, per-table separate orders + walk-in, unknown table 404, floor lists + marks occupied green, **auto-send-to-kitchen on add**, **floor-plan colours by kitchen status (red→yellow→green)**, **arrange saves position**, **moveTable clamps to grid**, **moveTable Write-gated**, **toggleLine add/remove**, **toggleLine ignores bad input**, **toggleLine Write-gated**, floor name translatable). `PosModuleTest` registered-models list updated (+ pos.floor/pos.table) |

**Split order — move items off a bill into a new order (shipped 2026-06-12):**

The SierraPOS / Odoo "split the bill" gesture: select a quantity of lines on an
existing order and move them to a **new** order (optionally on a different table).
Available from **two entry points** sharing one modal + one service.

| Concern | Location |
|---|---|
| Domain service | `Modules\Pos\Services\PosOrderSplitter::split($source, $quantities, $destTableId, $notes)` — the **sole** entry point. `$quantities` = `lineId => unitsToMove`. Atomic (one session-locked `DB::transaction`; a validation throw moves nothing). Per line: a partial move shrinks the source line + creates a destination line; a whole move re-creates on the destination and deletes the source. Product/price/discount/tax/**condiments**/KDS prep-state all carry over. Guards: source must be Draft or Done; ≥1 unit moves AND ≥1 unit stays (can't empty the original ⇒ a single-unit order isn't splittable); a draft can't split back onto its own table |
| Draft path | Moved lines land on the destination table's **open draft** (merged if one exists — preserves one-draft-per-table), else a fresh draft. No money involved |
| Paid (Done) path | Spawns a **mirror Done order**. **Never re-fires `PosOrderPaid`** ⇒ stock is NOT re-consumed (new order inherits `components_consumed = true`), no second journal entry / KDS ticket / WhatsApp receipt. Combined revenue+tax+stock across the two orders is identical to the original; only **payment records** (greedy exact split, method breakdown preserved, change stays with the source) and the per-phone discount % are re-apportioned so each order balances alone |
| Exception | `Modules\Pos\Exceptions\PosOrderSplitException` — friendly message rendered inline in the modal |
| Schema | `pos_orders.notes` (nullable text; migration `2026_06_12_600001`, auto-applied by deploy's POS migrate step) — the modal's "Notes (Optional)" lands here on the new order. Added to `PosOrder` fillable + `@property` |
| Toggle | **`Feature::SplitOrder`** (POS → Settings, shipped 2026-08-18). ON in the Café/Retail/RetailCraft presets (so existing shops are unchanged) and overridable per database. OFF hides the split action in **both** entry points (`PosOrders`' `splittable` row flag + the terminal cart's Split button) and refuses it server-side: `PosOrders::openSplit` / `PosTerminal::openSplit` / `SplitOrderModal::submit` `abort 404`, and `SplitOrderModal::openFor` simply won't open (so a stale page can't act). Test: `PosOrderSplitTest::test_turning_split_order_off_hides_and_refuses_it` |
| Shared modal | `Modules\Pos\Livewire\SplitOrderModal` + `pos::split-order-modal` — item table (checkbox + qty stepper), destination-table select, notes, **live** split summary (new vs remaining items + totals). Opened by dispatching `open-split-order` (orderId); on success dispatches `order-split` so the host re-renders. Registered as a Livewire alias in `PosServiceProvider::boot()`, but **embedded via `@livewire(\Modules\Pos\Livewire\SplitOrderModal::class)`** (FQCN, not the alias) on the terminal + orders views so it resolves in the test harness too (module `boot()` doesn't run after an in-test install — the same gap PurchaseConfirmTest works around) |
| Entry 1 — terminal | A **"Split"** button in the cart header (shown when the cart has ≥2 units) opens the modal for the current order |
| Entry 2 — Orders list | `PosOrders` was **rewritten** from the metadata engine-list wrapper into a **custom actionable list** (SierraPOS-style): search by reference, status filter, pagination, and per-row **split / cancel / print** icons. The Kanban tab still embeds the engine `kanban-view`. Cancel voids a **draft** only (sets `Cancelled`); print → the receipt route below; split → the shared modal. `/app/pos/order` (sidebar tile) now lands here |
| Receipt print | `Modules\Pos\Http\Controllers\PosReceiptPrintController` (GET `/app/pos/order/{id}/receipt`, `pos.order.receipt`, Read-gated) renders `pos::receipt-print` — a browser-printable slip reusing `PosReceiptImageRenderer::receiptViewData()` (made **public**) so it matches the WhatsApp PNG. Auto-opens the print dialog |
| Tests | `tests/Feature/PosOrderSplitTest.php` (9 — draft move, partial-qty shrink, merge-into-existing-table-draft, can't-empty-original, empty-selection rejected, **paid split keeps stock + reapportions payment + combined cash unchanged**, modal create+dispatch, orders-list render+cancel, receipt-print renders) |

**Deliberately OUT of scope** (say so if asked, offer as follow-ups): offline/PWA &
hardware/IoT (cash drawer, customer display), table merge-transfer (order **split**
and the **free-position floor-plan editor** now ship; table merge/transfer don't),
loyalty/gift cards/coupons, multi-currency *per-order* (the global default currency from
Phase 11 IS now applied), advanced tax (price-included, multi-tax, fiscal positions),
refunds/returns, and accounting/invoice posting. Known simplification: cash
reconciliation sums **payment amounts**; change given is computed (`change_due`) but
not posted as a drawer cash-out, so tendering over total slightly overstates expected
cash — use exact tender or treat `change_due` as informational.

**Limousine "Live entry data" export — a safety backup before the legacy-import cutover
(shipped 2026-09-22).** The owner plans to delete the Limousine app's pre-15-Sep-2026
history and re-import the old system's data fresh. Before any deletion, each of the
4 bespoke Limousine list screens (Bookings, Receipts, Quotations, Invoices) gained a
**"Live entry data"** button next to Print that exports (CSV) only the rows genuinely
entered LIVE through this ERP — never the ones the one-time historical bulk import
brought over — so a full backup of "what actually happened in the ERP" exists
independent of the eventual delete/reimport. **This ships no deletion logic at all** —
it is purely additive, read-only, and exists so the eventual cutover has something to
diff against.

| Screen | "Live" marker used | Why |
|---|---|---|
| Bookings | `limo_bookings.imported_at IS NULL` | Exact — the column exists specifically for this (added same week); only `LegacyBookingImporter` ever sets it |
| Receipts | `reference NOT LIKE 'L-RCPT%'` | `LegacyReceiptImporter` keeps the old system's `L-RCPT12968`-style number verbatim, bypassing `HasReference`'s auto-format |
| Quotations | `LENGTH(reference) > 7` | `LegacyQuotationImporter` always writes exactly `QT/0555` (4-digit, 7 chars); the live/auto format zero-pads to 5 digits (8 chars) |
| Invoices | `booking.imported_at IS NULL` AND (`notes IS NULL` OR `notes NOT LIKE 'Invoice #%'`) | **Best-effort, not exact** — `LegacyInvoiceImporter` never sets its own reference marker, so this combines "linked to a legacy booking" with the importer's fixed `notes` shape. **Known gap:** an old CSV row whose booking reference AND every fallback were blank produces `booking_id=null` AND `notes=null` on import — indistinguishable from a genuine live standalone invoice by these two predicates. Spot-check before trusting this screen's export as complete |

Implementation: each of the 4 `*Rows` services (`LimoQueueRows`/`LimoReceiptRows`/
`LimoQuotationRows`/`LimoInvoiceRows`) gained a trailing `bool $onlyLive = false`
param on `query()`/`all()` applying the matching filter above; each `*ExportController`
branches on `?live=1` in its CSV route, calling `all()` with `onlyLive: true` and an
**empty tab** (bookings) / **ignoring every other filter** (receipts/quotations/invoices)
so the export is a full, unfiltered backup regardless of whatever tab/date/search the
screen happened to be showing. **Bookings deliberately passes tab `''`, not
`TAB_ALL`** — `LimoQueueRows::query()`'s `TAB_ALL` branch silently drops cancelled
legs when the search box is empty, which would make "Live entry data" (a backup) miss
cancelled trips; an empty string matches neither branch, so no status filtering runs
at all. Button label translated (`lang/ar.json`: "Live entry data" → "بيانات الإدخال
المباشر"). Tests: `LimousineModuleTest` (+2, bookings) + `LimoBespokeExportTest`
(+3, one per remaining model) — all assert a legacy-imported row is excluded and a
live one is included.

**Fixed 2026-09-22 — the markers alone let the old data through.** `imported_at`
was added *after* the historical import ran, so every imported booking had it
NULL and read as "live" (the owner's export was full of `Booking #…` / admin /
completed-paid rows). All four filters now also require **`created_at >= 15 Sep
2026`** (company timezone), via `Modules\Limousine\Support\LiveEntry::since()`.
That date holds for all of them: the legacy importers backdate `created_at` to
the old system's dates, and the bulk migration ran on 3–5 Sep. Test:
`LimousineModuleTest::test_live_entry_data_excludes_unmarked_bookings_from_before_the_cutover`.

**The bookings backup is now lossless (shipped 2026-09-22).** The printed
columns alone lost trip/receipt numbers, comments, "Added by", booked time, a
round trip's legs belonging together, and the car/driver links on a reimport.
The live CSV/Excel now appends Booking reference / Customer phone / Customer
email / Company reference / Passenger, plus a last column **"Record data (do not
edit)"** (English by design — the importer finds it by that exact name) holding
`Modules\Limousine\Support\BookingSnapshot::capture()`: the RAW stored rows
(`getAttributes()`, never `toArray()`, which would shift times to UTC) of the
booking, every leg, its customer, its invoices (its own + any a receipt was paid
against) and its receipts. `BookingImporter` detects that column and calls
`BookingSnapshot::restore()` instead of its summary path: one DB transaction per
booking, **original ids kept when free** (so expenses / payment links / coupon
use pointing at the booking stay attached; a taken id gets a new one and is
remapped), only today's columns inserted, `saveQuietly()` so no hook renumbers
or re-prices, customer = same record → same phone ending → same name → recreated,
an invoice/receipt whose reference already exists is reused/skipped, and a booking
whose reference is on file is skipped (re-importing is a no-op). NOT carried:
receipts with no booking, and the coupon/payment-link/expense rows themselves (only
their link survives, by id). Excel re-saving a CSV can mangle the record cell —
import the downloaded file as-is. Test:
`LimousineModuleTest::test_a_live_backup_restores_every_booking_detail_after_a_delete`
(every stored column identical after delete + import, second import skips).

**The four "Live entry data" BUTTONS were removed from the screens on
2026-09-27 at the owner's request.** The export itself still works — each
controller still honours `?live=1` (and the tests still cover it), it just has
no button. Re-add the `<a href="…/export/csv?live=1">` link beside Print on a
screen if it is needed again.

**This is a backup step only — the actual delete-and-reimport plan is still
undecided** and requires, before any execution: which date field the cutoff applies
to, what happens to invoices/receipts linked to a deleted booking, and explicit
confirmation a backup was taken. Do not execute any deletion/renumbering of
Limousine data without the owner's unambiguous go-ahead on those specifics.

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
| 17 | **WooCommerce** (`Modules/WooCommerce/`): one-way ERP → store product sync (queued REST push on product save/delete + POS-sale stock). Phase A (push) DONE; Phase B (online orders + stock back via webhooks) = next | ✅ Phase A |
| 18 | **Cloudflare Stream** (core `app/Erp/Stream/`): browser-direct video upload (one-time upload URL) + public watch link. Rental orders get **pickup & return condition videos** | ✅ DONE |

**Phase 8 — Settings (where things live):**

| Concern | Location |
|---|---|
| Schema | `database/migrations/..._create_ir_config_parameter_table` — `key`(uniq), `value`(text), `type`(string\|bool\|number\|image), `group`, `label`, `description`, `sort`. `image` is a UI-only flag — the column stores a string (relative path on the `public` disk, e.g. `company/logo.webp`); `cast()` returns it verbatim. The settings view renders it as an upload widget routed through `FormImageUploadController` |
| Model | `App\Models\Ir\IrConfigParameter` |
| Service | `App\Erp\Settings\SettingManager` (whole set cached `rememberForever`; `get/set/setMany/grouped/flush`; writes flush) + `App\Erp\Settings\Setting` facade (singleton bound in `AppServiceProvider`). **Cache key is namespaced by the active database** — `erp.settings.all:md5(DB::connection()->getDatabaseName())` — NOT a fixed key. Each workspace has its own `ir_config_parameter` table; the runtime `cache.prefix` swap in `WorkspaceManager::activate()` is NOT reliable on its own because Laravel resolves the cache store once and memoises its prefix (so a prefix change after first cache touch is ignored). The fixed-key version leaked across workspaces: after a deploy cleared the cache, Main was read first and populated the shared key, then every tenant served Main's company name/logo/currency. Tying the key to the active connection's database name guarantees isolation. Regression: `tests/Feature/SettingWorkspaceIsolationTest.php` |
| UI | `App\Livewire\Pages\SettingsPage` — **role-gated**: admins see all keys; non-admins (POS cashiers, sales users) see only the keys in `SettingsPage::NON_ADMIN_KEYS` (currently `['company.language']`). Index-keyed `$form` so dotted keys aren't read as nested; `mount()` filters `$form` to the allowed set; `save()` re-filters before `setMany` so a crafted payload can't escalate. The `company.language` row is **per-user** — `mount()` reads `Auth::user()->language`, `save()` writes it (not `setMany`); see Phase 12 row. Group→tabs; bool=toggle/number/string controls; bulk Save → `setMany` → cache flush → `resources/views/livewire/pages/settings.blade.php` |
| Route/Nav | `/app/settings` (named `settings`, registered **before** `/app/{module}`); Settings link lives in the **topbar user dropdown** (between Profile and Sign out, `components/layouts/app.blade.php`) — moved out of the Sidebar 2026-06-10 — shown to every authenticated user (the page itself enforces what each role can edit) |
| Seed | `SettingSeeder` (General: `company.name`, `company.logo`, `currency.default`, `company.timezone`, `company.language`) — non-destructive (keeps saved values), in default `DatabaseSeeder` chain. `company.logo` ships with an empty value; once an admin uploads a logo the path is rendered on the login page (guest layout), the topbar brand, and the POS receipt overlay |
| Logo render path | Admins set `company.logo` under Settings → General. The file goes to `storage/app/public/company/<hash>.<ext>` via `FormImageUploadController` (bucket `company` — must stay in lockstep with deploy.yml's rsync `--exclude` list, see [[rsync-delete-wipes-user-uploads]]). Helpers: `Setting::get('company.logo')` returns the relative path; `App\Erp\Branding\Logo::url()` is the single render path (returns null if no logo OR file missing — same guard pattern as `User::avatarUrl()`). Used in `resources/views/components/layouts/{app,guest}.blade.php` and `Modules/Pos/resources/views/terminal.blade.php` |

Usage: `Setting::get('company.name')`, `Setting::set('currency.default', 'EUR')`. App-switcher still shows the demo `settings` tile to all (ACL enforced on click → non-admins land on the restricted view), like other apps. To grant non-admins another setting, append its key to `SettingsPage::NON_ADMIN_KEYS` — no other changes needed (view is data-driven). The settings-nav partial's WhatsApp pill stays admin-only.

**Phase 9 — Inventory scaffold (where things live):**

| Concern | Location |
|---|---|
| Schema | `Modules/Inventory/database/migrations/*` — `warehouses`, `stock_locations` (hierarchical `parent_id` + `type`), `stock_operation_types`, `stock_moves` (src→dest, state, `lot_name`/`barcode` scaffold), `stock_quants` (on-hand per location, unique loc+product+lot) |
| Enums | `Modules\Inventory\Enums\{LocationType,MoveState}` — LocationType: Vendor/View/Internal/Customer/Inventory/Production/Transit |
| Models | `Modules\Inventory\Models\{Warehouse,StockLocation,StockMove,StockOperationType,StockQuant}`. `StockMove::affectsValuation()` = the double-entry rule (value only changes crossing the Internal boundary to/from Customer/Vendor). `product_id` is a **logical** ref (Inventory decoupled — no FK to a catalogue) |
| Dashboard | `Modules\Inventory\Livewire\InventoryOverview` (`/app/inventory`) → one Kanban card per `StockOperationType` with **live** `toProcessCount()`/`lateCount()` + KPI strip; New/View-All deep-link to the transfers pages. **"Products in stock" KPI counts `pos_products.stock_on_hand > 0`** when POS is installed (the catalogue staff actually stock — `stock_quants` only fills from purchase receipts/transfers since POS sales/stock edits don't post quant moves), guarded by `Schema::hasTable('pos_products')` with a `stock_quants` fallback (no hard POS dependency — same coupling style as the core Dashboard). **That KPI card is also a button** — `wire:navigate` to `/app/pos/stock-report` (the Stock Report below) via the `productsUrl` view var; the other three KPI tiles stay static. NOTE: POS ↔ Inventory stock are still **separate ledgers** — only `PurchaseConfirmer` syncs both; a true POS→quant sync (POS sale posts a stock move / POS stock edit posts an adjustment) is an unbuilt follow-up |
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

**Phase 14 increment shipped 2026-06-09 — account asset fields:**

- **Attachment + cost/unit + units on an account** — three optional,
  **descriptive-only** fields on the Chart-of-Accounts `Account` form (they do
  NOT feed any ledger maths — journal items remain the source of truth). New
  columns via `2026_06_09_400005_add_asset_fields_to_accounts`: `document_path`
  (string, the engine `file` widget — PDF/image), `cost_per_unit`
  (`decimal(12,2)`, cast `float`), `units` (`unsignedInteger`, cast `integer`).
  Added to `Account` fillable/casts + `irModelDefinition()` fields + form arch
  (`document_path` = `'widget' => 'file'`, the other two = `'number'`). The
  upload bucket is `accounts` (whitelisted in `FormFileUploadController` +
  excluded in `deploy.yml` rsync). **Deploy now self-heals Accounting**: the
  remote post-deploy step runs `migrate --path=Modules/Accounting/database/
  migrations --force` and `module:resync accounting` (added 2026-06-09), so
  accounting migrations/arch land on prod without manual SSH — same treatment
  POS already had. Test: `tests/Feature/AccountFileFieldTest.php` (upload
  endpoint accept-PDF / reject bad type+bucket, form renders the 3 fields,
  save persists all three). If asked for a real fixed-asset register
  (depreciation, asset categories) — that's a separate feature, not this.

**Phase 15 — Kitchen Display System (`Modules/Pos/`, shipped 2026-06-01 / 2026-06-02):**

| Concern | Location |
|---|---|
| Enum: station | `Modules\Pos\Enums\PrepStation` — backed-string `kitchen` / `shisha` + `label()`. Adding a new station = a new case + `/app/pos/kitchen/<value>` URL (the screen is the same component, parameterised). |
| Enum: line lifecycle | `Modules\Pos\Enums\PrepStatus` — `pending` → `preparing` → `ready` → `completed`. Provides `next()` (single-step forward), `nextLabel()` (button text per state), `color()` (Tailwind tone token), `label()` (translated user-facing name), `active()` (list of statuses still on screen). |
| Category → station | `pos_categories.station` (nullable VARCHAR(16), indexed) added by `2026_05_31_200002_add_station_to_pos_categories`. `PosCategory` exposes it via a **custom Attribute mutator** (not the standard enum cast — see Phase 7 increment 2026-06-01 for why); list arch declares `format: badge`, form arch declares `widget: select` with options `['' → "— None (no KDS routing) —", 'kitchen' → 'Kitchen', 'shisha' → 'Shisha']`. |
| Line lifecycle columns | `pos_order_lines.{prep_status, prep_sent_at, prep_started_at, prep_ready_at, prep_completed_at}` added by `2026_05_31_200001_add_kds_columns_to_pos_order_lines`. `PosOrderLine::advancePrep()` walks one step forward and stamps the matching transition timestamp; idempotent at the terminal state. |
| Routing listener | `Modules\Pos\Services\KitchenRouter::route(PosOrder): int` is the sole "send to kitchen" implementation — stamps `prep_status = pending` + `prep_sent_at` on every line whose product → category → station resolves, idempotent (a line already routed is left alone). **Uses `DB::table()`** for the station lookup pluck — Eloquent's `pluck` would route through the `station` Attribute accessor and hand back `PrepStation` enum instances that the typed map closure can't return. Called by `PosTerminal::addProduct()` (auto-send on add — postpaid flow) AND `Modules\Pos\Listeners\QueueLinesForKitchen` on `PosOrderPaid` (safety net for non-terminal orders; usually a no-op since items already routed). Listener wired in `PosServiceProvider::boot()` alongside the WhatsApp listener. |
| Screen | `Modules\Pos\Livewire\KitchenDisplay` — single component parameterised by `public PrepStation $station;`. Routes: `/app/pos/kitchen/kitchen` (food) and `/app/pos/kitchen/shisha` (shisha) under `web/auth/pos.session:Read`. `mount(PrepStation $station)` — typed as the enum because Livewire converts the URL segment via the typed property before `mount()` runs (an earlier `string` type 500ed with TypeError; route-level `whereIn` rejects unknown values upstream). |
| Component methods | `advance(int $lineId)` (per-line single-step), `markOrderPreparing(int $orderId)` (Pending → Preparing for every Pending line on the order — drives Pending column button), `markOrderReady(int $orderId)` (walks every Pending/Preparing line forward to Ready — drives Preparing column button), `completeOrder(int $orderId)` (forces every active line to Completed — drives Ready column button). All filter by station so a Shisha screen can never mutate a Kitchen line. |
| Ticket aggregation | `loadTickets()` queries active lines for THIS station, groups by order, returns `Collection<int, KitchenTicket>`. `KitchenTicket` (`Modules\Pos\Support\KitchenTicket`) is a readonly VO: `orderId`, `reference`, `sentAt` (earliest `prep_sent_at` on the order), `status` (least-progressed line's status — a multi-item ticket only graduates to Ready when every line is ready), `lines`, **`tableName`/`floorName`** (nullable — the card shows a "Table N · Floor" badge for dine-in orders; null = walk-in). Table/floor are looked up via a `pos_table_id`→`PosTable` map (NOT `$order->table` — that relation name collides with Eloquent's `$table` property and trips PHPStan). |
| View | `Modules\Pos\resources\views\kitchen-display.blade.php` — 3-column kanban (Pending / Preparing / Ready, hard-coded tone tokens so JIT scans every Tailwind class). Each card = one order's lines for this station. `wire:poll.5s` on the wrapping div. Big touch targets (`min-h-12`), late-ticket red ring (CSS `@keyframes lateFlash` after 15 minutes), per-line notes underlined. **Full-height, page never scrolls (2026-06-23):** the root is `h-full overflow-hidden` and simply FILLS the layout's `<main>` (`flex-1` below topbar + breadcrumb — no `100vh-3rem` math, which had ignored the `md:` breadcrumb bar and caused a whole-page scroll on tablet). The grid is `overflow-hidden`; on phones the 3 columns split the height into equal thirds (`grid-rows-3`), on `sm+` they're side-by-side (`sm:grid-cols-3 sm:grid-rows-1`) — each column's ticket list (`min-h-0 flex-1 overflow-y-auto`) is the ONLY thing that scrolls, never the page. |
| Empty-state diagnostic | If `PosCategory::where('station', $station)->count() === 0`, the screen renders an amber banner ("No categories are routed to this station yet … Open POS → Categories, edit each one that belongs here, and set Kitchen station to <name>") with a "Go to Categories" link. Surfaces the wiring gap that would otherwise just look like an "always empty" screen. |
| Sound + visual cue | Web Audio API generates a 2-tone ping in-code (no audio file). Hooked via `Livewire.hook('commit', { succeed })` scoped to this component's `wire:id` — fires once per round-trip after DOM patch, regardless of whether the new ticket arrived as `morph.added` or `morph.updated`. AudioContext is `.resume()`-d defensively before every ping + on `visibilitychange` so backgrounded-tab suspensions don't silently mute the kitchen. The **NEW (Pending) column glows** with a looping amber pulse (`.kds-new-glow`, `animation … infinite`) for as long as it holds an un-accepted order — server-driven (Blade adds the class when `$columnTickets->count() > 0`), so it stops when the cook taps "Start preparing". First ping requires "Tap to enable sound" once per session (browser autoplay rule). |
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

**Phase 16 increment shipped 2026-06-09 — inline vendor create:**

- **"New vendor" on the bill** — a button next to the Vendor picker in
  `PurchaseForm` opens a small modal (name required, optional phone/email) that
  creates a `Partner` (`is_company = true`) and selects it on the bill — no trip
  to Contacts. `PurchaseForm::{openVendorModal,closeVendorModal,saveVendor}` +
  `$addingVendor` / `$newVendor`. Gated by the same `purchases.purchase` Create
  permission as the form (so a buyer who may raise a bill may add its vendor);
  hidden once the bill is Confirmed. The modal lives **outside** the bill
  `<form>` so its inputs/submit can't trip the outer form; Esc / backdrop close
  it; `email` only validates when one is typed (blank stays optional). Test:
  `PurchaseConfirmTest::{test_inline_vendor_create_makes_a_partner_and_selects_it,
  test_inline_vendor_requires_a_name_and_validates_email}`. AR keys added
  (مورد جديد / إضافة مورد / اسم المورد / إغلاق).

**Phase 16 increment shipped 2026-06-16 — searchable product picker + inline product create:**

- **Each bill line's Product field is now a searchable combobox** (was a plain
  `<select>`). Type two letters and it filters the product list live, client-side
  — an Alpine combobox: the page root holds one shared `products` array (mapped
  `{id, name}` in the view) + a `filterProducts(q)` helper (substring, capped 50);
  each line is its own isolated `x-data` scope (`open` / `search` / `selectedName`)
  reading the parent list via Alpine scope inheritance. Picking calls
  `$wire.set('lines.{i}.pos_product_id', id)` (still fires `updatedLines` →
  description + unit-cost prefill); `@click.outside` resets the box to the
  selected name. RTL-aware panel (`start-0`). A confirmed bill renders the product
  name as read-only text (no combobox).
- **"+ New product" inline create** — Odoo-style "Create '<typed text>'" footer in
  every combobox dropdown (shown to users with purchase Create/Write). Opens a
  modal mirroring the existing "New vendor" one (`openProductModal(index, name)` /
  `closeProductModal` / `saveProduct`): name (required, prefilled from the search
  text) + the **full POS product field set** (Sale Price, Cost Price, Tax %,
  Barcode, Stock on hand, Unit select, Reorder point, Category select, Active,
  and a Photo upload routed through `FormImageUploadController` bucket
  `pos_products` — same Alpine fetch-POST as the engine image widget), gated by
  `purchases.purchase` Create. Saving creates a `PosProduct` (defaults: active,
  stock 0, tax 0, unit qty) and selects it on the line that
  opened the modal (prefilling description + unit cost). `saveProduct` dispatches
  `product-created {id, name, lineIndex}`; the root appends it to the Alpine
  `products` list and the target row updates its display — so the new product is
  immediately pickable without a page reload. Modal lives OUTSIDE the bill `<form>`
  (same pattern as the vendor modal). Tests:
  `PurchaseConfirmTest::{test_inline_product_create_makes_a_pos_product_and_selects_it_on_the_line,
  test_inline_product_requires_a_name}`. AR keys: Search a product… / No products
  found / Product name / Add product (New product, Create, Sale Price, Unit cost
  already existed). NOTE: product lookups use `where('name->en', …)` — `name` is
  translatable JSON.
  - **Extracted + reused (shipped 2026-06-22).** The inline-create modal + its
    logic were factored into a **shared trait** `Modules\Pos\Livewire\Concerns\CreatesProductInline`
    (`$addingProduct` / `$newProduct` props, `blankProduct()`, `inlineProductRules()`,
    `persistInlineProduct(): PosProduct`, `closeProductModal()`) and a **shared
    Blade partial** `pos::partials.new-product-modal` (expects `$addingProduct`,
    `$newProduct.*`, `$unitOptions`, `$categories` + the host's `saveProduct` /
    `closeProductModal`). `PurchaseForm` now `use`s the trait + `@include`s the
    partial (host keeps only `$productLineIndex` + its line-assignment `saveProduct`).
    The **POS recipe editor** (`PosRecipeEditor`, the product page's "Recipe"
    card) reuses the same trait + partial: its component `<select>` became the
    same searchable combobox (bound to `componentId` via `$wire.entangle`, `fixed`
    panel to escape overflow, "Create '<text>'" footer → `openProductModal`),
    gated by `pos.product` Create. Tests: `tests/Feature/PosRecipeEditorTest.php`
    (3 — inline create selects the component, name required, created component
    adds to the recipe). AR keys: Component / Qty / unit / Add component.

**Phase 16 increment shipped 2026-06-23 — vendor location, purchase name/expiry, condiment lines:**

- **Vendor "Location" field** — the inline New-vendor modal gained a Location
  text input; `PurchaseForm::saveVendor()` stores it into the Partner's **`city`**
  column (the single "where" field already surfaced in Contacts list/kanban — no
  new column). Validated `nullable|string|max:255`.
- **Purchase name + expiry date** — two new `purchases` columns (migration
  `2026_06_23_100003`): `name` (string, optional label) + `expiry_date` (date,
  e.g. shelf-life of perishables). Both **descriptive only** — no stock/accounting
  effect. Added to `Purchase` fillable/casts/@property, the `PurchaseForm` header
  (Purchase name + Expiry date inputs), `rules()`, mount/persist.
- **Condiment purchase lines** — a bill line can now buy a **condiment** as well
  as a product. `purchase_lines.pos_condiment_id` (nullable logical ref, migration
  `2026_06_23_100004`). The per-line picker became a **products + condiments**
  combobox using composite keys `p:{id}` / `c:{id}` (the line's editor field is now
  `component`, split into `pos_product_id`/`pos_condiment_id` on persist — mirrors
  the recipe editor + `product_recipes`). `PurchaseConfirmer` raises the matching
  stock: a product → `raisePosStock()` + warehouse receipt (as before); a condiment
  → `raiseCondimentStock()` (`pos_condiments.stock_on_hand += qty`, **no** warehouse
  move — condiments aren't on the Inventory ledger). Accounting total includes both.
- **Deploy**: `deploy.yml` now runs `migrate --path=Modules/Purchases/database/migrations`
  for Main (Purchases was previously absent from the auto-migrate list); tenants get
  it via `workspaces:migrate`'s installed-module backfill.
- Tests: `PurchaseConfirmTest` (`test_confirming_a_condiment_line_raises_the_condiment_stock`,
  `test_purchase_name_and_expiry_date_persist`, `test_inline_vendor_saves_the_location_to_the_partner`;
  the two existing form tests retargeted from `lines.*.pos_product_id` → `lines.*.component`).
  AR keys: Purchase name / Expiry date / Location / City / area / the name placeholder.

**POS — re-date a single order (shipped 2026-08-18):**

Sales entered **after the fact** (typing up a paper log of past days) are stamped
`ordered_at = now()` by the register, so a week of back-dated takings all pile
onto today and the day-by-day reports are wrong. `PosOrders` (the Orders List)
gained an **admin-only per-order "Change date"** action (calendar icon, gated by
the same `$canDeleteSales` = `pos.order` Write **+ `isAdmin()`**): `openDate($id)`
/ `saveDate()` / `closeDate()` with a small date modal. `saveDate()` moves **only
that order's** `ordered_at` onto the chosen day, **keeping its time of day**
(`->setDate(...)`), and `saveQuietly()`s — a re-dating is not a re-sale and must
not re-fire order hooks (stock consumption, receipts, journal entries). Logged to
the order's Chatter via `logChange()`. Tests:
`PosOrderSplitTest::{test_changing_one_orders_date_leaves_the_others_alone,
test_changing_an_order_date_is_admin_only}`.

**POS — mark an order delivered + record the driver's cost (shipped 2026-08-18):**

An admin-only **truck action** per row in the Orders List (beside the calendar):
`PosOrders::{openDelivery,saveDelivery,clearDelivery,closeDelivery}` sets
`channel = SalesChannel::Remote` and `delivery_fee` on that order.
**`delivery_fee` is OUR cost (we pay the driver) and deliberately does NOT feed
the order total** — that's `delivery_charge` — so tagging an **already-paid**
order leaves its total and `paid_total` untouched and it stays balanced (the
owner chose this meaning explicitly over charging the customer). Saved with
`saveQuietly()` + no `recalculate()`, logged to the order's Chatter. The row
shows a sky "Delivered · −fee" line under the total; "Not a delivery" reverts to
`Shop` with fee 0. Independent of the `RemoteSales` feature (which gates the
fuller remote-order workflow). Tests: `PosOrderSplitTest::{test_marking_a_paid_order_delivered_records_our_cost_without_touching_the_total,
test_a_mis_tagged_delivery_can_be_cleared, test_marking_an_order_delivered_is_admin_only}`.

**Deliberately per-order, NOT per-session.** A first attempt put the date on the
POS *session* and moved every order in it — rejected by the owner: the manager
needs to know what sold **on each day**, so orders must keep their own dates. A
session-wide re-date is the wrong shape for this business; don't re-add it.
(That `PosSessionPage::setSessionDate` control was removed the same day, as was a
short-lived `purchases.pos_session_id` tag — migration `2026_08_18_100007` added
it, `2026_08_18_100008` drops it. **SQLite gotcha:** dropping an indexed column
needs the index dropped in a SEPARATE `Schema::table` call first, or it fails with
"error in index … after drop column".)

**Phase 17 — WooCommerce sync (`Modules/WooCommerce/`, Phase A shipped 2026-06-24):**

One-way **ERP → WooCommerce** product push, mirroring the WhatsApp module's
shape (per-database encrypted config + queued job + admin Settings tab). A
product added/edited in the ERP appears on the WordPress/WooCommerce store.
Chosen by the user (2026-06-24): two-way eventually, push everything
(new/edits/stock/unpublish), all active products — **Phase A** below is the
ERP→store push; **Phase B** (online orders + stock back via webhooks) is the
next increment.

| Concern | Location |
|---|---|
| Manifest | `Modules/WooCommerce/module.json` (`depends:[base,pos]`, `application:false`, `models:[]`, sequence 30) — surface is a Settings tab, not an app screen |
| Config | `woocommerce_configuration` (single row: `store_url`, `consumer_key`/`consumer_secret` = **TEXT holding APP_KEY-encrypted ciphertext**, `api_version` default `wc/v3`, `enabled`). `WooCommerceConfiguration::current()` firstOrNew, `isConfigured()`, `apiBase()` = `{store_url}/wp-json/wc/v3`. **Per-database** (each workspace its own row) so it's effectively Kaleem-only — the module installs everywhere but stays **dormant until store keys are entered**, and only that DB syncs |
| Mapping | `woocommerce_product_links` (`pos_product_id` unique logical ref, `woo_id` nullable, `last_status`/`last_error`/`last_synced_at`). `woo_id` null until first push → POST creates; thereafter PUT `/products/{woo_id}` updates the SAME remote product (no duplicates). Row survives a product delete so the listing can still be unpublished |
| Service | `WooCommerceService` — `syncProduct()`/`unpublishProduct()` (QUEUE a push + mark link `queued`); `pushNow(id, action)` (the actual SYNCHRONOUS REST call → returns `{ok, skipped, error}`, never throws); `syncAllActiveNow()` (the "Sync all now" button — loops active products through `pushNow` **synchronously in the current request's DB context** and returns `{synced, failed, error}` for immediate UI feedback). Field map: `name` (EN translation), `type=simple`, `status`=active?publish:draft, `regular_price`, `manage_stock=true`+`stock_quantity` (int round), `sku`=barcode, `images[]`=primary `image_path` **first then every `PosProduct::galleryImages()` secondary path** (each `Storage::disk('public')->url(...)` — the WooCommerce listing shows the full gallery, primary as featured), `categories[]`=the product's POS category mapped to a WC term via `resolveCategoryId()` (GET `/products/categories?search=` exact-name match, else POST-create on the store; in-memory cached per name so a "Sync all" resolves each category once; best-effort — a category hiccup leaves the product uncategorised, never fails the push). HTTP Basic auth (key/secret) + 20s timeout. Every method no-ops when `! isConfigured()` |
| Queued job | `SyncProductToWooCommerce(posProductId, action='sync'|'unpublish', workspaceId?)` (`ShouldQueue`, tries=3, backoff 30). **Tenant-aware** — the database `queue` connection is pinned to Main (see `WorkspaceServiceProvider`), so a job dispatched from a tenant RUNS in Main's context; it carries the originating `workspaceId` and re-activates that workspace (`WorkspaceManager::withTenant`) before delegating to `pushNow`, otherwise it would read Main's (wrong) config/products and silently no-op. **This was the bug: products queued on a tenant (Kaleem) never reached the store.** Non-2xx → throw (retry → `failed_jobs`). **Needs the hPanel `schedule:run` cron** (`[[hostinger-cron-needed-for-queue-worker]]`); the manual "Sync all now" is synchronous so it does NOT depend on the cron |
| Triggers | `WooCommerceServiceProvider::boot()` (loaded only while installed): `PosProduct::saved` → upsert (or unpublish if just deactivated), **guarded by `wasChanged(SYNCED_FIELDS)`** (incl. `gallery_images`, so adding/removing a secondary image re-pushes) so per-keystroke autosaves don't spam; `PosProduct::deleted` → unpublish; `PosOrderPaid` → re-push each sold product's stock (sale-time `decrement()` bypasses model events). All gated by `storeReady()` = `Schema::hasTable(...) && isConfigured()` (defensive: a stray hook on a DB without the table no-ops) |
| Settings UI | `Modules\WooCommerce\Livewire\WooCommerceSettings` (**admin-only** `abort 403`) + `woocommerce::settings`; secrets write-only (blank = keep). Surfaced as a **"WooCommerce" tab** via `resources/views/partials/settings-nav.blade.php` (now generic `installedModule()` helper; shows the pill only when installed + admin). Tab content stays **English by design** (same integration carve-out as the WhatsApp tab). A "Sync all active products now" button calls `syncAllActive()` |
| Routes | `Modules/WooCommerce/routes/web.php` → `/app/settings/woocommerce` (`auth`, two-segment so the `/app/{module}` wildcard doesn't shadow it). Phase B webhook routes not built yet |
| Deploy | `deploy.yml` runs `migrate --path=Modules/WooCommerce/database/migrations --force` + `module:install woocommerce` (Main) + the existing `workspaces:install-modules` backfills tenants; `workspaces:migrate` keeps its migrations applied per tenant. Image-bucket note: it pushes existing `pos_products` image URLs (already deploy-excluded) — no new bucket |
| Tests | `tests/Feature/WooCommerceModuleTest.php` (12 — install schema, encrypted-at-rest, settings admin-gate + enable-needs-creds, service queues + no-op-when-unconfigured, job POST-then-PUT (create→update, asserts payload sku/status/stock), unpublish→draft, non-2xx marks failed + throws, **synchronous "Sync all now" reports synced/empty + surfaces a store error (401)**, saved-hook dispatches). HTTP mocked via `Http::fake` (mirrors `WhatsAppModuleTest`) — proves payload shape/auth/endpoints, not the live store |

To enable for a store: install the module on that database, then **Settings →
WooCommerce** (admin) → paste the store URL + REST consumer key/secret
(WordPress: WooCommerce → Settings → Advanced → REST API → Add key, Read/Write)
→ enable → "Sync all active products now". **Phase B (next, NOT built):** a
public `/woocommerce/webhook` endpoint (HMAC-verified) to import online orders
+ pull stock changes back into the ERP (the user picked two-way).

**Phase 18 — Cloudflare Stream video (core, shipped 2026-06-25):**

Browser-direct video upload to **Cloudflare Stream** + a public share link.
Built for the Rental flow per the user: a **pickup video** (car handover) and a
**return video** (car return) on each rental order, each becoming a shareable
Cloudflare watch link. Lives in **core** (not a module) so the routes are always
available; per-database encrypted config like the other integrations.

| Concern | Location |
|---|---|
| Config | `cloudflare_stream_configuration` (core migration, per database: `account_id` plain, `api_token` **encrypted**, `enabled`). `App\Models\CloudflareStreamConfiguration` — `current()`/`isConfigured()`/`apiBase()` = `https://api.cloudflare.com/client/v4/accounts/{id}/stream` |
| Service | `App\Erp\Stream\CloudflareStreamService` (injected `HttpFactory`, Bearer token) — `createDirectUpload(name)` mints a one-time **direct creator upload** URL (`POST /direct_upload`, returns `{uid, uploadURL}`), `videoInfo(uid)` reads `result.preview` (the public **watch URL**) + status/thumbnail, `deleteVideo(uid)`. `App\Erp\Stream\StreamException` on unconfigured / non-2xx |
| Endpoints | `App\Http\Controllers\StreamUploadController` — `POST /app/stream/upload-url` (mint, throttle 60/min) + `GET /app/stream/{uid}/info` (watch URL, throttle 120/min); both in the core `auth` group (`routes/web.php`). Errors → JSON 422 so the uploader shows them inline |
| Upload flow | **The big file never touches the ERP server.** Browser: (1) `POST upload-url` → `{uid, uploadURL}`; (2) `XMLHttpRequest` POSTs the file straight to Cloudflare's `uploadURL` (progress bar); (3) `GET {uid}/info` (retried) for the watch URL; (4) `$wire.call('setVideo', …)`. **Simple direct upload = ≤200 MB** (the error names the limit); >200 MB would need a tus upgrade (follow-up). Alpine `streamVideoUpload(kind, wire)` in `resources/js/app.js` (closure-captures `wire` per `[[livewire-wire-on-alpine-this]]`); shared Blade component `resources/views/components/stream-video-upload.blade.php` (prop is `kind`, NOT `slot` — `$slot` is reserved in Blade components) |
| Settings | `App\Livewire\Settings\StreamSettings` (admin-only) + `livewire.settings.stream-settings`, route `/app/settings/stream`; a **"Cloudflare Stream"** pill in `partials/settings-nav` (admin, always — core, no module gate). Token write-only (blank = keep). Tab content English (integration carve-out like WhatsApp/WooCommerce) |
| Rental wiring | Feeds the **existing** rental handover/return flow (the `handover_video_url` / `damage_video_url` columns + capture modals — migration `2026_06_25_900016`). The shared Blade component `<x-stream-video-upload target="handover_video_url" :url="…" />` REPLACED the manual "paste a URL" `<input type="url">` in the **handover modal** (= pickup video) and the **return modal**'s damage video. The widget takes a `target` prop (a Livewire property name, NOT `kind`) and writes the watch URL via `$wire.set(target, watchUrl)` (clear → `$wire.set(target, '')`); the modal's own save (with its `nullable\|url` rule) persists it. The handover video reuses their column; the optional damage video still sits under the "Customer caused damage" checkbox. **Mandatory return video (added 2026-06-25):** a separate **always-visible, required** "Return video" uploader is the **last field** of the return modal, writing to a NEW `rental_orders.return_video_url` column (migration `2026_06_25_900023`, auto-applied by deploy's Rental migrate step + tenants via `workspaces:migrate`). `OrderForm::confirmReturn()` validates it `required\|url\|max:500` (custom message "A return video is required to close the rental.") and persists it; `openReturn()` hydrates it; the saved-order summary shows a "View return video" link. So a rental can't close without a return video, while the damage video stays optional/conditional. |
| Tests | `tests/Feature/CloudflareStreamTest.php` (8 — token encrypted-at-rest, settings admin-gate + enable-needs-creds, service mints upload URL (asserts Bearer + endpoint), service reads watch URL, throws-when-unconfigured, upload-url endpoint 422-unconfigured/200-configured, OrderForm renders + accepts the handover video URL). HTTP mocked via `Http::fake`. The mandatory return-video rule is pinned by `RentalHandoverReturnTest::test_a_return_video_is_required_to_close_the_rental` |

**Videos go to THIS server for now, not Cloudflare (changed 2026-09-17, the
owner's decision — "until I give permission to upload to Cloudflare").**
`config('erp.video_storage')` (`ERP_VIDEO_STORAGE`, default **`local`**) picks
the destination for `<x-stream-video-upload>`; set it to `cloudflare` (env) to
restore the flow below — only when the owner approves.

| Concern | Location |
|---|---|
| Endpoint | `App\Http\Controllers\VideoUploadController` — `POST /app/video/upload-chunk` (`auth`, `throttle:1000,1`, `video.upload-chunk`). The browser sends the file in **2 MB chunks** (fits PHP's default `upload_max_filesize`) under one `upload_id` (`^[A-Za-z0-9-]{16,64}$`); chunks sit in `storage/app/private/video-chunks/{userId}/{uploadId}/` (per user, so one account can't finish another's), and the last chunk assembles the file, **checks its contents with finfo** (mp4/mov/m4v/webm/3gp/3g2/mkv/avi — the client's filename is never trusted), caps it at **1 GB**, and writes it to the public disk as `rental_orders/videos/{Y}/{m}/{random40}.{ext}` (unguessable — it's a customer's car on camera). Returns `{url, path}`; the chunk folder is always deleted |
| Browser | `streamVideoUpload(target, wire, mode)` in `resources/js/app.js` — `sendLocal()` loops the chunks with up to 4 retries each (a 422 refusal is not retried), progress per chunk, then `wire.set(target, url)`. The component passes `mode` from the config. The order form's "Uploads to Cloudflare" help text became "Share the link with the team." |
| Storage safety | `rental_orders/` is already in deploy.yml's rsync `--exclude`, so videos survive deploys. `storage/app/private/video-chunks/` was added too. Daily `prune-video-chunks` (routes/console.php) drops abandoned uploads older than a day. **Watch the host's disk space** — phone videos are large |
| Tests | `tests/Feature/VideoUploadTest.php` (7 — default is local, chunks join into one video on the public disk + chunks cleaned, a non-video refused, a missing chunk refused, cross-user refused, bad upload id refused, guest refused) |

To use: **Settings → Cloudflare Stream** (admin) → Account ID (from the
dashboard URL) + an API token with **Stream: Edit** → enable + Save. Then a
**saved** rental order shows the Pickup/Return upload boxes. The share link is
**public** (anyone with it can watch — the user's choice). NOT built: a
standalone video library, signed/private playback, >200 MB (tus) uploads,
videos on Limousine bookings (same widget would drop in).

**Settings increment shipped 2026-06-21 — "Users" tab (admin-only staff accounts):**

A new **admin-only "Users" tab** in Settings (alongside General / Daily Report)
to create a staff account (username + email + password) and grant it
**view-only** access to a chosen set of apps and databases.

| Concern | Location |
|---|---|
| Tab wiring | `App\Livewire\Pages\SettingsPage::render()` exposes `$userTab = isAdmin()`; `resources/views/livewire/pages/settings.blade.php` renders a `__users` tab button + panel that `@livewire(\App\Livewire\Settings\UserManager::class)`. Stable ASCII tab key (like `__reports`) so the localised label can't break Alpine tab state |
| UI component | `App\Livewire\Settings\UserManager` (+ `resources/views/livewire/settings/user-manager.blade.php`) — **admin-only** (`mount()` + every action re-`abort_unless(isAdmin)`). One form for **create AND edit** (`$editingId` toggles), `save()` branches: an apps multi-checkbox (installed `application` modules from `ir_module`) + (create only) a databases multi-checkbox (**ALL** workspaces incl. Main — Main is no longer implicit, **`workspaces` is `required\|min:1` on create**) + a list of **all** users (admins + staff, role badge) with **Edit** and **remove** per row. `editUser()` loads name/email + current app grants (derived from the per-user group's rules); `save()` on edit updates name/email/password (blank = keep) + rewrites app grants, **never touches `is_admin`**; `deleteUser()` blocks deleting yourself or the last admin. **Gotcha:** the public `$apps`/`$workspaces` props (selected values, for `wire:model`) would shadow same-named view vars — render() passes the option lists as `appModules`/`workspaceList` to avoid the collision (Livewire injects public props into the view) |
| Provisioning | `App\Erp\Admin\UserProvisioner` — the sole creator. `provision(name,email,password,appNames[],workspaceIds[]): ?User`: hashes once, then for **each selected workspace** runs `upsertWithAccess()` — Main on the current connection, each tenant via `WorkspaceManager::withTenant()` (its own SQLite file), matched by **email**. Returns the Main user, or null if Main wasn't selected (an account can live only in tenant DBs). `upsertWithAccess()` (DB transaction): `updateOrCreate` user (`is_admin=false`) then `grantApps()`. `grantApps(User,appNames[])` (public, reused by the edit path) rebuilds the **dedicated per-user group** (`code = user:{id}`) + one **Read-only** `ir_model_access` rule per registered `ir_model` of every granted app (write/create/unlink=false). `deleteUser()` removes the user + their per-user group + its ACL rules (tenant copies left in place, harmless) |
| Access model | App access = the existing ACL system: per-user group + `ir_model_access` Read rules. So a granted user can **open** the app's screens and read records but cannot add/edit/delete (engine List/Form gate mutations). Apps with **no** `DefinesIrModel` (Inventory custom screens, Settings, WhatsApp) have no models, so a grant on them creates no rules — view access there isn't ACL-expressible (note if asked). DB access is **provision-only**: the account is created in the chosen databases; **switching is unchanged (still admin-only)** — the tenancy security model (SetActiveWorkspace / SwitchWorkspaceController) was deliberately NOT touched. The list/edit/delete operate on the **current (Main)** database; a user created only in a tenant won't appear in the Main list |
| Tests | `tests/Feature/UserManagerTest.php` (19 — view-only grants on selected apps, granted-apps-only (denies a non-granted installed app), create requires ≥1 database, non-admin 403, name/email/password validation, duplicate email rejected, edit renames + rewrites grants, edit keeps blank password, can delete a non-last admin but not yourself, delete removes user+group+rules, provision **only** into the selected databases — not Main when unselected; + the 5 in-workspace tests listed below) |

Decisions (chosen by the user): app access = **View only**; database access = **provision only** (no self-switching); Main is a normal pickable database (not auto-included); the list shows all users + admins and supports edit/delete. To widen later: change the `perm_*` flags in `UserProvisioner::grantApps()` (e.g. add Write/Create), or lift the admin-only switch gate for granted users (would need a `workspace_user_access` grant table + relaxed `SwitchWorkspaceController`/`SetActiveWorkspace` + a non-admin switcher UI — out of scope here).

**Add users from INSIDE any database (shipped 2026-07-13):** the Users tab used
to be **read-only inside a workspace** ("switch to Main to add a user") because
logins are only ever authenticated against Main — a row created in a tenant
alone could never sign in. That trip to Main is gone: an admin now adds/edits/
deletes users **from whatever database they're in**, and the identity plumbing is
handled for them.

- **What a workspace-created account is:** the real row — role (`staff`/`admin`)
  + app grants + `home_workspace_id` — lives **in that database**, and a bare
  **non-admin login shell** (same email, `home_workspace_id` = that workspace) is
  written to **Main** behind the scenes. `SetActiveWorkspace` already routes a
  user with a `home_workspace_id` straight into their workspace ignoring the
  cookie, so they sign in and land inside it and can never reach Main or another
  database. **An "Administrator" created inside a workspace is an admin of THAT
  database only** (their Main shell stays non-admin).
- **`UserProvisioner::provisionLocked()` is the single path** (generalised from
  the super-admin-only version): now takes `?string $plainPassword` (null/blank =
  keep — the edit path), `bool $isAdmin`, and `list<string> $appNames`. It is
  **connection-explicit** — the Main shell goes through the new
  `WorkspaceManager::withMain()` and the real row through `withTenant()` — so it
  behaves identically whether called from Main or from inside a tenant. New
  siblings: `mainEmailConflict(email, workspaceId)` (refuse an email already
  owned by a global account or another workspace's user, rather than silently
  clobbering the login) and `deleteLocked(email, workspaceId)` (drop the tenant
  row + grants AND the Main shell — but only when that shell really is ours:
  locked to this workspace and not an admin).
- **`WorkspaceManager::withMain(Closure)`** — run a callback on the landlord
  connection from anywhere (no-op when Main is already active).
  **`withTenant()` now also restores the tenant connection's PATH**, not just the
  default connection name — otherwise a call made from *inside* a workspace left
  the shared `tenant` connection aimed at another file for the rest of the
  request.
- **`UserManager`**: `onMain()` no longer gates anything; `currentWorkspaceId()`
  resolves the active workspace **from the tenant connection's SQLite file**
  (NOT the cookie — a locked admin is routed with no cookie, and the connection
  is the DB we'd actually write to), falling back to the actor's home workspace
  then the cookie. `belongsHere()` scopes every mutation inside a tenant to that
  database's own accounts: a **global** account (copied into every DB, `home_workspace_id`
  null) stays read-only there and still shows "Managed on Main" — editing it from
  one database would silently change every other one. The database picker +
  "lock to one database" checkbox are hidden inside a workspace (both implicit).
  Edit/delete still go through the regular-admin email-OTP gate.
- Tests: `UserManagerTest` (+5 — add a user from inside a workspace (tenant row
  locked + view-only, Main gets a non-admin shell), an admin added inside a
  workspace is admin **only** there, an email owned by a global account is
  refused, a global account stays read-only inside a workspace, deleting a
  workspace user removes their Main login too). AR keys added.

**Roles: one mutually-exclusive choice (shipped 2026-07-13):**

`App\Erp\Admin\StaffRole` (backed enum) is the **single source of truth** for what
a user account is. **ONE role per account** — the old "admin who is also an
accountant" combination is gone, and so are the inline `toggleSuperAdmin` /
`toggleAccountant` row buttons (both tiers are now roles in the **Edit form**, so
there's one place a role is set and one set of guards).

| Role (`value`) | Flags | Grants on each chosen app |
|---|---|---|
| Staff (`staff`) | — | Read |
| Supervisor (`supervisor`) | — | Read + **Write + Create** (never Delete) |
| Accountant (`accountant`) | `is_accountant` | Read + **Write + Create** (never Delete) + `canConfirmPayments()` |
| Administrator (`admin`) | `is_admin` | n/a — bypasses the ACL |
| Super admin (`super`) | `is_admin` + `is_super_admin` | n/a — bypasses the ACL |

**Accountant broadened to Supervisor-level Write+Create (2026-09-20).** It
used to be strictly Read-only plus the payment-confirmation power — but that
left no role for someone who both manages ordinary records (e.g. adding
drivers) AND confirms payments/writes receipts by hand, which is exactly what
a real accountant on staff needed. `StaffRole::permissions()` now returns the
same shape for `Accountant` as `Supervisor`. **`StaffRole::of()` still checks
`is_accountant` before ever consulting the ACL's write rules** (see
`rolesFor()`'s bulk badge resolution below), so an Accountant is never
mis-labelled as a Supervisor now that their granted rules look identical.

- **Supervisor is NOT a column** — it's the *shape* of the ACL rules the user's
  per-user group carries (a Supervisor's rules have `perm_write`). So
  `UserProvisioner::roleOf(User)` reads it back from the rules (the edit form's
  radio would otherwise lie), and `UserManager::rolesFor()` resolves the whole
  list's badges in ONE extra query rather than per row.
- **Owner-only roles:** `StaffRole::needsSuperAdminToAssign()` → **Super admin**
  and **Accountant** (confirming money was received is deliberately not in a
  regular admin's gift — it preserves the old owner-only toggle's rule). Enforced
  in the `role` validation rule (`Rule::in($this->assignableRoles())`), so a
  crafted payload fails too, and the picker only renders the roles the actor may
  assign.
- **`UserManager::safeRole()`** is the demotion guard: you can't strip the **last
  super admin** or the **last admin** of their tier, and you can't demote
  **yourself** — the existing tier is kept instead. (This replaced the guards that
  used to live inside the two toggles.)
- `UserProvisioner` is role-aware end to end: `provision(..., StaffRole $role)`,
  `provisionLocked(..., StaffRole $role, array $appNames)`, and
  `grantApps(User, appNames, StaffRole)` writes `perm_*` from
  `$role->permissions()`. The role owns all three flags in one place
  (`upsertLockedRow` / `upsertWithAccess`), so they can't drift from the label the
  admin picked.
- Tests: `UserManagerTest` (supervisor can view/add/edit but **not delete**; edit
  reads the supervisor role back + demote to staff; accountant may confirm
  payments and is not an admin; a regular admin can't assign the owner-only roles;
  the picker's options differ for admin vs super admin; the last super admin can't
  be demoted) · `SuperAdminTest::test_only_a_super_admin_can_promote_another` and
  `RentalPaymentConfirmationTest::test_only_a_super_admin_can_grant_the_accountant_role`
  retargeted from the removed toggles to the role picker. AR keys added.

**Pause a user account (shipped 2026-09-08):** every user row in Settings →
Users now has a **3-dot menu** (Edit / Pause·Unpause / Delete) replacing the
plain "Edit"/"remove" text links — same isolated per-row Alpine scope +
`@click.outside` pattern as the app-bar dropdowns. Pausing an account signs it
out **immediately** and refuses sign-in until an admin unpauses it. This is
**core** (not module-scoped) — it works identically in Main and every
workspace, with no dependency on business type or company identity (unlike
the Sweileh Café happy-hour feature, which is deliberately gated to one
database).

| Concern | Location |
|---|---|
| Schema | `users.is_paused` (bool, default false) + `paused_at` (nullable timestamp) — core migration `2026_09_08_100001_add_is_paused_to_users_table`, so it auto-applies to Main via `migrate --force` and backfills every tenant via `workspaces:migrate` |
| Model | `User::isPaused()` — column-guarded like `isSuperAdmin()`/`isAccountant()` (reads false on a not-yet-migrated DB) |
| Instant kill | `App\Erp\Security\SessionKiller::killFor(User)` — deletes the paused user's rows from the **sessions** table and rotates their `remember_token`. Sessions always live on the LANDLORD (Main) connection (`WorkspaceServiceProvider` pins `session.connection` to the boot-time default so a tenant swap never logs anyone out — see `[[workspaces-tenancy-architecture]]`), so the row to delete is keyed by the **Main-matched** copy of the account (by email), never a tenant-local id. Best-effort (wrapped try/catch) — it is defence-in-depth, not the authoritative guard |
| Authoritative guard | `App\Http\Middleware\EnsureUserIsNotPaused` — appended to the `web` group right after `SetActiveWorkspace` (so it reads the correct per-database row) and before `SetLocale`. Re-checks `is_paused` on **every** request for the currently-resolved user; if paused it force `Auth::logout()`s, invalidates the session, and `abort(419)`s — reusing the app's existing "session expired" handling (a Livewire AJAX request treats 419 as expired and reloads silently via the `window.confirm` override in the master layout; a plain page load renders `errors/419.blade.php`, which reloads the current URL via JS). Either path lands on `/login` because the session is already dead. This is what actually guarantees an instant kick regardless of session driver or a live "remember me" cookie re-authenticating them on some later request |
| Sign-in rejection | `App\Livewire\Auth\Login::login()` — after `Auth::attempt()` succeeds, checks `isPaused()`; if paused it logs them straight back out and throws a validation error ("Your account has been paused. Contact your administrator.") **without** counting it against the rate limiter (the credentials were correct) |
| Guards on pausing | `UserManager::canPause()` — can't pause **yourself** (an instant, unrecoverable self-lockout since only another admin could undo it) or the **last admin** (mirrors the existing `canDelete()` guard). Unpausing carries no such risk and needs no guard |
| 2FA | `togglePause()` carries the same email-OTP gate as edit/delete (`ConfirmsWithEmailOtp`, action `user.pause`) — a regular admin confirms an emailed code, a super admin acts immediately |
| Activity log | `user_paused` / `user_unpaused` action codes added to `ActivityLog::LABELS`/`COLORS` |
| Tests | `AccessControlTest::{test_a_paused_users_very_next_request_signs_them_out, test_a_paused_user_cannot_sign_in_even_with_the_right_password}` · `UserManagerTest::{test_pausing_a_user_kills_their_session_and_marks_them_paused, test_unpausing_a_user_restores_their_account, test_an_admin_cannot_pause_themselves, test_a_shared_accounts_pause_can_be_toggled_from_inside_a_workspace}` · `SuperAdminTest::{test_regular_admin_must_pass_email_otp_to_pause_a_user, test_super_admin_pauses_a_user_without_otp}` |

AR keys added: Pause / Paused / Unpause + the pause-confirm and paused-login
strings.

**Fixed same day — pause must not require a trip to Main.** The first cut
scoped `togglePause()` by `belongsHere()` (same rule as Edit/Delete), so a
**global** account (shared across every database — every admin in the
screenshot the owner sent back showed "Managed on Main" with no actions at
all) could only be paused from Main. That defeats the point: an admin managing
one business's database needs to be able to block someone's access to THAT
database right now, without switching databases. **Pause is not an identity
edit** — `is_paused` is a per-database column like any other, so toggling it
on whatever row exists in the CURRENTLY ACTIVE connection is always correct,
global account or not. Fix: `performTogglePause()` dropped the `belongsHere()`
check entirely; the blade's action menu now renders Pause/Unpause on **every**
row (`$canTogglePauseOn` = `$canManage && ! $isSelf && ! $isLastAdmin`,
independent of `$inScope`/`$canSetAccessHere`) while Edit/Edit access/Delete
stay scope-gated exactly as before. `$showActionsMenu` decides whether the
3-dot button renders at all (nothing to show for a self-row that's both
out-of-scope and not yet paused). Test:
`UserManagerTest::test_a_shared_accounts_pause_can_be_toggled_from_inside_a_workspace`
(pauses/unpauses both a shared staff row and a shared ADMIN row from inside a
workspace, neither locked to it).

**Remove a login from every database — `user:remove` (shipped 2026-09-08):**
the CLI twin of Settings → Users' **Delete**, for the case the screen can't
serve the job: a GLOBAL account (shared across every database, "Managed on
Main") can only ever be deleted from Main through the UI — deleting one from
inside a workspace is refused there on purpose (its identity belongs to
Main). Mirrors the existing `user:ensure` / `ensure-user.yml` pair exactly,
but for removal instead of provisioning.

| Concern | Location |
|---|---|
| Service | `UserProvisioner::deleteEverywhere(string $email, ?array $onlyWorkspaceIds = null)` — `null` = every database (Main included); iterates `WorkspaceManager::all()`, deleting via the existing `deleteUser()` on whichever connection is active for that row (`withMain()` / `withTenant()`). A database with no matching account is silently skipped. Returns the list of database names an account was actually removed from |
| Command | `App\Console\Commands\RemoveUserCommand` (`user:remove {email} {--databases=all}`) — `php artisan user:remove admin@example.com` removes it everywhere; `--databases=3,7` restricts to those workspace ids (Main's own id must be included explicitly to touch Main) |
| Manual workflow | `.github/workflows/remove-user.yml` (`workflow_dispatch`, inputs `email` + `databases`) — SSHes into erp.wanaan-bh.com and runs the command, same pattern as `ensure-user.yml` |
| Tests | `tests/Feature/RemoveUserCommandTest.php` (5 — removes on Main, no-op when nothing matches, removes a shared account from Main **and** a provisioned workspace, `--databases` restricts to just the given workspace, requires an email) |

First real use: removing the seeded demo `Administrator / admin@example.com`
account from Main and every workspace it had been copied into at provisioning
time. **Not** the same account as `BOOTSTRAP_ADMIN_EMAIL` (the `admin:ensure`
recovery login re-asserted on every deploy, named "Wanaan Admin") — the two
are unrelated, so removing the demo admin does not get undone by the next
deploy.

**Set the system-wide default language — `language:set-default` (shipped
2026-09-10):** `company.language` is the fallback locale a **guest** on the
login page (and any signed-in user with no personal preference) gets — see
`App\Http\Middleware\SetLocale`. There is **no in-app control for it**:
the Settings page's "Language" row looks like it should own this, but it's
deliberately a **personal** preference (reads/writes `Auth::user()->language`,
never the `ir_config_parameter` row — see `SettingsPage`'s `PER_USER_LANGUAGE_KEY`),
so an admin toggling their own language never touches what a brand-new visitor
sees. This command is the only way to change it, same shape as `user:remove`.

| Concern | Location |
|---|---|
| Command | `App\Console\Commands\SetDefaultLanguageCommand` (`language:set-default {code=en} {--databases=all}`) — validates `code` against `en`/`ar` (same whitelist `SetLocale` enforces), iterates `WorkspaceManager::all()` via `withMain()`/`withTenant()` and calls `Setting::set('company.language', $code)` on each (idempotent; a database already on the code is just re-set and still reported) |
| Manual workflow | `.github/workflows/set-default-language.yml` (`workflow_dispatch`, inputs `code` + `databases`) — SSHes into erp.wanaan-bh.com and runs the command, same pattern as `remove-user.yml` |
| Tests | `tests/Feature/SetDefaultLanguageCommandTest.php` (4 — sets on Main, rejects an unsupported code, sets every workspace too, `--databases` restricts to just the given workspace) |

First real use: forcing every live database (Cheeky, Hashtag limo, Kaleem
Perfume W.L.L, Wanaan Car Rental W.L.L, Swelieh Cafe/Main) to `en`, so a
brand-new visitor or freshly-created user with no personal language choice
always lands in English regardless of what `company.language` had drifted to
on that database.

**App lists must go through `Features::moduleAllowed()` (fixed 2026-07-13):**
the business-type gate has to be applied at **every** surface that lists
installed application modules, not just the app bar. Two were missing it and
offered/navigated-to apps the database doesn't run (Rent A Car in a perfume
shop):

- `UserManager::render()` — the Users tab's "Apps this user can access"
  checklist listed every **installed** module. Now filtered.
- `CommandPalette::corpus()` — ⌘K would jump you into a hidden app. Now filtered.
- Already correct: `AppSwitcher` (app bar), `Dashboard` (quick-launch),
  `ModuleMenu` (tiles + app-bar dropdowns, filters **models** via
  `Features::modelAllowed()`).
- Deliberately NOT filtered: `Dashboard`'s `appCount` / `moduleCount` — those
  are the **super-admin-only system cards** reporting what is *installed*, not
  what is *visible*.
- **`UserProvisioner::grantApps()` now filters too** (apps via `moduleAllowed`,
  models via `modelAllowed`) — the UI filter alone isn't enough: a crafted
  Livewire payload could tick a hidden app. More importantly, `grantApps` runs
  **once per target database, inside that database's connection**, and `Features`
  reads that database's own `company.business_type` — so ONE tick-box creates
  grants matching **each** database independently (Main-as-café gets POS, a
  rental tenant gets nothing from the same tick). Tests:
  `UserManagerTest::{test_the_app_checklist_only_offers_apps_this_business_type_runs,
  test_a_grant_for_an_app_the_business_type_hides_creates_no_access,
  test_grants_are_scoped_per_database_by_each_ones_business_type}`.
- **When adding a new surface that lists apps or models, route it through
  `Features::moduleAllowed()` / `modelAllowed()`** — an unfiltered `IrModule`
  query is the bug.

**Super-admin tier + admin 2FA (shipped 2026-06-24):**

An owner role **above** admin. A super admin is a **strict superset** — its
row also carries `is_admin = true`, so every existing `is_admin` gate keeps
passing — and the extra `users.is_super_admin` flag gates owner-only powers.
Regular admins keep everything else, but destructive identity/tenancy actions
now require an **emailed 2FA code** for them (super admins are exempt).

| Concern | Location |
|---|---|
| Schema | `users.is_super_admin` (bool, core migration `2026_06_24_100001`, after `is_admin`) · data migration `2026_06_24_100002` flips `is_admin`+`is_super_admin` on email **`qasoom310@gmail.com`** (the owner) — **idempotent + password-safe** (never touches the password), no-op when the account is absent (tenants / tests) · `admin_otp_challenges` (`2026_06_24_100003`: user_id, action, `code_hash` SHA-256, `expires_at`, unique `(user_id,action)`). All **core** ⇒ deploy's core `migrate --force` applies them to Main automatically, and `workspaces:migrate` backfills every tenant |
| User model | `User::isSuperAdmin()` (guarded `getAttribute ?? false` so a not-yet-migrated DB reads false) + `is_super_admin` cast/fillable; `roleLabel()` → "Super administrator". `isAdmin()` unchanged (super admins pass it since their row has `is_admin=true`) |
| Owner-only powers | (1) **Dashboard system cards** (Installed apps / modules / Registered models) — wrapped in `@if($isSuperAdmin)` in `dashboard.blade.php` (`Dashboard::render` passes `isSuperAdmin`). (2) **Company "business type"** (`company.business_type`, a row in the **General** tab — moved out of its own "Business" tab 2026-07-13 via migration `2026_07_13_100001` flipping its `ir_config_parameter.group` to `General`, sort 5; the now-empty Business group drops its tab) — this is the teammate's `App\Erp\Business\{BusinessType,Features}` system (the type drives which apps/menus/models appear per database); this increment **moves it up to super-admin-only**. `SettingsPage::SUPER_ADMIN_KEYS = ['company.business_type']` + the new `canSee($key)` (super admin: all; regular admin: all **except** super-admin keys; non-admin: `NON_ADMIN_KEYS`) hide the row from regular admins in BOTH mount + save; the picker options come from the `BusinessType` enum, built only `isSuperAdmin()`. So a regular admin can no longer reshape the app surface — only the owner can. (3) **Promote/demote super admin** — `UserManager::toggleSuperAdmin()` is `abort_unless(actorIsSuperAdmin)`; promoting raises `is_admin` too; can't demote yourself or the **last** super admin. Buttons shown only to super admins in the user list |
| Admin 2FA (email OTP) | `App\Erp\Security\TwoFactorGate` (`required(User)` = admin AND not super admin; `challenge()` stores a 6-digit SHA-256 code + emails it **synchronously** via `App\Notifications\AdminActionOtp`; `verify()` checks + consumes, 10-min TTL) + `App\Models\AdminOtpChallenge`. Reusable Livewire trait `App\Livewire\Concerns\ConfirmsWithEmailOtp` (`requireOtp(action,args)` → true to run now (exempt) / false + opens modal; `submitOtp()` verifies then calls the host's `runConfirmedAction()`; shared `resources/views/partials/otp-modal.blade.php`). Gated actions (regular admin only): **UserManager** edit (`save` on existing) + `deleteUser`; **WorkspacesPage** `rename` + `deleteWorkspace` (OTP **after** the existing password check). Super admin runs all immediately. **Escalation guard:** a regular admin can't edit/delete a super admin (`UserManager::actorCanManage`), nor delete the last admin/super admin |
| Tests | `tests/Feature/SuperAdminTest.php` (10 — superset+OTP-exempt, dashboard cards SA-only, business-type SA-only + can't-be-written-by-regular-admin, regular-admin delete needs OTP, wrong OTP no-op, super admin deletes without OTP, regular admin can't manage a super admin, owner-only promote/demote, regular-admin DB rename needs OTP) |

The 2FA email needs prod SMTP configured (same `[[prod-mail-transport-environment-specific]]` as the daily report) — without it the code can't be delivered and a regular admin can't complete a gated action; the super admin (exempt) always can. To retune: change the channel in `AdminActionOtp::via()` (e.g. add WhatsApp), the TTL in `TwoFactorGate::TTL_MINUTES`, or the gated set by adding `requireOtp()` calls. To move a power between tiers: add/remove a key in `SettingsPage::SUPER_ADMIN_KEYS`, or wrap/unwrap a view block in `@if($isSuperAdmin)`.

**Per-app feature toggles (shipped 2026-06-24):**

Each app gets its own **"Settings" tab** to switch its sub-features on/off for
the active database — a **manual override layer on top of the business-type
preset** (the override wins). E.g. POS → toggle **Dine-in** (floors/tables/
kitchen/shisha) and **Recipes** independent of the chosen business type.

**Toggle set (expanded 2026-06-24):**
- **POS** (`pos`): **Floor plan & tables** (`Restaurant` → `pos.floor`/`pos.table`
  + the floor plan + table-service ordering) and **Kitchen & shisha display**
  (`Kitchen` → the KDS screens `/app/pos/kitchen/{station}` + the POS-home
  Kitchen/Shisha deep-link buttons via `PosHome::showStations`) — **split into two
  independent toggles 2026-08-08** (was one "Dine-in (tables, kitchen, shisha)"
  `Restaurant` feature); a shop can now run the kitchen/shisha screens without
  floors/tables or vice-versa. Both are in the **Café** preset; `KitchenDisplay::mount`
  `abort_unless(Feature::Kitchen)` so turning it off hides the screen by direct URL too,
  **Recipes & ingredients** (`Recipes` → `pos.ingredient` + the product-form
  recipe editor), **Condiments & add-ons** (`Condiments` → `pos.condiment` +
  the terminal cart "add-ons" button + the product-form condiment editor),
  **Customer discounts** (`CustomerDiscounts` → `pos.customer_discount` + the
  terminal "+ Customer discount" entry), **Damage / waste log** (`Damage` →
  `pos.damage`; **moved off the `Inventory` feature to its own** so it's
  independently toggleable — preset reach unchanged: Café/Retail/RetailCraft),
  **Barcode scanning (camera)** (`BarcodeScanning` → the terminal camera-scan
  button only; the search box + USB keyboard-wedge scanners are never gated),
  **Postpaid (kitchen first, pay later)** (`Postpaid` → `PosTerminal`: gates the
  auto-send-to-kitchen-on-add in `addProduct()` AND the dine-in green pay-gate
  in `canPay()`). **`Postpaid` is OPT-IN / default OFF** — the register is
  **prepaid** by default (pay first → the order fires to the kitchen on
  `PosOrderPaid` via `QueueLinesForKitchen`, which becomes the primary router in
  prepaid mode). It's the one feature in `Features::DEFAULT_OFF`: it does **not**
  fail open on an unconfigured database and is in **no** business-type preset, so
  it stays off until a restaurant turns it on in POS → Settings. (Chosen by the
  user 2026-06-24: prepaid is the default; postpaid is opt-in; in prepaid items
  reach the kitchen only after payment.)
- **Accounting** (`accounting`): **Chart of accounts** (`ChartOfAccounts` →
  `accounting.account`), **Journal entries** (`JournalEntries` →
  `accounting.journal_entry`).
- **Rent A Car** (`rental`): **Vehicle maintenance** (`rental.maintenance`),
  **Drivers** (`rental.driver`), **Rental quotations** (`rental.quotation`),
  **Replacement vehicles** (`rental.replacement`).
- **Limousine** (`limousine`): **Trip expenses** (`limousine.expense`),
  **Limousine quotations** (`limousine.quotation`), **Saved locations**
  (`limousine.location`).
- Single-purpose apps (**Inventory, Purchases, Contacts, WhatsApp, Projects**)
  have **no** `APP_FEATURES` entry → no Settings tab (they're on/off as a whole
  via the business type). Each terminal/product-form UI gate is a plain
  `@if (Features::enabled(Feature::X))` in the Blade; menu/tile/app-bar
  visibility flows through the existing `MODEL_FEATURE` map + `ModuleMenu`.
- **Each new sub-feature was added to every `BusinessType` preset where its
  parent app is on** (POS sub-features → Café/Retail/RetailCraft; Accounting →
  every type with `Accounting`; Rental → Rental/RentalLimousine; Limousine →
  Limousine/RentalLimousine; `General` = all cases). Without this a *configured*
  database would default the new feature OFF (presets are exact lists; only an
  unconfigured database fails open) — so a café would have silently lost
  condiments. The manual override then lets an admin turn any of them off.

| Concern | Location |
|---|---|
| Override store | `Features::overrides()` reads the per-workspace `features.overrides` setting (a JSON object `featureValue => bool`, written by `Features::setOverrides()` via `Setting::set`, cached + flushed). `Features::enabled()` now checks the override FIRST, then falls back to `presetEnabled()` (the business-type preset, fail-open when no type). So everything already gated by `Features::{enabled,moduleAllowed,modelAllowed}` (AppSwitcher, ModuleMenu, PosHome stations, product-form recipe editor) honours the toggle automatically — no new gates needed |
| Which toggles per app | `Features::APP_FEATURES` (module → list of sub-`Feature`s; the app-level master feature is intentionally excluded so you can't disable the app you're inside). `pos`/`accounting`/`rental`/`limousine` are populated (see toggle set above); extend the const to surface more. An app absent here has no Settings tab |
| UI | `App\Livewire\Pages\AppFeatureSettings` (`/app/{module}/settings`, route `app.feature-settings`, registered in core `routes/web.php` **before** `/app/{module}` — two-segment, no module defines it) + `resources/views/livewire/pages/app-feature-settings.blade.php` (iOS switches). **Admin-gated (any admin)** — `mount`/`save` re-`abort_unless(isAdmin)`. Feature-less / unknown slug → 404. `save()` writes all shown toggles as explicit overrides + logs `settings_updated` |
| Entry point | `AppSwitcher` adds a **"Settings"** row at the bottom of each app's dropdown when that app has feature toggles AND the viewer is an admin (`$settingsUrls[$module]`); the app-switcher view also opens a dropdown for a toggle-having app even if it has no model menu |
| Central settings exclusion | `SettingsPage::canSee()` returns false for any `features.*` key, so the internal `features.overrides` row (which `SettingManager::persist` would otherwise drop into the General group) never shows on the central settings page |
| Tests | `tests/Feature/AppFeatureSettingsTest.php` (12 — admin sees POS toggles, **Postpaid is opt-in / off by default even unconfigured + toggle turns it on**, dine-in OFF hides floor/table even in a café, dine-in ON shows it in retail, **condiments OFF hides `pos.condiment` (sibling unaffected)**, **other apps (accounting/rental/limousine) expose a tab while single-purpose apps don't**, **journal-entries OFF hides only that model**, **rental/limousine sub-features gate their own models**, non-admin 403, feature-less app 404s, app-switcher links Settings for admins only, overrides key hidden from central settings). `BusinessTypeTest` still covers the preset layer; `PosDamageTest` pins damage's preset reach after the `Inventory`→`Damage` re-route |

Decisions (chosen by the user): toggles live **in each app** (per-app tab), **feature-level** granularity, editable by **any admin**, and they **override** the business-type preset. To add a toggle to another app: add its `Feature`(s) to `Features::APP_FEATURES[<module>]` (mapping the feature to its models/menus in `MODULE_FEATURE`/`MODEL_FEATURE` as needed). NOTE: an override, once saved, pins that feature regardless of a later business-type change — there's no "revert to preset" button yet (a possible follow-up).

**Activity log — audit trail (shipped 2026-06-21):**

Admin-only system audit trail. The topbar **bell was replaced by a
clipboard-list "Activity log" icon** (admin-only; `route('activity')`) opening
a professional table of who did what, when.

| Concern | Location |
|---|---|
| Schema | `activity_logs` (core migration `2026_06_21_100001`) — `user_id` (logical ref, survives user deletion), `user_name` + `user_is_admin` (**snapshots** so the row reads right after rename/delete), `action`(40), `subject`, `description`, `ip_address`, `created_at` (immutable — `$timestamps=false`). Core table ⇒ per-database (each workspace has its own; logs scoped to the DB they happened in) |
| Model | `App\Models\ActivityLog` — `LABELS` + `COLORS` maps keyed by action code; `actionLabel()` (localised) / `actionColor()` (Tailwind tones) |
| Logger | `App\Erp\Activity\ActivityLogger` (singleton) — `log(action, subject?, description?, actor?)`. Snapshots `Auth::user()` (or an explicit actor) + `request()->ip()`. **Guarded by `Schema::hasTable` + try/catch** so auditing NEVER breaks the audited action (or a tenant DB lacking the table) |
| Captured | Auth login/logout/failed (`Event::listen` in `AppServiceProvider::registerActivityListeners()`); engine `FormView` create (`created`) + update (`updated`, **once per editing session** via `public bool $activityLogged` — auto-save fires per keystroke); engine `ListView::bulkDelete` (`deleted`, N records); `UserManager` create/update/delete (`user_*`); `SettingsPage::save` (`settings_updated`, changed keys). POS-sale / purchase-confirm events are a possible follow-up |
| Page | `App\Livewire\Pages\ActivityLog` (`/activity`, admin-only `mount`) + `resources/views/livewire/pages/activity-log.blade.php` — search (user/subject/description/IP) + action filter + pagination (`vendor.pagination.compact`); columns Date&time (12-hour + relative) / User (role badge) / Action (colour badge) / Details / IP. `render()` degrades to an empty state if `activity_logs` is missing (a tenant DB not yet migrated) instead of 500ing |
| Tenant backfill | `App\Console\Commands\MigrateWorkspaces` (`workspaces:migrate`) runs, against every tenant SQLite file (resilient per-workspace try/catch): core `migrate --force` **AND** — per module installed in that tenant's `ir_module` — its `migrate --path=<manifest->migrationsPath()> --realpath --force` + `module:resync <slug>` (extended 2026-06-22). **Added to `deploy.yml`** right after the Main `migrate`. Fixes the general "tenants miss migrations added after provisioning" gap for **both** core (e.g. `activity_logs`) **and module** migrations — without this a tenant's `pos_*`/etc. schema froze at provision time and screens using a later column (e.g. POS `pos_x`/`pos_y`, translatable floor name) 500'd only inside tenants while Main was fine. Resync also lands later `irModelDefinition()` arch tweaks (translatable pills, new fields) in tenants |
| Tests | `tests/Feature/ActivityLogTest.php` (6 — logger snapshot, login event audited, page admin-only, list + action filter, user-create audited, settings-save audited) |

**In-app database backups — daily snapshots + restore (shipped 2026-08-09):**

A "Hostinger-style" backup built into the ERP: a **daily whole-database snapshot**,
kept **14 days**, restorable from the app — so any change (add / edit / delete, to
any table) can be undone by rolling the **whole database** back to an earlier day
(a point-in-time rollback, **not** a per-record undo). **Per database** — Main
(MySQL) and each tenant workspace (SQLite) each snapshot themselves.

| Concern | Location |
|---|---|
| Engine | `App\Erp\Backup\DatabaseBackup` — engine-agnostic (query-builder, works for MySQL + SQLite). `snapshot()` dumps every business table to a **gzipped JSON file** under `storage/app/backups/<db-key>/<Y-m-d_His>_<rand>.json.gz` (`db-key` = `sha1` of the connection's database name, so tenants can't see each other's). `restore($path)` empties + repopulates each captured table inside ONE transaction with `Schema::disableForeignKeyConstraints()` (disabled BEFORE the transaction — SQLite ignores the PRAGMA inside one); columns added by later migrations default, dropped ones are ignored (`array_intersect_key` vs `getColumnListing`). `list()`/`owns()`/`delete()`/`purge()`. **`EXCLUDED`** infra tables (`migrations`, `sessions`, `cache*`, `jobs*`, `failed_jobs`, `password_reset_tokens`) are never dumped/restored — excluding `sessions` means a restore doesn't log the admin out. **Backups are FILES, not a DB table**, so a restore never disturbs the backup catalogue |
| Daily backup | `App\Console\Commands\BackupDatabases` (`backups:run`) — snapshots EVERY database (Main + each tenant via `WorkspaceManager::withTenant`, like `workspaces:migrate`) then `purge()`s past-retention files. Scheduled **`dailyAt('00:05')`** (just after midnight) in `routes/console.php`. **Depends on the hPanel `schedule:run` cron** (`[[hostinger-cron-needed-for-queue-worker]]`) |
| UI | Lives **inside the Activity Log page** (`App\Livewire\Pages\ActivityLog`, `/activity`, **admin-only** — NOT a separate page, per the user's request) as a "Backups" panel above the log: lists the CURRENT database's snapshots (date / size), **Back up now**, **Download**, **Delete**, and **Restore**. Restore is **password-gated** (`Hash::check` the admin's own password, mirroring workspace delete) because it overwrites every table; a stern amber warning + confirm modal. Both actions audited (`activity_logs` `backup_created` / `backup_restored`) — and, being audited, they show up in the same log right below |
| Download | `App\Http\Controllers\BackupDownloadController` (GET `/app/backups/download?file=`, route `backups.download`, admin-only) — streams a snapshot; `owns()`-gated so no cross-workspace path access. Registered before the `/app/{module}` wildcard |
| Deploy | `storage/app/backups/` added to `deploy.yml`'s rsync `--exclude` (like `storage/app/workspaces/`) — else `--delete` wipes every snapshot on each push (`[[rsync-delete-wipes-user-uploads]]`). The command is scheduled (no deploy.yml step needed) |
| Tests | `tests/Feature/DatabaseBackupTest.php` (8 — snapshot creates a listed file, restore rolls back a delete / an add / an edit, purge honours the window, the Activity Log page backs-up + restores with the password, wrong password refused, admin-only) |

**Scope / caveats:** restore is a **full rollback** (everything after the snapshot is lost) — by design, like Hostinger. Snapshots are **logical JSON dumps** (fine for small POS/retail data; a very large table loads into memory on snapshot/restore). Schema **rolls forward** (a later migration's new table isn't in an old snapshot and is left as-is; old rows insert only into columns that still exist). To retune retention change `DatabaseBackup::RETENTION_DAYS`.

**Dark mode / per-user theme (shipped 2026-07-13):**

A **light / dark / system** appearance picker in **Settings → General** (top of
the tab, a segmented sun/moon/monitor control). It's a **per-user** preference
(like language) — every user, not just admins, can set their own; applied
**live, no reload**.

| Concern | Location |
|---|---|
| Preference | `users.theme` (`nullable(10)` — `light\|dark\|system`; null = system), core migration `2026_07_13_110001` (Main via `migrate --force`, tenants via `workspaces:migrate`). `User` fillable + `@property`. |
| Mechanism | An inline script in `components/layouts/app.blade.php` `<head>` (BEFORE `@vite`, so no light-flash) reads `<html data-theme="{{ auth theme ?: 'system' }}">`, resolves `system` via `matchMedia('(prefers-color-scheme: dark)')`, and toggles a **`.dark` class on `<html>`**. Exposed as `window.applyTheme(pref)`; tracks OS changes while in `system`. `tailwind.config.js` set `darkMode: 'class'`. **wire:navigate persistence (fixed 2026-07-13):** SPA navigation morphs `<html>` back to the server class (dropping a JS-added `.dark`) and does NOT re-run head scripts → navigating away reverted to light. Two-part fix: (1) an **explicit** dark choice renders `.dark` on `<html>` **server-side** (`$htmlClass = 'h-full'.($pref==='dark'?' dark':'')`) so the morph keeps it natively (no flash); (2) the head script re-applies on the `livewire:navigated` document event (covers `system` mode too, which can't be server-resolved). Pinned by `ThemeSettingTest::test_explicit_dark_renders_the_class_server_side_and_reapplies_on_navigation`. |
| Styling | **NOT `dark:` variants across the views.** Instead an **additive `.dark` override block at the end of `resources/css/app.css`** remaps the neutral utility CLASSES the whole app is built on — `bg-white`/`bg-chrome-*` → dark surfaces, `text-chrome-*` → light, `border/ring/divide-chrome-*`, `.o-input` + the forms-plugin inputs. **Light mode is byte-identical (every rule is scoped under `.dark`, zero regression).** Deliberately untouched: `text-white` (stays white on coloured badges) and `bg-chrome-900/40..70` modal scrims (kept a dark scrim). **Brand-yellow overload fix:** `bg-primary-400/500` fills stay yellow, so their label text is pinned dark via `.dark .bg-primary-400{.text-chrome-900,.text-chrome-800,.text-chrome-700}` (same-element AND descendant — covers the topbar app-bar's active `text-chrome-900` + inactive `text-chrome-800` links) + `.dark .o-btn-primary` — no Blade edits. **Gotcha:** the app-bar dropdown menu is a `bg-white` panel that's a DOM child of the yellow `<header>`, so a naive brand-descendant pin would force its menu text dark-on-dark; a higher-specificity `.dark .bg-primary-400 .bg-white .text-chrome-*` re-asserts light there (scoped to "white panel inside a brand fill" so it doesn't lighten a yellow KPI tile that sits inside a white card). Accent text (`text-primary-600/700`) is brightened to a legible gold. **Semantic colours (2026-07-13 pass):** status text (`text-{red,emerald,amber,green,sky,blue,indigo,violet,rose,orange,…}-500..900`) is brightened to its soft `-400` shade AND its paired tint background (`bg-{family}-50/100`) darkens to a translucent wash of the family colour, so status text/banners flip together (never dark-on-dark); off-brand greys (`text-{gray,slate,zinc,neutral,stone}-*`) + `text-black` map to the light chrome tones. `text-white` on solid coloured fills is left alone. |
| Control | `SettingsPage::$theme` + `setTheme(light\|dark\|system)` — validates, writes `users.theme`, dispatches `theme-changed` (value); the layout `<body>`'s `x-on:theme-changed.window` calls `applyTheme` for instant apply. Rendered at the top of the General panel in `settings.blade.php` (only when the General group is shown; every role sees General via `company.language`). |
| Tests | `tests/Feature/ThemeSettingTest.php` (4 — persist + dispatch, mount reflects saved, invalid ignored, non-admin may set their own). AR keys: Appearance / Light / Dark / System / the help line. |

**Accent (brand) colour picker (shipped 2026-07-13):** beside the theme control in Settings → General, a row of colour **swatches** (Yellow default · Amber · Orange · Red · Pink · Violet · Sky · Emerald) recolours the whole app's accent. Per-user (`users.accent`, migration `2026_07_13_120001`, null = yellow). The `primary` Tailwind colour was converted to **`rgb(var(--primary-N) / <alpha-value>)`** — `:root` in `app.css` holds the default yellow ramp as RGB triplets, and each `[data-accent="…"]` (rendered on `<html>` from the pref) swaps the ramp, re-tinting every `bg/text/border/ring-primary-*` utility at once (no view edits, no light regression — `:root` == the old hexes). Applied **live** by `SettingsPage::setAccent()` dispatching `accent-changed`, whose layout hook sets `document.documentElement.setAttribute('data-accent', v)`; wire:navigate keeps the server-rendered attribute so it's morph-safe with no FOUC (unlike the `.dark` class, no JS re-apply needed). Dark-mode accent text/tints reference the accent vars (`rgb(var(--primary-300))`, `rgb(var(--primary-500)/…)`) so they follow the chosen colour instead of a fixed gold. Each ramp keeps the brand shape (bright `400` fill + `text-chrome-900` dark ink; dark `600+` accent text on white). Tests: `ThemeSettingTest` (accent persists+dispatches, invalid ignored, renders on `<html>`). AR keys: the colour names + the help line.

Scope note: the **guest/login page stays light** (pre-auth, no user row) — dark mode is the authenticated app only. The **accent** likewise applies to the authenticated app only (login/receipts stay brand yellow). The brand **logo/favicon SVGs are not recoloured** by the accent (they're fixed assets). Coverage is broad (neutral surfaces/text/inputs/chrome across every screen), but bright *tinted* banners (`bg-emerald-50` etc.) and any bespoke non-chrome colours aren't remapped — refine per-screen if needed. To retune the palette, edit the values in the `.dark` block; to add a spot that needs hand-tuning, use a `dark:` Tailwind variant (now enabled).

**Forgotten password — self-service reset by email (shipped 2026-09-05):**

A **"Forgot your password?"** link on the login card (beside Remember me) so a
locked-out user gets back in without an admin. Email a link → set a new
password → sign in. Uses Laravel's stock password broker (the
`password_reset_tokens` table already ships in `0001_01_01_000000_create_users_table`
and `config/auth.php` already declares the `users` broker — no migration, no config).

| Concern | Location |
|---|---|
| Request screen | `App\Livewire\Auth\ForgotPassword` (`/forgot-password`, route `password.request`, **guest** group) + `resources/views/livewire/auth/forgot-password.blade.php`. Takes an email, calls `Password::broker()->sendResetLink()`, then swaps the form for a "Check your email" notice (`$sent`) |
| Reset screen | `App\Livewire\Auth\ResetPassword` (`/reset-password/{token}`, route `password.reset`, **guest** group) + `reset-password.blade.php`. `mount(string $token, ?string $email)`; `Password::broker()->reset()` rehashes and rotates `remember_token`, fires `PasswordReset`, flashes to `session('status')` and redirects to `/login` (the login view renders that flash in an emerald pill) |
| Mail | `App\Notifications\ResetPasswordLink` — **deliberately NOT queued** (a person is waiting at the sign-in screen, and the queue only drains when the host's minute cron fires — same reasoning as `TwoFactorGate`'s OTP; memory `[[hostinger-cron-needed-for-queue-worker]]`). `User::sendPasswordResetNotification()` overrides Laravel's stock English mail with this translated one. **Sends through whatever `.env` configures** — prod is Hostinger SMTP (`[[prod-mail-transport-environment-specific]]`); with no SMTP the link silently never arrives |

**Why the workspace layer needs no special handling here.** Both routes are in
the **guest** group, and `SetActiveWorkspace` short-circuits an unauthenticated
request (`if (! Auth::check()) return $next($request);`) — so a reset always
reads and writes **Main**, the canonical identity store, which is the same row
`Login` authenticates against. A workspace-created account's real row lives in
its tenant DB, but only its **Main login shell** holds the password that signs
in, so rewriting Main is both correct and sufficient. This is the opposite of
the email-verification route's problem (that one is open to guests *and* needed
a `ws` parameter because it edits a per-database record).

Deliberate choices, don't "fix" them:

- **The reply never says whether the address exists.** A hit and a miss both
  render "Check your email"; only a genuine throttle (`RESET_THROTTLED`) or a
  malformed address shows an error. A different message on a hit would turn the
  box into a way of asking "does this person have an account here?".
- **Two throttles.** The broker's own `'throttle' => 60` (config/auth.php) stops
  one mailbox being flooded; a `RateLimiter` keyed on the visitor's IP (5 per
  5 minutes) stops one visitor working through a list of addresses.
- **`$token` is `#[Locked]`** on `ResetPassword` — it identifies the account
  being rewritten, and Livewire lets the browser set any unlocked public property
  (the engine-hardening rule from 2026-08-24). `$email` is deliberately NOT
  locked: it isn't secret and the token is bound to it, so typing a different
  one can never reset anybody else — and it has to be typeable when a link
  arrives without it.
- **The email rides in the QUERY STRING, and Livewire does not pass query
  parameters to `mount()`** — only route segments. `mount()` reads
  `request()->query('email')` explicitly. The first cut didn't, so every mailed
  link opened a screen that refused with "The email field is required" (found
  by the owner on 2026-09-07). When the link carries no email the screen shows
  an Email box instead of failing. Pinned by
  `test_the_emailed_link_fills_the_email_in_so_nobody_types_it`, which hits the
  real GET URL — `Livewire::test()` with mount params cannot reproduce it.
- **Printable-ASCII only** on the new password (`regex:/^[\x20-\x7E]*$/` +
  the `beforeinput` filter and `<x-password-ascii-notice />` from the profile
  screen), so a password stays typeable on a keyboard set to any language.
- **Username-only staff (POS cashiers) have no email**, so they cannot use this
  — an admin still resets them from Settings → Users, or via
  `EnsureStaffUserCommand`. That is not a bug; it is what a null email means.

Tests: `tests/Feature/PasswordResetTest.php` (16 — link on the login screen,
send emails the account, unknown address looks identical, malformed address
refused, the emailed URL carries token + email, reset changes the password and
kills the old one, a token is single-use, a forged token changes nothing,
confirmation + length enforced, non-ASCII refused, the link's email is read from
the real URL, a bare link asks for it, `#[Locked]` token, IP rate limit,
signed-in users bounced). 20 `lang/ar.json` keys added.

**Idle sign-out on laptops/desktops — 1 hour, phones exempt (shipped 2026-09-17; 15 min → 1 hour 2026-10-03 at the owner's request):**

A laptop or desktop with no key press, click, scroll or mouse movement for 1 hour
minutes is signed out. Phones and tablets are never signed out for inactivity.

| Concern | Location |
|---|---|
| Timer | Inline head script in `components/layouts/app.blade.php` (global `window.__erpIdleLogout` guard — wire:navigate doesn't re-run head scripts). **Desktop = `(pointer: fine)` AND `(hover: hover)`**; anything else (phone, tablet) returns immediately. A touchscreen laptop still counts as a desktop (its primary pointer is the trackpad) |
| Cross-tab | Last activity lives in `localStorage['erp.lastActivity']` (writes throttled to one per 5s), so work in one tab keeps the others alive. A tab whose key is removed by another tab's sign-out reloads to the login screen |
| Sleep / closed browser | The check compares timestamps (every 30s + on focus / visibilitychange), not a countdown, so a laptop that slept past the hour signs out the moment it wakes, and a stored stale time is honoured on page load. **The guest layout removes the key**, so a fresh sign-in never inherits a stale time and bounces out |
| Exemption | `/app/pos/kitchen/*` (KDS) never times out and counts as activity — a kitchen screen is watched, not touched. Consequence: a desktop with a KDS tab open keeps the whole session alive |
| Server | `POST /logout/idle` (`logout.idle`, `auth`) — same as `/logout` but flags the request, so the Logout listener audits `logout_idle` ("Signed out (inactive)") instead of `logout`, and flashes the reason onto the login screen |
| Limits | Browser-enforced: only the browser can see a mouse move (Livewire polls would fool a server-side check). With JS disabled or the tab killed, the normal `SESSION_LIFETIME` (720 min) still applies |
| Tests | `tests/Feature/IdleLogoutTest.php` (5) |

**New users get a generated password by email — no password field (shipped 2026-09-07):**

The Settings → Users form (every database) no longer has a Password box. On
**create**, the ERP generates a strong password, provisions the account with it,
and **emails the person** their sign-in details plus the way to pick their own.
On **edit**, passwords are never touched — the person changes theirs from
"Forgot your password?" on the sign-in screen (the self-service reset above).

| Concern | Location |
|---|---|
| Generation | `UserManager::generatePassword()` → `Str::password(16, symbols: false)` — 16 letters + digits (~95 bits), **no symbols** so it survives being copied out of an email, and it satisfies the printable-ASCII rule the profile/reset screens enforce. Never shown to the admin |
| Mail | `App\Notifications\WelcomeCredentials(name, email, password)` — subject "Your {company} account" (`Setting::get('company.name')`, falling back to `app.name`), the email + password, a **Sign in** button (`route('login')`), and the **forgot-password URL** for choosing their own. Sent **on demand** (`Notification::route('mail', $email)`) because the row may have just been written into a *different* database from the one the admin is in — only the address matters. **Synchronous**, same reasoning as the reset link and the OTP |
| Wiring | `UserManager::welcome()` runs AFTER provisioning in both create paths (Main `save()` and workspace `writeWorkspaceUser()`) and returns the flash text. The form is `reset()` before it runs, so **capture `$name` first** — the greeting read an empty name until that was pinned (`test_creating_a_user_emails_them_a_generated_password_that_signs_in` asserts `$mail->name`) |
| Failure | A mail failure (SMTP down) is caught + `report()`ed and the flash says so: *"User created, but the email could not be sent. Ask them to use 'Forgot your password?'"*. The account is **not** rolled back — it already exists across the chosen databases, and the reset flow is the recovery path |

Why the "choose your own" link is the **forgot-password page, not a reset
token**: a token expires in 60 minutes and a welcome mail is routinely opened
days later. The person asks for a fresh link when they are ready.

Consequences to keep in mind:

- **`EnsureStaffUserCommand` (CLI) still takes a typed password** — it is the
  break-glass path when the Users screen can't be used, and it prints nothing
  by email. Unchanged on purpose.
- **`UserProvisioner` signatures are unchanged** (`provision(..., string $plainPassword, ...)`,
  `provisionLocked(..., ?string $plainPassword, ...)`); only the caller changed
  from "what the admin typed" to "what we generated" (null on edit = keep).
- The blade note under the two fields says what happens (create vs edit
  wording). `sm:grid-cols-3` → `sm:grid-cols-2`.
- Tests: `UserManagerTest` — 22 `->set('password', …)` calls removed (+4 more in
  `ActivityLogTest` / `WorkspaceLockTest`, which CI caught first), the
  `password => min` assertion dropped, + 3 new (generated password is emailed
  on demand to the right address with the right name and actually signs in;
  the mail carries email/password/forgot-link/login action; editing never
  changes the hash and sends no second mail). 11 `lang/ar.json` keys.

**A quotation the customer gets WITHOUT a total (shipped 2026-09-30):**

The owner asked for it off the quotations list: a quote for several journeys
priced one by one is often a choice, not a shopping list, and a Total at the
bottom tells the customer they are buying all of it. So the same sheet can go
out with the Subtotal/Discount/VAT/Total box left off. **The trips and their
per-leg Rate/Amount columns stay** — what goes is only the figure that reads as
a commitment to the whole list.

| Concern | Location |
|---|---|
| Flag | `QuotationPdf::viewData($quote, bool $withoutTotal = false)` → `$withoutTotal` in the view data; `render()`, `renderMany()` and `filename()` all take it |
| Sheet | `partials/quotation-body.blade.php` wraps the summary table in `@unless ($withoutTotal)` and swaps the closing note for a "Rates are per the table above" line. **Defaulted in the partial** (`$withoutTotal = $withoutTotal ?? false`) so the batch PDF and any older caller still render |
| Download | `LimoQuotationController` reads `?without_total=1`. Its signature gained `Request $request` FIRST — `LimoQuotationActionsTest` invokes the controller directly (module routes only mount on the boot after install), so that call had to be updated too |
| Filename | `quotation-QT-01695-no-total.pdf` vs `quotation-QT-01695.pdf`. Named apart because both copies of one quote in a downloads folder have to be tellable without opening them |
| Send | `Quotations::$sendWithoutTotal` (tick box in the Send dialog), cleared in BOTH `openSend()` and `closeSend()` — a quote that went out total-free to one customer must not silently do the same for the next |
| Mail | `QuotationMail::$withoutTotal` reaches the BODY as well as the attachment. A total-free PDF under an e-mail that summarises the total hands the figure over anyway, so `quotation-email.blade.php` prints "The rates for each journey are in the attached quotation." instead |
| Tests | `tests/Feature/LimoQuotationWithoutTotalTest.php` (11) — including one that RENDERS both sheets, so a blade that forgot the flag cannot pass |

The total is still computed either way and simply not printed, so the two
copies can never disagree about the trips behind them.

**Vehicle Type on a quotation reads BOTH car fields (fixed 2026-09-30):** the
owner wrote a quote, chose the car on it, and the Vehicle Type column printed
empty. Not a mistake of theirs — the form offers TWO ways to name the car, a
per-leg free-text "Car details" (`vehicle_details`) and a pick from the fleet
(`car_id`, whose label is snapshotted into `vehicle`), and
`QuotationPdf::legRow()` read only the first. `vehicleLabel()` now falls back
`vehicle_details` -> `vehicle` -> the quotation's own `car_type` header field.
A picked car is stored as "Ford Expedition · 363899 · White", so only the part
before the first `·` is printed: the column is headed Vehicle Type, a quote is
not a dispatch, and no customer chooses by plate. A quote naming no car
anywhere still prints no column at all rather than an empty one.

**Deliberately NOT the rule the invoices use.** `LimoInvoicePdf` and
`LimoCombinedInvoicePdf` go on reading `vehicle_details` alone, because there
`vehicle` may be the car the QUEUE assigned at dispatch and a customer who
agreed to an SUV must not be billed by whichever plate happened to run it. A
quotation has no dispatch behind it, so its `vehicle` can only be the car the
office chose on the quote itself.

**Quotation lists: row checkboxes narrow the downloads (shipped 2026-09-07):**

Both bespoke quotation lists (Limousine `/app/limousine/quotation`, Rental
`/app/rental/quotation`) gained a checkbox column. Tick rows and **Copy / CSV /
Excel / PDF / Print act on just those**; tick nothing and they act on the whole
tab, exactly as before. Asked for by the owner as "the checkboxes like the one
in Customers" - note the engine list's checkboxes are for bulk DELETE and its
exports still cover the whole filtered set; this is the first list whose
exports honour a selection.

| Concern | Location |
|---|---|
| Selection state | `App\Livewire\Concerns\SelectsListRows` (shared trait): `$selected` (ids; the browser sends strings), `$selectPage` (header box -> `currentPageIds()`), `updatedSelected()` drops the header's "all" claim, `clearSelection()`, `selectedIdsParam()` (comma-joined for links), `isSelected()`. Host implements `currentPageIds()` and calls `clearSelection()` from `updatedTab()` - a tick on one tab is not a tick on another |
| Hosts | `Modules\Limousine\Livewire\Quotations` + `Modules\Rental\Livewire\Quotations`: `currentPageIds()` re-runs the Rows service query for `getPage()` x 20, same order as the list |
| Export scoping | `LimoQuotationRows::all(string $tab, array $ids = [])` / `RentalQuotationRows::all(...)` add `whereKey($ids)` when given; both `*QuotationExportController`s parse `?ids=3,7,12` via `ids()` (junk/empty = whole tab). Blades add `'ids' => $this->selectedIdsParam()` to the export query string |
| Copy | `copyTableById()` in `resources/js/app.js`: rows with `data-row-selected="1"` narrow the copy (thead kept); cells with `data-copy-skip` (the checkbox column) never go. Server-rendered attributes, so a `wire:model.live` tick is accurate on the next copy |
| Rental gotcha | the Rental row is `onclick="window.location=..."` (the whole row opens the quote), so its checkbox cell carries `onclick="event.stopPropagation()"` |
| UI | header checkbox (`Select all on this page`), per-row checkbox, and in the export bar a ":count selected / Clear selection" pill, or the hint "Tick rows to export only those." `colspan` 6 -> 7. 3 `lang/ar.json` keys |
| Tests | `LimoBespokeExportTest::{test_quotation_export_narrows_to_the_ticked_rows, test_the_header_box_ticks_the_page_and_the_download_links_carry_the_ids}`, `RentalBespokeExportTest::{test_quotation_csv_narrows_to_the_ticked_rows, test_the_header_box_ticks_the_page_and_the_download_links_carry_the_ids}` |

**To give another bespoke list the same:** `use SelectsListRows`, implement
`currentPageIds()`, call `clearSelection()` on filter change, add `'ids'` to
its export query, give its Rows service an `$ids` parameter and its export
controller the `ids()` parser, and mark the checkbox cells `data-copy-skip` +
rows `data-row-selected`.

**Rental receipts got the same (2026-09-07)** — `/app/rental/receipt`, asked for
by the owner off the Receipts screen. Its scope is a **search string**, not a
tab, so `clearSelection()` hangs off `updatedSearch()`: a tick made against one
search is not a tick against the next. While wiring it, `Receipts::render()` was
switched from its **own hand-copied query** to `RentalReceiptRows::query()` —
the two were identical, but the header checkbox reads the Rows service, so
letting them drift would make "select all on this page" select something other
than the page. **A list with a Rows service should render from it**; that is why
it exists.

**Invoices got the same (shipped 2026-09-08)** — Rental `/app/rental/invoice`
gained the full `SelectsListRows` treatment (header checkbox, per-row
checkbox, `data-row-selected`/`data-copy-skip`, `ids` on every export link) —
it had no selection at all before. **Limousine `/app/limousine/invoice`
already had a checkbox column** (it exists to pick invoices for a **combined
bill**, via its own hand-rolled `$selected`/`selectAll()` — not the
`SelectsListRows` trait, since "select all" there means every filtered row,
not just the current page), but ticking a row did **not** narrow Copy/CSV/
Excel/PDF/Print — those always covered the whole tab. Wired the existing
`$selected` into the export query (`'ids' => implode(',', $selected)`) and
`LimoInvoiceRows::all()` (new optional `array $ids = []`, `whereKey($ids)`
when given) + `LimoInvoiceExportController::ids()` (same parser as the
Quotation controllers), and added `data-row-selected`/`data-copy-skip` to its
table so Copy narrows the same way. **Two different selection mechanisms
narrowing exports is fine** — the export controller only cares about the
`ids` query param, not how a screen produced it.

**Maintenance got the same (shipped 2026-09-12)** — Rental `/app/rental/maintenance`
gained the full `SelectsListRows` treatment. Its scope is the status **tab**
(All/Pending/Approved/In progress/Done), so `clearSelection()` hangs off
`updatedTab()` — a tick made against one tab is not a tick against another,
same rule as the receipts screen's search box. `MaintenanceRecords::render()`
was also switched from its own hand-copied query to
`RentalMaintenanceRows::query()` (the Rows service already existed and the
export controller already used it, but the screen didn't) — same "a list with
a Rows service should render from it" reasoning as the receipts fix.
`RentalMaintenanceRows::all()` gained the `array $ids = []` parameter and
`RentalMaintenanceExportController` gained the same `ids()` parser as every
other bespoke list. Test: `RentalBespokeExportTest` (+4 — CSV narrows to a
ticked row, ticking nothing still exports the whole list, the header
checkbox ticks the page and the links carry the ids, changing the tab drops
a tick made against the old one).

**Car replacements got the same (shipped 2026-09-12)** — Rental
`/app/rental/replacement` gained the full `SelectsListRows` treatment,
scoped to the status **tab** (All/Active/Closed) exactly like Maintenance —
`clearSelection()` hangs off `updatedTab()`. `Replacements::render()` was
also switched from its own hand-copied query to `RentalReplacementRows::query()`
(the Rows service already existed and the export controller already used it,
but the screen didn't — same gap Maintenance and Receipts had before this).
`RentalReplacementRows::all()` gained the `array $ids = []` parameter and
`RentalReplacementExportController` gained the same `ids()` parser as every
other bespoke list. The row is `onclick="window.location=..."` (the whole row
opens the record), so its checkbox cell carries `event.stopPropagation()`,
same as every other list with this treatment. Test: `RentalBespokeExportTest`
(+4 — same shape as Maintenance's).

**Rental orders got the same, plus bulk Cancel / Delete (shipped 2026-09-29)** —
`/app/rental/order` gained the `SelectsListRows` treatment (selection cleared on
tab/from/to/search change; `RentalOrderRows::all(..., $ids)` +
`RentalOrderExportController::ids()`). When rows are ticked two bulk buttons
appear: **Cancel selected** (`rental.order` Write — `cancelOrder()` per open
order, frees the car, closed/cancelled ones skipped) and **Delete selected**
(`rental.order` **Unlink** — deletes for good, but **never an order with an
invoice or any `advance_amount` received**; those are skipped and named, cancel
them instead). Delete cancels first (frees the car), unlinks any
`rental_web_bookings.rental_order_id`, and logs one `deleted` "Rental orders"
activity entry with the references. The result shows in an inline
`$bulkMessage` banner (a session flash would only show after a reload). Test:
`RentalOrderBulkActionsTest` (6).

**Orders import reads the old system's Active Orders export (fixed 2026-09-29).**
Uploading that CSV on `/app/rental/order` 500ed: `OrderImporter` read
`$row[$cols['pickup']]` unguarded and the file has no Pick-up column. The
importer now also understands `RA#` (kept as the reference, and the dedupe key —
an RA# already on file is SKIPPED, never rewritten; `rental:fix-order-figures`
is the deliberate figure refresh), `Customer` = "Name , CPR , phone" (matched by
CPR, then phone ending, then name), `Vehicle` = "plate - model" (matched by
plate), `Hire Period` = "31-Aug-26 12:42 to 17-Sep-26" (explicit `d-M-y` formats
+ `hired_time`), Amount/VAT/Total/Receipt/Balance/Deposit ("Extra" not written —
outside the old total). No Status column → active when still owing or not yet
due back, else closed. Every row is try/caught (logged, counted as `failed`) so
one bad row never fails the upload. Tests: `RentalOrderImportTest` (+3).

**…and that file IS the active list (fixed 2026-10-03).** The owner imported
it and saw 3 active orders instead of 6: all six RA#s were already on file, and
the historical migration had brought RA1815 / RA1794 / RA1623 in as **closed**,
so the "RA# on file → skip" rule changed nothing. Now a file in the old
system's shape (`RA#` + `Hire Period`, no Status column) is treated as the
active list: new rows land **active** regardless of figures (a paid order past
its return date is still a car that is out), and an order on file as closed is
**reopened** — state only, money untouched — unless it was closed in the ERP
(`returned_at` set) or cancelled. Active orders also flip an available/reserved
car to rented. Result gains a `reopened` count. Tests: `RentalOrderImportTest` (+2).

**Rental quotation gets a real document, matching the invoice (shipped
2026-09-12):** `/app/rental/quotation` used to have no per-document PDF at
all — its list's "PDF"/"Print" buttons only ever rendered the generic tabular
report, and there was no download icon per row. `Modules\Rental\Services\RentalQuotationPdf`
+ `quotation-pdf.blade.php` / `quotations-batch-pdf.blade.php` /
`partials/quotation-body.blade.php` now give it the SAME reference-template
design as `RentalInvoicePdf` (gold band, title + meta cells, From/Quotation-for
blocks, ruled item table, Subtotal/Discount/VAT/Total box) — item-table
columns are the ones the owner pointed at on the old system's printed
quotation: No. / Service / Vehicle / From / To / Days / Rate / Amount. A
rental quotation prices exactly ONE vehicle for ONE period (no legs/lines
relation, unlike the Limousine quotation), so there is at most a single item
row, or an honest "Rental services" fallback line when no vehicle is chosen
yet. **VAT is computed for display only** — `RentalQuotation` has no stored
`vat_rate`/`vat_amount` columns (those only exist on `RentalOrder`, once a
quote converts) — at `RentalOrder::DEFAULT_VAT_RATE`, so a quotation previews
the same tax an accepted order would actually charge without a schema change.
A **Requirements** note prints under the summary whenever the quotation
carries a `deposit`, naming that figure dynamically (never hardcoded) in the
same sentence the old system's printed quotation used. `RentalQuotationController`
(new, `/app/rental/quotation/{id}/download`) serves a single quotation, mirroring
`RentalInvoiceController`; the quotations list gained the same download icon
+ Actions column as the invoices list. `RentalQuotationExportController::pdf()`
now branches exactly like the invoice's: ticked rows download their own
document(s) (one page each, batched into one PDF past a single row), nothing
ticked still exports the plain tabular report. Tests:
`RentalQuotationDocumentTest` (figures multiply out, VAT is computed off the
discounted subtotal, no-vehicle fallback, deposit-driven Requirements note)
+ `RentalBespokeExportTest` (+3 — one/several ticked rows download the real
document(s), nothing ticked still exports the tabular report). Also added to
`DocumentFooterTest::DOCUMENTS` alongside the (previously missing) Rental
invoice/invoices-batch PDFs.

**Rental receipt gets a real document too (shipped 2026-09-12):** unlike the
invoice and quotation, `/app/rental/receipt` had no per-document PDF at all
— not even in an older style — so this is a new document, not a restyle.
`Modules\Rental\Services\RentalReceiptPdf` + `receipt-pdf.blade.php` use the
SAME gold-band reference-template design as the invoice/quotation, with the
fields the owner pointed at on the old system's printed cash receipt:
Receipt No. / Received with thanks from (customer name **+ their CPR/ID**,
`RentalCustomer::cpr`, when on file) / a sum-of breakdown (Rental + VAT +
Extra charge) / Rental Agreement # / By Cash/Cheque/Credit Card / Remarks.
**The breakdown only prints when it actually foots to the receipt's own
`amount`** — computed from the linked invoice's `order` (`subtotal − discount`
as the rental figure, `vat_amount`, and `delivery_charges + extra_charge +
fuelChargeTotal()` as Extra) and compared against what was really recorded;
a partial payment against a bigger invoice would otherwise print a
breakdown that doesn't match what was actually handed over, so it silently
falls back to a flat "Amount received" line instead. "Rental Agreement #" is
the ORDER's own reference (`RA…`), not the invoice's — reached via
`receipt->invoice->order`, since that's what the customer's paperwork calls
it. **The sign-off row is "Received by" / "Stamp", not a customer
signature** — same reasoning already established for the Limousine receipt
(a receipt acknowledges money arrived; it isn't a contract the customer
signs), applied here for consistency even though the old paper form itself
had a "Signature" line. `RentalReceiptController` (new,
`/app/rental/receipt/{id}/download`) serves a single receipt, mirroring
`RentalInvoiceController`/`RentalQuotationController`; the receipts list
gained the same download icon + Actions column. **Unlike invoices/
quotations, `RentalReceiptExportController::pdf()` was NOT changed to branch
on ticked rows** — the Limousine receipt's own export controller doesn't do
that either (only its per-row download exists), so Rental stays consistent
with that sibling rather than the invoice/quotation pattern. Test:
`RentalReceiptDocumentTest` (customer/CPR/agreement carried through, the
breakdown appears only when it foots to the amount, a partial payment or a
receipt with no invoice shows no breakdown, Remarks prints the receipt's own
`notes`, the second sign-off slot is the stamp not a signature, the PDF
renders and the download icon serves it). Also added to
`DocumentFooterTest::DOCUMENTS`.

**Limo invoice PDF redesigned + the toolbar "PDF" now downloads real invoice
documents when rows are ticked (shipped 2026-09-08):** `invoice-pdf.blade.php`
was rebuilt to match a reference template the owner supplied — a solid gold
(`#FFC837`) band across the top, a large plain "INVOICE" title with issue/due
date + invoice number as small label/value columns beside it, Bill from /
Bill to, a plain-ruled Date/Description/Amount item table (no separate
price/qty split — this document doesn't have that data), and a right-aligned
totals box with **Balance due** bold above a top rule (still the headline,
not Total). It does **not** use the shared `<x-pdf-styles />` component —
that dark-letterhead/gold-accent family is a different visual language from
this reference design, so the file carries its own `<style>` block. The
Partial badge is the same gold with dark text (never white-on-brand-yellow).

The body markup is shared via `Modules/Limousine/resources/views/partials/
invoice-body.blade.php` with a new sibling document,
`invoices-batch-pdf.blade.php`: several invoices as ONE pdf, one full page
each (`page-break-before` between them), sharing a single `<x-document-footer
/>` (its `position: fixed` repeats on every physical page DomPDF paginates,
manual page breaks included). **Why this exists:** the invoices list's
toolbar "PDF" (next to Copy/CSV/Excel/Print, narrowed by the row checkboxes)
used to ALWAYS render the generic tabular list report
(`exports/list-print.blade.php`, shared by every list in the app) regardless
of what was ticked — so ticking one invoice and pressing "PDF" produced a
plain data table, not the actual bill, which read as "the old pdf" once the
real invoice document had a new look. `LimoInvoiceExportController::pdf()`
now branches: ticked rows → their own invoice document(s) via the new
`LimoInvoicePdf::renderMany()` (one row → `render()`, same as the per-row
download icon); nothing ticked → unchanged tabular report. CSV/Excel/Print
are untouched — still the plain tabular export, ticked or not. Tests:
`LimoBespokeExportTest::{test_invoice_pdf_with_one_ticked_row_downloads_that_invoice_document,
test_invoice_pdf_with_several_ticked_rows_downloads_them_as_one_document,
test_invoice_pdf_with_nothing_ticked_still_exports_the_tabular_report}`. AR
keys: "Bill from" / "Bill to".

**A shared account's app access is set inside each database (shipped 2026-09-07):**

Settings → Users, inside a workspace, gained an **"Edit access"** action on a
**global** account (one shared with every database, `home_workspace_id` null,
labelled *Managed on Main*). It opens a cut-down form: name / email / role are
shown read-only, and only the **apps checklist** is editable. Saving writes
`ir_model_access` rules **in the current database only**.

**Why this had to exist.** `UserManager::render()` and
`UserProvisioner::grantApps()` both filter apps through
`Features::moduleAllowed()`, which reads *that database's own*
`company.business_type`. Main is a **café**, so its checklist only ever offers
contacts / pos / inventory / accounting / purchases — **Rent A Car and
Limousine cannot be ticked there at all**. A global account therefore could
never be granted them for a rental workspace: the tick was unavailable on Main,
and the workspace's own screen refused to edit the account. Found live: Prejith
(accountant, global) had 4 rules in the Wanaan database — accounting ×2,
contacts, purchases — and no rental/limousine access whatever, though the owner
had "given her access to all databases".

| Concern | Location |
|---|---|
| Flag | `UserManager::$editingGlobal` — set by `editUser()` when, inside a workspace, the target is global (`home_workspace_id === null`) **and not an admin**. An admin bypasses the ACL, so there is nothing to grant and the form does not open (the pre-existing "global stays read-only" test covers exactly that case) |
| Validation | `rules()` returns **only** the `apps` rules while `$editingGlobal` — the identity fields aren't editable, and the role rule (`Rule::in($assignable)`) would otherwise reject a regular admin editing an accountant |
| Write | `UserManager::writeGlobalAccessHere()` — re-reads the role with `roleOf()` (never from the form), calls `grantApps()` on the **current** connection, logs `user_updated`. Reached from `saveInWorkspace()` (which takes the email-OTP gate first) and from `confirmedUpdate()` after that gate |
| Not editable | name / email / role (Main owns the identity - editing it here would silently diverge every other database) and **delete**: `canDeleteHere()` still requires `belongsHere()`, so a shared account is removed on Main, not from one database |
| UI | List: `$canSetAccessHere` (`$workspaceId && ! $inScope && home_workspace_id === null && ! is_admin`) renders **Edit access** in an `@elseif` — deliberately NOT by widening the existing `@if ($inScope && $canManage)`, because the **remove** button is nested inside it. Form: heading "Edit app access", a read-only who-this-is panel, credentials + role block hidden, button "Save app access" |
| Tests | `UserManagerTest::{test_a_shared_accounts_app_access_can_be_set_from_inside_a_workspace, test_a_shared_accounts_name_email_and_role_are_left_to_main, test_a_shared_account_still_cannot_be_deleted_from_inside_a_workspace}` + the existing global-admin no-op test, comment sharpened. 7 `lang/ar.json` keys |

**Rule: ACL grants are per-database rows, identity is Main's.** Anything that
edits a shared account from inside a workspace must stay on that side of the
line.

**Statement of account — company reference + column alignment (shipped 2026-09-07):**

The owner marked up a printed statement. Two things, both in
`limousine::statement-pdf` and `LimoStatement`:

- **A "Company ref." column**, between Description and Receipt no. — the
  customer's own order number (`limo_bookings.company_reference`, the same
  field and the same label the combined invoice already prints), so their
  accounts department can tie a line to **their** paperwork and not only to
  ours. A **payment** row shows the reference of the bill it answers
  (`receipt.invoice.booking`, falling back to the receipt's own booking) —
  otherwise a page of receipts traces back to nothing. The nested eager load
  needs the FK selected: `invoice:id,reference,booking_id` **plus**
  `invoice.booking:id,reference,company_reference`.
- **The money headings were left-aligned over right-aligned figures.**
  `.ledger th` (0,0,1,1) out-specifies `.num` (0,0,1,0), so Charge / Payment /
  Balance printed hard against their columns' left edge while the figures under
  them sat right — measured at **19.8 / 24.0 / 13.3pt adrift**. Fixed with
  `.ledger th.num { text-align: right; }`. **Watch this whenever a `.num`-style
  utility meets an element-qualified table rule** — the utility silently loses.

Column widths were then rebalanced (date 60 · reference 68 · description auto ·
company ref. 100 · receipt 86 · charge 72 · payment 72 · balance 80) because at
the first attempt "COMPANY REF." wrapped to a second line, and at the second
"RECEIPT NO." did. Both were found by **rendering the PDF and reading the text
placements back out of its content streams** — DomPDF writes **UTF-16BE**, so
the "spaces" between letters in a `TJ` array are NUL bytes; strip `\x00` before
matching, or every needle misses.

Tests: `LimoStatementTest` (+4 — every line carries the customer's reference and
a payment shows the bill's, the column prints, the heading rule is present, and
**every ledger row spans the same eight columns** — a wrong `colspan` on the
brought-forward or closing row shunts every figure sideways, which is how a
statement starts lying).

**A limousine receipt says who raised it (shipped 2026-09-07):**

`limo_receipts.prepared_by` (module migration `2026_09_07_950030`) + a
**"Prepared by"** slot on the receipt beside Received by / Stamp — the line the
pre-printed pad had, filled in by hand.

- **A name snapshot, not a foreign key**, for the same reason `confirmed_by` is
  one: a receipt is a financial document and must still read correctly after
  the account that raised it is renamed or deleted.
- **The account's `name`** — the username people sign in under — **never the
  email**, which is not what anyone would write on a receipt.
- Stamped in a **`creating` hook on the model**, not at the call sites: a
  receipt is created in **five** places (`ReceiptForm`, `BookingPayments`
  ×2, `BookingImporter`, `ReceiptImporter`) and any of them could forget. A
  CLI import runs with nobody signed in and leaves it unset.
- **Deliberately not backfilled**, and an unset value prints **no line** (the
  two signing slots stay evenly split). We do not know who typed the historical
  rows, and a name invented onto a financial document would be a lie.

Tests: `LimoReceiptDocumentTest::{test_the_receipt_records_and_prints_who_raised_it,
test_an_older_receipt_prints_no_prepared_by_line}`.

**The limousine receipt is stamped, not counter-signed (shipped 2026-09-07):**

`limousine::receipt-pdf`'s second signature slot said **"Customer signature"**;
it now says **"Stamp"**. A receipt is our acknowledgement that the money
arrived — the customer is not attesting to anything by being paid up, and the
office stamps these, exactly as the old printed pad did. The **rental
agreement keeps its "Customer signature"**: that one is a contract, and the
customer really is signing it. Pinned by
`LimoReceiptDocumentTest::test_the_second_slot_is_the_company_stamp_not_a_customer_signature`.

**One footer band on every printed page (shipped 2026-09-07):**

Every page the system prints carries the same grey band: company name + phone
numbers on the left, address (and email / website) on the right. Asked for by
the owner, who supplied Wanaan's numbers and its new Juffair address.

Three families, all fed by the same component:

1. **The documents a customer receives** — the rental **agreement**, and the
   limousine **quotation / invoice / combined invoice / receipt / statement /
   coupon voucher / service order**.
2. **Every list Print and PDF in the app** — they all render through the one
   shared `resources/views/exports/list-print.blade.php`
   (`App\Erp\Export\TabularRenderer`), plus the limousine driver queue's own
   copy of that layout, `limousine::queue-print`. **This family was missed on
   the first pass** and the owner found it immediately by printing a
   quotations list: patching the eight documents does nothing for the list
   exports, because they share no markup with them.
3. **Reports** — `purchases::reorder-pdf`, `pos::daily-report-pdf`,
   `pdf.payslip`, `pos::stock-report-print`.

**When adding a new printable page, drop `<x-document-footer />` in and widen
the `@page` bottom margin to 60px.** To find them all again:
`grep -rn "Pdf::loadView" app Modules` — every PDF in the system is rendered
from one of those call sites.

| Concern | Location |
|---|---|
| The band | `resources/views/components/document-footer.blade.php` — an **anonymous Blade component** that reads its own data from `Setting`, so a host document just drops `<x-document-footer />` in and passes nothing |
| DomPDF vs a browser | The band defaults to `fixed` (per page, what DomPDF wants). A page a **browser** prints passes **`:fixed="false"`** and gets an ordinary block after the last row instead — browsers disagree about whether a fixed element repeats per page, and on screen `bottom: -50px` sits below the window entirely. The two list views serve both a Print view and a PDF download from one template, so they pass `:fixed="$forPdf"` |
| DomPDF renders as `screen`, not `print` | `default_media_type` is **`screen`**, so a `@media print { body { margin: 0 } }` block in a shared Print/PDF view does **not** apply to the PDF — the body margin lands on top of the `@page` margin there. DomPDF's own default `@page` margin is **`1.2cm`** (`vendor/dompdf/dompdf/lib/res/html.css`), so a view with no `@page` rule of its own takes `@page { margin: 1.2cm 1.2cm 60px; }` — top and sides unchanged, only the bottom grown |
| Data | Seven General settings, all **per database**: `company.phone` (hotline, printed first), `company.phone_alt` (free list — split on `, ; /`), `company.address`, `company.email`, `company.website`, and — added 2026-09-07 from the owner's old pre-printed rental receipt — `company.vat_number` and `company.cr_number` (migration `2026_09_07_100003`). `company.phone`/`company.email` were **already read** by the limousine PDF services but had **no `ir_config_parameter` row**, so they were unreachable from Settings and every footer printed the company name alone |
| Layout | Left cell: company name, then `Hotline: …` with every number, then `VAT No.: … · CR No.: …`. Right cell (right-aligned): address, then email / website. That mirrors the pre-printed pad the office used to fill in by hand |
| Rows created | `SettingSeeder` (new databases) **and** core migration `2026_09_07_100002_add_company_contact_settings` (existing ones) — insert-only, never touches a saved value. The migration is the one that matters: `SettingSeeder` runs against **Main only** on deploy, while `workspaces:migrate` carries a core migration into **every workspace** |
| Repeats per page | `position: fixed; bottom: -50px` — that is how DomPDF repeats a band on every page. Each host reserves **60px** in its `@page` bottom margin. **Measured, not guessed** (a two-page probe rendered with DomPDF and read back through the PDF's own coordinates): band occupies y 7.5–38pt on **both** pages, lowest body text at y 62 — 24pt of clearance |
| Empty databases | The band prints only when this database has **at least one contact detail** (a phone, address, email or website). A company **name alone is deliberately not enough** — every document already prints the name in its header, and an unconfigured database still answers the `"OpenERP"` default, which would put a stranger's name on the foot of a real customer's invoice (Hashtag Limo's live database does exactly that). So an unconfigured business gets **no grey bar at all** |

**Bank details on invoices (shipped 2026-09-29).** The limousine invoice (single,
batch, combined) and the rental invoice (single, batch) print a "how to pay"
block under the totals — cheque payee + bank name / account no. / IBAN / SWIFT —
via the shared anonymous component `<x-bank-details />`, reading five General
settings: `company.bank_payee` (blank = company name), `company.bank_name`,
`company.bank_account`, `company.bank_iban`, `company.bank_swift`. Nothing prints
until one bank detail is filled. Core migration `2026_09_29_100002` adds the rows
everywhere and pre-fills Wanaan's (recognised by company name containing
"wanaan" or VAT 220015215500002) from its printed invoice: Al Salam Bank,
765765150000, BH47ALSA00765765150000, ALSABHBM — never overwriting a typed
value. Test: `BankDetailsTest`.

**Never hardcode an address or a number in these views.** `Modules/Rental` and
`Modules/Limousine` are shared by every business on the system, so anything
baked in prints on Hashtag Limo's paperwork too. That had already happened:
`service-order-pdf` carried `Shop 2082, Road 5669, Block 356` and
`Tel: +973 17474949` in its markup — the office has since moved, so it was
printing the **wrong** address for Wanaan and someone else's for everyone else.
Both are gone; the band supplies them from settings.
`DocumentFooterTest::test_the_old_hardcoded_address_is_gone_from_the_service_order`
keeps them gone.

Deliberately **not** given the band:

- **`rental::agreement-print`** — an overlay of absolute mm positions onto
  pre-printed stationery. That paper has its own footer; ours would land on top
  of it.
- **`pos::receipt-pdf` and `pos::receipt-print`** — the till slip (a 360px-wide
  roll, `@page margin: 0`) and its browser twin. A full-width grey band does not
  belong on a receipt, and the slip already prints the shop's phone at the top.

Both are pinned by `test_the_thermal_receipt_slip_is_left_alone`, so a later
sweep doesn't "helpfully" add them. Everything else that renders a PDF or a
print view **does** carry the band — including `queue-print`, which was on this
exclusion list on the first pass and is now included.

Wanaan's own values (workspace 7) are **data, not code**: hotline
`+973 17474949`, plus `+973 39991869` and `+973 39991830`, at *Shop 4, Building
18, Road 4101, Block 341, Juffair, Bahrain*. Any admin changes them in
**Settings → General**, and every document follows on the next print.

Tests: `tests/Feature/DocumentFooterTest.php` (12 - prints name/numbers/address/
email, hotline first, separators, no empty band, a name on its own is not worth
a band, one contact detail is enough, fixed positioning, **all fourteen printable
views carry the tag and reserve the 60px margin**, a list export prints the band
under its rows, the browser-print variant lays it out in the flow, the till slip
and the stationery overlay are left alone, the old hardcoded address stays gone).

**Profile self-service (shipped 2026-05-21):**

| Concern | Location |
|---|---|
| Page | `App\Livewire\ProfilePage` + `resources/views/livewire/profile-page.blade.php`, route `/profile` (auth-only, accessed via topbar user-menu dropdown) |
| Schema | `2026_05_21_300001_add_profile_fields_to_users_table` — `avatar_path` (nullable text) + `new_email` (nullable indexed string) |
| Email change | NOT direct write-through. Save parks new value in `users.new_email`; `App\Notifications\VerifyNewEmail` sends a 1-hour signed verification link to the **new** address via `Notification::route('mail', $new)->notify(...)` (anonymous notifiable — the user's default routing would deliver to the OLD address). URL: `URL::temporarySignedRoute('profile.email.verify', now()->addHour(), ['id', 'hash'])` where `hash = sha256(strtolower(trim(email)) . '|' . config('app.key'))` |
| Verification | `App\Http\Controllers\ProfileEmailVerificationController` (single-action invokable) — checks `hasValidSignature()`, re-derives hash from `users.new_email`, refuses on mismatch (stale link after the user changed their mind → 403). On match: `forceFill(email = new_email, new_email = null, email_verified_at = now())->save()` + redirect to `/profile` with flash. No pending change → friendly redirect (not 4xx). Login NOT required (signature is proof of intent, matches Laravel's stock email-verify convention) |
| Avatar | `WithFileUploads` → `Storage::disk('public')->store('avatars')`; replacing deletes the previous file (idempotent — `delete()` no-ops on missing). `User::avatarUrl()` is the single render path used by both the profile page and the topbar — returns null when `avatar_path` is set but the underlying file is missing on disk (so the initial-letter fallback renders instead of a broken-image icon). Pinned by `ProfilePageTest::test_avatar_url_falls_back_to_null_when_the_underlying_file_is_missing` after a deploy regression wiped the avatars bucket. **Workspace fallback:** avatars live on one shared disk but each database has its own `users` row, and a tenant copy of an account (created by email at provisioning) has no `avatar_path` — so `avatarUrl()` falls back to the **Main (landlord) record** (`User::on(Workspace::$landlordConnection)` by email) when the active workspace's row has none, keeping the same profile image across databases. Guarded by `DB::getDefaultConnection() === landlord` (no lookup on Main) and a try/catch. Regression: `tests/Feature/AvatarWorkspaceFallbackTest.php` |
| Role | Read-only display via `User::roleLabel()`; form has no `is_admin` input, and `save()` never touches it — pinned by `test_save_does_not_let_user_promote_themself_via_form_state` |
| Password | Optional — requires correct `currentPassword`; `min:8` + `confirmed:newPasswordConfirmation`; all three fields have Alpine eye-toggle (purple `text-primary-600`, independent state). Same eye pattern on the login password field. **Printable-ASCII only** — blade inputs carry an `x-on:beforeinput` filter that `preventDefault`s any keystroke or paste whose `event.data` matches `/[^\x20-\x7E]/`, and `newPassword` carries a matching `regex:/^[\x20-\x7E]*$/` server-side rule (defence in depth for JS-disabled clients / crafted payloads). Each block's Alpine scope holds a debounced `blocked` flag flipped by `notifyBlocked()` on a vetoed press; reusable Blade component `<x-password-ascii-notice />` (in `resources/views/components/`) reads it and fades in an amber pill "English characters only." 2.5 s window past the last attempt. Login form is **not** filtered so users with pre-existing non-ASCII passwords aren't locked out |
| Tests | `tests/Feature/ProfilePageTest.php` (19) — mount prefill, name/avatar/password write-through, email parks + notifies new address (via `assertSentOnDemand` because anonymous notifiable doesn't match user instance), already-taken email rejected, cancel pending clears `new_email`, controller swap on valid sig, refuse on mismatched hash / unsigned / expired, idempotent flash on already-verified, role label correct for admin/non-admin, role can't be promoted via form state |

**Multi-database / "My database" (workspaces — shipped 2026-06-11):**

Odoo-style database manager: an admin can create separate, isolated ERPs and
switch between them from the topbar **"My database"** menu item (between
Profile and Settings). Each workspace is its own **SQLite file**; **one login**
works across all of them (Main admins are copied into each on provisioning,
matched by email). Memory: `[[workspaces-tenancy-architecture]]`.

| Concern | Location |
|---|---|
| Registry | `workspaces` table (core migration `2026_06_11_100001`, lives in the **Main** DB) + `App\Models\Workspace` — **pinned to the boot-time default connection by NAME** via `Workspace::$landlordConnection` (so the list is always read from Main even while a tenant is the active default; pinning by name, not a clone, shares the in-memory SQLite used in tests). `is_main` row = today's data (no file); others store a bare `database` filename under `storage/app/workspaces/`. `databasePath()` resolves it |
| Connection wiring | `App\Providers\WorkspaceServiceProvider` (registered FIRST in `bootstrap/providers.php`) — sets `Workspace::$landlordConnection`, **pins `session.connection` + the database `queue` connection to the boot-time default** (so a tenant swap never logs anyone out / orphans jobs — no-op on Main), and registers a reusable `tenant` SQLite connection whose path is set at runtime |
| Routing | `App\Http\Middleware\SetActiveWorkspace` (web group, appended AFTER StartSession + BEFORE the `auth` route middleware, listed before `SetLocale`). **Main = strict zero-cost no-op: no `erp_workspace` cookie ⇒ returns immediately, no DB query, no dependency on the `workspaces` table** (safe mid-deploy). For a tenant: swaps `database.default` → `tenant` (its file) + `cache.prefix` → `ws<id>_`, then **rebinds auth by email** (`Auth::setUser` the tenant user matching the Main identity's email — the persisted session login id is NEVER changed, so switching back to Main restores the original cleanly). Whole tenant path wrapped in try/catch → falls back to Main, never breaks a request |
| Manager | `App\Erp\Tenancy\WorkspaceManager` — `provision(name, owner, ?modules)` (touch SQLite file → `withTenant()` swaps default → `Artisan::call('migrate')` (core) → `ModuleManager::install` each discovered module → `AuthSeeder` + copy Main admins by email + `SettingSeeder`), `activate()`, `withTenant()`, `delete()` (drops file + row; Main undeletable), `current()`/`all()`/`ensureMain()` |
| Switch | `App\Http\Controllers\SwitchWorkspaceController` (GET `/workspaces/switch/{id}`, admin-only) queues the `erp_workspace` cookie (1-yr) + full-page redirect home — a plain GET so the Set-Cookie rides the redirect |
| UI | `App\Livewire\WorkspacesPage` (`/workspaces`, **admin-only**) — list / create (synchronous provisioning, "Building…" state) / **inline rename** (`startRename`/`rename`/`cancelRename` — `editingId`+`editName`, updates only `name` on the landlord-pinned `Workspace`, leaving `slug`/`database` untouched so switching by id still works; renaming Main is allowed, the "Main" badge derives from `is_main` not the name) / **delete→trash** (see Trash row) / switch via links. Menu item in `components/layouts/app.blade.php` (admin-only). `resources/views/livewire/pages/workspaces.blade.php` |
| Trash / soft delete (shipped 2026-06-22) | Deleting a database is **password-confirmed + reversible**. `Workspace` `use SoftDeletes` (`deleted_at` via core migration `2026_06_22_200001`); `WorkspaceManager::RETENTION_DAYS`=14. UI `delete` opens a **password modal** (`confirmDelete`/`cancelDelete`/`deleteWorkspace`) — `Hash::check` against the admin's own password, then `WorkspaceManager::trash()` (soft delete, **SQLite file KEPT**). Trashed DBs leave the active list (SoftDeletes global scope on `all()`/`find()`/`current()` → a trashed cookie falls back to Main, can't be switched into) and appear in a **"Recently deleted"** section with days-left + **Restore** (`restoreWorkspace` → `restore()`). After the window a daily schedule (`purge-expired-workspaces` in `routes/console.php`, `withoutOverlapping` — in-process so safe) calls `purgeExpired()` → `delete()` (now `forceDelete()` + `@unlink` file) for good. `findAny()`=`withTrashed()->find`; `trashed()`=`onlyTrashed()`; `uniqueSlug` checks `withTrashed`. **Depends on the hPanel `schedule:run` cron** like the other sweeps. Tests in `WorkspaceTest` (password-then-trash + file kept, restore, purge-after-window) |
| Deploy | `storage/app/workspaces/` **MUST be in deploy.yml's rsync `--exclude`** (added 2026-06-11) — else `--delete` wipes every user-created database (`[[rsync-delete-wipes-user-uploads]]`). The `workspaces` migration is core, so deploy's core `migrate` creates the registry table automatically |
| Tests | `tests/Feature/WorkspaceTest.php` (8 — Main is default with no cookie, provision builds an isolated DB with modules+admin (Main untouched), delete removes file+row, Main undeletable, non-admin 403, name validation, cookie routes a request to the tenant + keeps auth, switch admin-gate + cookie) |

**Known MVP limitations (offer as follow-ups):** auth is **admin-only** for
switching (the rebind matches Main admins by email — a non-admin added only in
a tenant can't switch in); background **queue jobs** run in the Main context
(queue pinned to Main); no backup/duplicate/rename; provisioning is synchronous
(~seconds, installs every module); creating a MySQL/Postgres workspace isn't
supported (SQLite files only, per the chosen architecture).

**A form component's `id` must not be typed `?int` (fixed 2026-09-05):**

Opening **`/app/rental/customer/new`** 500ed with
`CustomerForm::mount(): Argument #1 ($id) must be of type ?int, string given`.
**A route segment is always a STRING**, and a non-numeric one ("new") cannot
coerce to `int`, so the mount blew up before any of the component's own logic
ran. The same signature sat in **29 form components across every module**
(`grep "public function mount(?int \$id"`), so every `/new` page carried the
same latent 500. All of them now take **`int|string|null $id = null`** and
normalise as their first statement:

```php
// A route segment is always a string, and a non-numeric one
// ("new") means a new record rather than a bad request.
$id = is_numeric($id) ? (int) $id : null;
```

**Rule: a full-page Livewire component's route-bound scalar takes
`int|string|null` and normalises in the body — never `?int`.** (`whereNumber()`
on the route is not enough on its own: it constrains one route, while the
component is reachable from several — the Rental customer form also serves the
Limousine customer routes.) Test:
`RentalCustomerProfileTest::test_a_non_numeric_id_opens_the_create_form_instead_of_erroring`,
which calls `mount()` **through the container** with string params (how Livewire
mounts a page component) — `Livewire::test($class, ['id' => 'new'])` does NOT
reproduce it, because the test harness also assigns params onto the typed public
property and fails differently.

**Rental customer import — full column set + enrichment + workspace targeting (shipped 2026-09-03; phone matching 2026-09-05):**

`Modules\Rental\Support\CustomerImporter` (shared by the Import button on both apps'
Customers pages AND `php artisan rental:import-customers <file.csv>`) was extended for
the full Wanaan customer export:

- **Columns:** on top of Name / Type / CPR / Phone / E-mail it now reads Country
  (name **or** ISO-2 → stored as the ISO-2 code the customer form uses; unknown names
  store null — see `COUNTRY_ALIASES` for spellings beyond `RentalCustomer::countries()`,
  e.g. UAE / USA / Canada / Turkey), Licence No., Nationality, CR Number, Contact
  Person, Contact Person Phone, Address. Header aliases cover "Customer Type" /
  "CPR / ID" etc. Phones with two numbers jammed together (`+9665…+44…`) keep the
  first number.
- **Enrichment, not just skip:** an existing customer matched by CPR/CR → phone →
  (only when the row has neither) name gets their **blank** fields filled from the row
  — a filled field is NEVER overwritten. Result counts are
  `{imported, updated, skipped}`; the upload toast shows all three.
- **Phones match across country codes (2026-09-05).** A local number and the same
  number carrying its country code are ONE phone: matching is exact-first, then by
  a shared **ending of at least `PHONE_SUFFIX_MIN` (7) digits** (trunk zero dropped),
  via a `$byPhoneEnding` index so it stays O(1) per row. This is the same rule
  `PosCustomerDiscount::findForPhone()` uses. It matters a lot on real exports: the
  Wanaan limousine customer list held "38381200" for people we store as
  "+97338381200" — without it the import would have created **449** customers where
  only **108** were genuinely new. A `Status` column of `Inactive`/`no`/`0`/`false`
  creates the customer switched off (**on create only** — a matched customer keeps
  the active flag we already have).
- **`--workspace=<id>`** on the command imports into a tenant database via
  `WorkspaceManager::runFor()`; an unknown id **fails** instead of silently falling
  through to Main (a bulk import into the wrong database is the disaster case).
- **Wanaan data migration (2026-09-03 → 09-05), all into workspace id 7.** The old
  system's exports were loaded in this order, each behind a fresh server-side
  backup (`~/wanaan-pre-*.sqlite`): customers (3,769) → invoices (1,318, original
  `INV/…` numbers) → receipts (12,799, which recompute each invoice's paid status)
  → drivers (124) → rental orders (1,658, with 27 retired cars kept **inactive** so
  their history still opens) → quotations (558) → fleet (164 active cars, matched by
  plate so orders stayed attached) → limousine bookings (15,393 + 16,074 legs) →
  the limousine-only customers (108 new). **Original document numbers were preserved
  as the row ids** wherever the old system had them, so "Booking #15329" is `BK/15329`.
  Sheet columns with no model field (rental "Vehicle Type", per-leg hours) were
  dropped deliberately.
- Tests: `tests/Feature/RentalCustomerImportTest.php` (13 — full-column mapping +
  ISO codes, enrich-blank-keep-filled, jammed phone, country-code phone match,
  short-number guard, inactive status, name-fallback dedupe, unknown-workspace
  refusal, plus the original import/dedupe/endpoint/gate set).

**Wanaan service-order payment portal (built 2026-09-01; FULLY LIVE on Tap live keys 2026-09-03):**

Lets a limousine booking be paid online: the agent raises a **payment link** for a
trip (a "partition" — a deposit, one leg, or the whole balance; the amount is
agent-chosen, pre-filled with the remaining balance), the link is generated on the
**Wanaan WordPress site** (`wanaan-bh.com`), the customer opens it and pays via
**WooCommerce + Tap WebConnect**, and WordPress calls back to mark the booking paid.
Both halves now ship — the ERP module (below) AND the WordPress plugin
`wanaan-service-order` (see the plugin section below). Deployed and **fully live on
Tap live keys — taking real money end-to-end** for the `wanaan` database. TEST-mode
notes elsewhere are historical.

| Concern | Location |
|---|---|
| Config (per-database, OFF by default) | `limo_portal_configuration` table + `Modules\Limousine\Models\LimoPortalConfiguration` (`portal_url`, `shared_secret` **encrypted**, `enabled`). `enabled` defaults **false** — the master switch the manager can flip off in one place; `isConfigured()` gates every push |
| Payment link ("partition") | `limo_payment_links` table + `LimoPaymentLink` — one row per link (booking can have many). The **row id is the idempotency key** the portal + callback quote back (`erp_payment_id`), so a re-send updates and a replayed callback can't pay twice. `leg_id`/`booking_id`/`created_by_user_id` are logical refs |
| Signing scheme (shared with WP) | `Modules\Limousine\Support\PortalSignature` — `X-Wanaan-Timestamp` + `X-Wanaan-Signature` = hex HMAC-SHA256 of `"<timestamp>.<raw-body>"`, keyed by the shared secret; constant-time verify, 5-min window. **The WP plugin MUST reproduce `hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret)` byte-for-byte** |
| Outbound push | `Modules\Limousine\Services\ServiceOrderPortalClient::push()` — builds the Service-Order payload (same fields/sources as `ServiceOrderPdf`: trip facts from the **leg**, customer/PAX from the parent **booking**; **`amount` sent as an exact 3-dp BHD string** e.g. `45.000`; a customer **`email`** field — `serviceEmail()` prefers `service_email` then the general email — so WooCommerce/Tap prefills the checkout billing email), signs the raw JSON, POSTs to `{portal_url}/wp-json/wanaan/v1/booking` with a 5s timeout. **Never throws** — a WordPress outage returns false and the booking is untouched. Carries `ws` (the workspace id) so the callback re-enters the right database |
| Inbound callback | `Modules\Limousine\Http\Controllers\PaymentCallbackController` at **public** `POST /limousine/payment-callback` (CSRF-excepted in `bootstrap/app.php`; HMAC is the only credential). Resolves `ws` via `WorkspaceManager::runFor()`, verifies against THAT database's secret, then settles once (lock + `credit`-guard) through the existing `BookingPayments::receive($booking, $amount, 'online')` — which issues the receipt and flips the booking to Paid only when the balance clears. **Uses OUR billed amount**, not the callback's; a mismatch is logged. Idempotent |
| Agent action | `Bookings::openPaymentLink/createPaymentLink/closePaymentLink` + a "Create payment link" button (only shown when the portal is on) and modal on the bookings list — amount pre-filled with the balance, capped at it; the generated URL is **shown on-screen with a Copy button** to send the customer |
| Settings | `LimoPortalSettings` at `/app/settings/limo-portal` (admin-only), a **"Service Portal"** tab in settings-nav — portal URL, shared secret (write-only), and the ON/OFF switch; shows the callback URL to give WordPress |
| Deploy | The existing `deploy.yml` Limousine step (`migrate --path=Modules/Limousine/... --force` + `module:resync limousine`) auto-applies the two new migrations on live once merged to `main` — no manual SSH. **Until then it lives on a feature branch (deploy runs only on `main`), so live is untouched** |
| Tests | `tests/Feature/LimoServiceOrderPortalTest.php` (6 — off-by-default no-op, signed push + stored link + exact `45.000`, callback settles + receipt + marks paid, bad signature 401, idempotent, agent creates a link) |

**WordPress plugin `wanaan-service-order` (built 2026-09-03, lives in `wp-plugin/` in this repo but is NOT deployed by `deploy.yml` — `--exclude='wp-plugin/'`; installed by uploading the ZIP in WP admin):**

The WP half. Distributed as a zip built from `wp-plugin/wanaan-service-order/` — **build
the zip with .NET `ZipArchive` forcing forward-slash entry paths** (`.Replace('\\','/')`);
Windows `Compress-Archive` writes backslash entries that WP's unzip rejects. Version
constant `WANAAN_SO_VERSION` (currently 1.0.6). Structure:

| Piece | File | What it does |
|---|---|---|
| Signature | `includes/class-wso-signature.php` | Mirrors the ERP `PortalSignature` exactly — `secret()` reads the **`WANAAN_PORTAL_SECRET` wp-config constant** (the shared HMAC secret lives ONLY here + the ERP encrypted config, never in the repo); `sign()`/`verify()` = `hash_hmac('sha256', "$ts.$rawBody", $secret)`, constant-time, 5-min window |
| Install / schema | `includes/class-wso-install.php` | Creates `{prefix}wanaan_service_orders` via dbDelta; `SCHEMA_VERSION` self-heals on upgrade (v2 added `customer_email`) |
| Repository | `includes/class-wso-repository.php` | Upserts keyed on `erp_payment_id` (the ERP row id = idempotency key); mints a `token` (`bin2hex(random_bytes(16))`); stores `customer_email` |
| REST receiver | `includes/class-wso-rest.php` | `POST /wp-json/wanaan/v1/booking` (`permission_callback => __return_true`, HMAC is the only credential); verifies signature over the **raw** body, upserts, returns `{url, token}` |
| Public pay page | `includes/class-wso-page.php` + `templates/service-order.php` | Rewrite `/service-order/{token}`, `noindex`. **No T&C checkbox** (removed 2026-09-03 — the customer already agrees on the ERP-side flow; don't double-gate). Shows trip facts + amount + a **Pay Now** button; `handle_pay` (admin-post) still records consent (time/IP/UA) then routes to checkout |
| WooCommerce → Tap | `includes/class-wso-woo.php` | **cart → `/checkout/` flow** (see gotcha below). Loads the cart (`wc_load_cart()`), empties it, adds a hidden virtual "service" product carrying `wanaan_so_row/token/amount` as cart-item data, stashes customer name/email/phone to session, redirects to `wc_get_checkout_url()`. Hooks: `woocommerce_before_calculate_totals` (set the cart line's price to the exact amount), `woocommerce_checkout_create_order` (tag the order `_wanaan_service_order_id`), `woocommerce_checkout_order_processed` (link), `simplify_checkout_fields`/`prefill_checkout_value` (drop billing-address noise, prefill name/email/phone), `restrict_gateways` (keep only Tap when the cart holds a service item — **fails open**), `on_paid` (mark the row paid → fire the callback). `service_product_id()` lazily creates a hidden virtual `WC_Product_Simple` with `set_tax_status('none')` on the **product** |
| Callback → ERP | `includes/class-wso-callback.php` | On paid, signs + POSTs to the ERP `{callback_url}/limousine/payment-callback`; WP-Cron backoff retry (`MAX_RETRIES=5`); callback URL in `wanaan_so_callback_url` option |
| Admin | `includes/class-wso-admin.php` | Settings screen (capability + nonce guarded) for the callback URL etc. |

**Hard-won WooCommerce/Tap gotchas (don't re-derive these):**
- The Wanaan site is a **React front end** — the WooCommerce **order-pay** page
  (`/checkout/order-pay/...`) is a **dead/blank route** there. Paying an existing order
  by its pay URL shows nothing. The ONLY reliable flow is **cart → `/checkout/`** (a fresh
  standard checkout), which is why the plugin adds a product to the cart and redirects.
- **Tap builds its charge from product LINE ITEMS, not fee items.** A fee-only order gives
  the Tap hosted page nothing to charge and it renders blank. Use a real product line.
- `WC_Order_Item_Product` has **no `set_tax_status()`** (only `WC_Order_Item_Fee` does) —
  calling it is a fatal white-screen. Set `tax_status` on the **product** instead. Catch
  `\Throwable` (not just `Exception`) around the checkout wiring so a fatal degrades to a
  `WP_Error`, not a white screen.
- Verify the endpoints are live + HMAC-enforced with a signed-vs-unsigned curl — an unsigned
  `POST /wp-json/wanaan/v1/booking` must return **401**.

**Live since 2026-09-03 on Tap live keys.** The plugin is edited by re-uploading a freshly
built ZIP in WP admin (there's no CI for `wp-plugin/`).

**WhatsApp staff assistant — quote, book, documents, payment links by chat (built 2026-09-17, merged + deployed 2026-09-20):**

An INTERNAL bot: an authorised Wanaan employee messages it on WhatsApp in
Arabic or English and it does ERP work as their ERP user. Built from Qassim's
brief (`WANAAN-WHATSAPP-STAFF-ASSISTANT-BRIEF-v2.md`). No n8n; everything is in
the ERP, inside the **WhatsApp module** (`Modules/WhatsApp/Assistant/`).

| Concern | Location |
|---|---|
| Webhook | `AssistantWebhookController` — `GET/POST /integrations/whatsapp/{workspace}/webhook` (public, CSRF-exempt `integrations/whatsapp/*`, POST `throttle:240,1`). **Workspace in the path** because the Meta secrets are per database; an unknown/deleted id 404s (never falls through to Main — same rule as the pricing API). Verifies `hub.verify_token` / `X-Hub-Signature-256` against **that database's** `whatsapp_configuration` (the existing WhatsApp tab — the brief's `wa_*` rows were NOT duplicated). POST answers 200 at once and does the work in **`defer()`** (after the response, same PHP worker) — Meta retries slow webhooks, and the queue only drains once a minute |
| Orchestrator | `StaffAssistant::handle()` — order IS the security model: (1) dedupe on Meta message id (`whatsapp_messages.wa_message_id` unique); (2) kill-switch off → silence; (3) unknown/paused number → ONE "not authorised" reply then silence (`whatsapp_conversations.state = unauthorized`); (4) a pending proposal + an explicit YES (regex, EN+AR) → execute; NO → cancel; anything else → the proposal is dropped (staff is revising); (5) otherwise → the AI. **Confirm-before-create is enforced by code, not the prompt.** Proposals expire after `Conversation::DRAFT_TTL_MINUTES` (30) |
| AI | `Brain` interface → `ClaudeBrain` (official `anthropic-ai/sdk` ^0.49, new composer dep). Beta messages API, default model `claude-opus-5`, `output_config.effort = low` (routing, not deep reasoning), 25 s timeout, 1 retry, server-side refusal fallbacks (`fallbacks: 'default'`, beta `server-side-fallback-2026-07-01`). Across turns only TEXT history is kept (`Conversation::history`, last 20); within a turn the SDK's content blocks are replayed unchanged. Max 6 tool rounds. System prompt + tool schemas in `AssistantTools` |
| Tools | Read: `get_services`, `get_fare`, `find_booking`. **Propose only** (write nothing): `propose_booking`, `propose_quotation`, `propose_document` (invoice / service_order), `propose_payment_link`. A successful proposal ends the turn with a **code-generated** confirmation (`Replies`) — the AI never paraphrases an amount the staff member confirms |
| Fares | **New core `App\Erp\Pricing\FareCalculator` + `FareResult`** — prices a trip from `pricing_*` (rate × round-trip `return_factor` + extra hours × per-car rate − live offer % when the car is on the offer and the TRAVEL date is in its window). Missing rate/option/return factor/extra-hour rate = `found=false`, never a number. This is the agreed "Phase 2" fare source; the booking/quotation FORMS don't use it yet |
| Actions | `AssistantActions::execute()` (only after YES) — **re-reads the fare** (never the proposal's amount). Booking: find-or-create `LimoCustomer` by phone (last-8-digit match), `payment_method = online`, advance 0, `prepared_by` = the ERP user, one leg in the **queue with no driver/car** (dispatch assigns; the car TYPE goes in `vehicle_details`), offer shown as leg `discount`, then `recalcTotal` + `syncPaymentFromAdvance` + `syncInvoice` (same as `BookingForm`). Quotation: `LimoQuotation` + leg → `QuotationPdf`. Documents: `LimoInvoicePdf` / `ServiceOrderPdf`. Payment link: `LimoPaymentLink` → existing `ServiceOrderPortalClient::push()` (deleted if the push fails), amount capped at the balance NOW. All PDFs go to Meta's media store then as a WhatsApp document (never a public URL) |
| Permissions | Runs as the mapped user (`Auth::setUser` for the turn). Checked in BOTH propose and execute via `AccessControl`: booking = `limousine.booking` Create; quotation = `limousine.quotation` Create; invoice = `limousine.invoice` Read; service order = `limousine.booking` Read; payment link = `limousine.booking` Write (same as the bookings screen) |
| Paid notice | New `Modules\Limousine\Events\LimoPaymentLinkPaid`, fired by `PaymentCallbackController::settle()` **after commit, only for the delivery that settled it** (listener failure is caught). `NotifyStaffOfPayment` (listened to by string name in `WhatsAppServiceProvider::boot`) tells the conversation that raised the link — "Paid ✅" or part-paid + balance — once (`whatsapp_assistant_payment_links.notified_at`) |
| Replies | `MetaMessenger` — synchronous Graph API text + document (media upload), 15 s timeout, never throws, every outbound logged to `whatsapp_messages`. Wording in `Replies` = the brief's appendix (EN/AR); language detected per message (`\p{Arabic}`) |
| Audit | `ActivityLogger` under the acting user: `quoted` (new action code), `created` (booking/quotation), `invoiced`, `updated` (service order / payment link), settings changes |
| Schema | WhatsApp module migration `2026_09_17_300001`: `whatsapp_assistant_configuration` (AI key encrypted, model, enabled), `whatsapp_assistant_staff` (phone digits unique → user_id), `whatsapp_conversations`, `whatsapp_messages`, `whatsapp_assistant_payment_links`. Deploy's WhatsApp migrate step + `workspaces:migrate` apply it |
| Settings | `AssistantSettings` at `/app/settings/whatsapp-assistant` (admin-only), a **"WhatsApp assistant"** pill (only where WhatsApp AND Limousine are installed): master switch, Claude API key (write-only), model, authorised numbers → ERP user, and the per-database callback URL to paste into Meta. English by design (integration tab) |
| Deploy | `deploy.yml` gained `module:install whatsapp` on Main (the webhook route only registers while installed there) |
| Tests | `tests/Feature/WhatsAppStaffAssistantTest.php` (18 — Meta verify, unknown database 404, bad signature, duplicate id once, unknown number once, kill-switch, AR in/AR out, fare from tables + missing not guessed, quote tool result, nothing booked before YES + booking shape, NO cancels, stale proposal, fare re-read at YES, no-permission can't book, quotation PDF, invoice PDF, payment link + paid notice once, settings admin-only). The AI is a scripted `Brain`; Meta/portal via `Http::fake` |

**To go live (Qassim/Mohammed, not code):** in workspace 7 → Settings →
WhatsApp: Phone number ID, WABA ID, permanent access token, app secret, verify
token. Settings → WhatsApp assistant: Claude API key, add `97338467744` → Qassim's
ERP user, switch on. In Meta: callback URL shown on that tab, the same verify
token, subscribe `messages`. **Not built:** voice/image messages (asks for text),
multi-leg bookings, editing an existing booking by chat, driver assignment.

**In-ERP test chat — try the same bot with no WhatsApp/Meta at all (added
2026-09-20, same branch).** Meta wasn't set up yet, so the assistant needed a
way to be tried and reviewed before that. `StaffAssistant` never talked to
Meta directly — it always went through a small `ReplySink` interface — so a
second channel is a second implementation of that interface, not a second
assistant. Nothing about the confirm-before-create rule, the tools, the fares
or the permissions changes between the two; only how a reply is delivered does.

| Concern | Location |
|---|---|
| Channel split | `ReplySink` (new interface: `sendText`/`sendDocument`) — `MetaMessenger implements ReplySink` (unchanged behaviour, real Graph API calls). `WebReplySink` is the second implementation: captures each reply in `$messages` instead of sending it anywhere, and writes a document's raw PDF bytes to **private** storage under an unguessable path (`whatsapp-assistant-chat/{conversationId}/{random40}.pdf` — keyed by the numeric conversation id, never the `wa_id` string, which carries a colon that isn't a legal path segment on every OS this runs on). Both share the identical "write an outbound transcript row" logic via the new `RecordsOutboundMessages` trait, so the transcript can't drift between channels |
| Orchestrator | `StaffAssistant::converseAsWebUser(User $user, string $text)` — the web-channel twin of `handle()`. No phone to resolve and nothing to refuse (the page itself decides who may open it), no Meta message id to dedupe (there's no webhook redelivery to guard against in a synchronous request), and deliberately does **not** touch `Auth::setUser()`/`forgetUser()` — the caller is already that exact authenticated session, and forgetting the user mid-request would risk the rest of THAT SAME request rendering as signed out. Past that setup it calls the identical private `converse()` every WhatsApp message runs through. Conversation is keyed `web:{user id}` (fits the existing `wa_id` string column; no migration) |
| Page | `Modules\WhatsApp\Livewire\AssistantChat` at `/app/settings/whatsapp-assistant/chat` (admin, OR a phone number in `whatsapp_assistant_staff` mapped to the viewer's own account — same population that could use the real bot). Builds `StaffAssistant` itself with `app()->make(StaffAssistant::class, ['messenger' => new WebReplySink()])` rather than resolving it through the container (the container's own `ReplySink` binding is `MetaMessenger`, the correct default for the real webhook). Renders the conversation straight from `Conversation::history`; a document turn surfaces as a "Download" button that streams the stored PDF back (`response()->streamDownload()`, scoped to the viewer's own conversation folder) |
| Housekeeping | `deploy.yml` rsync excludes `storage/app/private/whatsapp-assistant-chat/` (else `--delete` wipes it every push — the standing rule, see `[[rsync-delete-wipes-user-uploads]]`). Daily `prune-whatsapp-assistant-chat-files` (`routes/console.php`) drops anything older than a day — these are meant to be downloaded once, right after the turn that produced them |
| Discoverability | A "Try it here (no phone needed)" link on the Settings → WhatsApp assistant tab |
| Tests | `tests/Feature/WhatsAppAssistantChatTest.php` — page gate (admin in, mapped staff in, unrelated account 403), a plain question gets a reply rendered as a bubble with no Meta HTTP call made, a confirmed booking goes through the same YES/NO flow and actually creates the booking, a confirmed document proposal can be downloaded and the bytes match what the PDF service rendered, disabled config shows the same "not available" wording the WhatsApp channel shows |

**Long-term memory (added 2026-09-29).** The chat only replays its recent turns
(`Conversation::HISTORY_LIMIT`, raised 20 → 60), so anything meant to outlast
that is a **saved note**: `whatsapp_assistant_memories` (WhatsApp module
migration `2026_09_29_300002`, model `AssistantMemory`, `user_id` + `text`).
Two tools, `save_memory` / `forget_memory`, both **scoped to the acting ERP
user** (one person can never read or delete another's notes, whatever id the
model passes). Every turn, `AssistantMemory::promptBlock()` is appended to the
system prompt as `[id] text` lines, explicitly as data under the rules: **a
price in a note is never a fare**, and charging one still goes through the
admin-only `override_amount` check. Capped at `MAX_PER_USER` (50) notes of
`MAX_LENGTH` (1500) chars so every message stays small. Per user, so the same
notes follow a person between WhatsApp and the test chat, which lists them in a
"Saved notes" panel with a Forget button (`AssistantChat::forgetMemory`).
Tests: `WhatsAppStaffAssistantTest` (+4) and `WhatsAppAssistantChatTest` (+1).

**Not this feature's job:** `NotifyStaffOfPayment` (a payment link getting
paid) still always sends over real WhatsApp — a payment link raised from this
test chat waits on a real payment, so there was nothing to route back to the
chat, and this was never meant to replace WhatsApp for that notification.

**Four more capabilities, each scoped to a real ERP permission — never a
claim in the chat (added 2026-09-20):** live testing of the bot surfaced a
request for it to "obey" things it was correctly refusing (a custom price, a
sales total) — refused because the assistant is deliberately unable to trust
what a message CLAIMS about who is asking; it only ever trusts the real,
authenticated account behind whichever phone (or `web:` session) is talking.
Rather than weaken that, each ask became its OWN tool, gated on the actual
permission it needs:

| Capability | Gate | Notes |
|---|---|---|
| **Admin price override** — `override_amount` on `propose_booking`/`propose_quotation` | `User::isAdmin()`, re-checked at BOTH propose and confirm | Never a free-text price the model invents: the AI may only pass one through when the staff member explicitly names a specific figure, and the system checks the REAL account, not the claim. `AssistantActions::withOverride()` builds a flat-price `FareResult` (discount zeroed — a hand-set figure is not "table price minus a %"); `applyOverride()` re-derives it at execute time so a demotion between propose and confirm can't slip through. Every use is logged (`logOverrideIfAny()`, action `updated`, description names both the charged and table price) — this is the auditable trail asked for, not a silent switch |
| **Edit an existing booking** — `propose_edit_booking` | `limousine.booking` Write | Pickup date/time, pickup/drop-off place, requested car (free text into `vehicle_details`) — deliberately NOT price, driver or vehicle assignment, which stay dispatch's job (mirrors the existing rule that a bot-made booking always leaves `driver_id`/`car_id` null). Refuses a cancelled/completed booking. `AssistantActions::editBooking()` logs the PRE-edit values (`updated`, JSON snapshot) so a change can be read back later |
| **Sales & revenue summary** — `get_sales_summary` (today/yesterday/this_week/this_month/custom) | `User::isSuperAdmin()` | Revenue is locked to the owner everywhere else in this ERP (the dashboard cards, the Rental/Limousine money bands) — a chat channel is not an exception. Reuses the existing `App\Erp\Targets\RevenueTargets::{earned,outstanding,windowBounds}` (the same figures the money-band UI shows), so a chat answer can never disagree with the dashboard. A regular admin (not super) is refused exactly like the ERP screens refuse them |
| **Customer lookup** — `find_customer` (name or phone) | `limousine.booking` Read | Profile, recent bookings, balance owed across ALL their bookings — the thing `find_booking` couldn't do (one booking by reference only). No revenue exposure, just what a staff member already sees opening the Customers screen |
| Tests | `tests/Feature/WhatsAppStaffAssistantTest.php` (+8 — admin override books at the override price and logs it, a non-admin with real booking permission is still refused the override, editing a booking changes place/time without re-pricing it, a cancelled booking can't be edited, the owner gets real figures, a regular admin is refused, a customer is found with their booking count, lookup is permission-gated) |

**Deliberately NOT built, and said so to the owner:** a generic "do anything a
super admin can do" capability. The bot's reach grows one named, permission-
checked, logged tool at a time — never a backdoor with no boundary, however
trusted the person asking is, because the bot is reachable by a phone (or a
browser tab) and a stolen device must never be worth more than the one
specific thing it was authorised for.

**Ad calendar — when to advertise, from what actually sold (shipped 2026-09-07):**

Admin-only page at **`/calendar`** (dashboard "Reports & Team" tile), one per
database. Ads work when they run BEFORE demand, so for every selling window
ahead it shows last year's takings for the SAME window and the date the ads
must be live by. Built for Wanaan (Rental + Limousine) first; works in any
database from whatever revenue sources it runs.

| Concern | Location |
|---|---|
| Hijri engine | `App\Erp\Calendar\Hijri` — pure-PHP tabular (civil/"Kuwaiti") Gregorian⇄Hijri via Julian Day Numbers; **no `intl` dependency** (not guaranteed on the host). Round-trips exactly; sits within the usual ±1 day of the moon-sighting date, irrelevant to multi-day windows. `fromGregorian()` / `toGregorian()` / `daysInMonth()` |
| Windows | `App\Erp\Calendar\EventWindow` (readonly VO: key, label, start, end, kind `islamic\|national\|custom\|closed`, country, `hijri` flag). **`lastYear()` shifts an Islamic window by a HIJRI year** — Eid last year lines up with Eid this year even though they're 11 days apart on the wall calendar. That one method is the "smart" part |
| Known seasons | `App\Erp\Calendar\KnownEvents::between($from, $to, $markets)` — shared Islamic windows (Islamic New Year, Ashura, Mawlid, Isra & Mi'raj, Ramadan, Eid al-Fitr 1–4 Shawwal, Eid al-Adha 9–13 Dhu al-Hijjah; country `GCC`) plus **per-country national days** (`COUNTRIES`: BH/SA/KW/AE/QA/OM; Qatar Sports Day = 2nd Tuesday of Feb). A long weekend in Saudi/Kuwait/Qatar is a busy weekend in Bahrain, so a database picks which **markets** count |
| Owner's own | `calendar_events` (core migration `2026_09_07_100001`, per database) + `App\Models\CalendarEvent` — name, dates, `kind` (`custom` = a season, `closed` = not operating), `recurs` (same Gregorian dates yearly). `windowsBetween()` projects recurring ones onto each year |
| Sales | `App\Erp\Calendar\SalesHistory` — per-day revenue from `rental_orders` (start_date, not cancelled), `limo_bookings` (pickup_at, `amount`, not cancelled), `pos_orders` (ordered_at, Done). Sources = table exists AND `Features::moduleAllowed()`. `firstRecordDate()` — **days before the first sale, and closures, are "no data", never "no demand"**, so an imported history can't invent a dead season |
| Planner | `App\Erp\Calendar\AdPlanner` — `heatmap()` (12-month day grid, five shades by quantile, event kinds per day, best/slowest month), `baseline()` (MEDIAN weekly revenue over 52 weeks, so Eid can't inflate "normal"), `plan()` (windows in the next `HORIZON_DAYS`=120: last-year revenue via `lastYear()`, uplift vs a normal week, per-app `launch.by` = start − lead days, status `overdue\|now\|live\|upcoming\|passed`), `unnamed()` (weeks ≥1.5× / ≤0.5× baseline with no known window — "name it"). **Rules per database** via settings `adcal.lead_days.{rental,limousine,pos}` (defaults 14/7/3) + `adcal.markets` (JSON, default `["BH","SA"]`); `SettingsPage::canSee()` hides `adcal.*` from the central page |
| UI | `App\Livewire\Pages\AdCalendar` + `resources/views/livewire/pages/ad-calendar.blade.php` — KPI row, plan cards, heatmap (`dir="ltr"`, Fri–Sat underlined, hatched = no data), unnamed weeks, rules form, owner events CRUD modal. Route `/calendar` in core `routes/web.php` |
| Tests | `tests/Feature/HijriCalendarTest.php` (11) · `tests/Feature/AdCalendarTest.php` (14 — incl. Eid-compared-with-last-Eid-by-Hijri-date, launch-by/overdue, closure = no data + excluded from baseline, unnamed peak) |

**Not built yet (v2):** overlaying actual Google/Meta ad spend by date (the ads
accounts are connected via MCP, not the app) so campaigns can be judged against the
sales they moved; per-country Eid lengths (the shared Islamic window covers the
longest official break); Saudi school holidays (variable, add as owner events).

**Revenue is the owner's alone, with monthly + yearly targets (shipped 2026-09-10):**

The Rental and Limousine dashboards each carried a gradient **Revenue** card
showing all-time collected income to anyone who could open the screen. That
figure is the whole business's takings, so it is now **super-admin only**, and
it sits in a gated "Money" band together with a **monthly** and a **yearly**
target measured against it.

**The targets are gated for the same reason as the revenue card, not as an
extra.** A box reading "62% of target" plus a target of 40,000 hands the income
to anyone who can divide, so gating the card while leaving the targets on screen
would gate nothing. Do not "helpfully" show the targets more widely.

| Concern | Location |
|---|---|
| Engine | `App\Erp\Targets\RevenueTargets` — `monthly()` / `yearly()` / `set()` (settings-backed, so **per database**), `earned(app, from, to)`, `outstanding(app, from?, to?)`, and `progress(app, now?)` which returns everything the three boxes render |
| Storage | `targets.{rental,limousine}.{monthly,yearly}` in `ir_config_parameter`. **`SettingsPage::canSee()` hides the `targets.` prefix** (like `features.` and `adcal.`) — `SettingManager::persist` would otherwise drop them into the General group, handing a regular admin both the figure and the box to change it |
| Edit path | `App\Livewire\Concerns\EditsRevenueTargets` (shared trait; host supplies `targetsApp()`). `openTargets` / `closeTargets` / `saveTargets`, each `abort_unless(isSuperAdmin)` — **re-checked on the action**, since Livewire dispatches straight to a method and a mount-time gate is not a gate. Writes an `ActivityLogger` `settings_updated` entry |
| UI | `resources/views/partials/revenue-targets.blade.php` (+ `revenue-targets-unpaid.blade.php`), `@include`d by both `rental::home` and `limousine::home` inside `@if ($isSuperAdmin)`. One partial, so the two dashboards cannot drift; each passes its own `gradient` and `unpaidHref` |
| Tests | `tests/Feature/RevenueTargetsTest.php` (24) |

Decisions to keep:

- **Monthly and yearly are stored independently, NOT yearly = monthly × 12.**
  Trade is seasonal — Eid and the F1 weekend are not a twelfth of the year each
  — so a derived annual figure would be wrong in both directions.
- **Each app counts revenue its own way.** Rental is `total - outside_cost`
  (**net of outside vendors** — markup, not gross); Limousine is the `fare`.
  ~~on paid orders~~ — **superseded 2026-09-26, see "The money band counted the
  wrong thing" below: it is now all WORK DONE, paid or not.**
- **"Not set" is a real state, distinct from a target of zero.** A blank box
  clears the target and the box shows "Set a target" rather than 0% attained.
- ~~**Attainment counts COLLECTED money only**~~ — **superseded 2026-09-26:
  attainment is measured against WORK DONE, with paid and still-owed shown
  beside it.** Each box carries what is **still owed** in its own window. A month at 60% with a big unpaid pile is a collection problem; the
  same 60% with nothing owed is a sales one. The two apps owe differently — a
  rental order has a settled `balance` column, a booking owes `fare - advance`
  floored per row — and both rules live in `outstanding()`.
- **The year is also shown as a PACE figure** (attainment against the part of
  the year already elapsed), because comparing a part-year against a whole-year
  target reads as failure every month until December.
- **Rental's "Unpaid" link left the revenue card and became its own KPI tile**
  in the Orders grid, visible to everyone. Chasing a balance is counter work,
  not a report on the takings — gating the revenue card must not take it away.

**Date-window gotcha (this bit an implementation and will again).**
`rental_orders.start_date` is declared `date()`, but Eloquent's `date` cast
writes **`"2026-09-01 00:00:00"`** into SQLite, while MySQL stores the bare
`"2026-09-01"` — and SQLite compares either as a **plain string**. So a period
must be bounded with a **date lower bound and a datetime upper bound**
(`$from->toDateString()` … `$to->endOfDay()->toDateTimeString()`). Bounding both
ends the same way silently drops a whole day at one end or the other, which is
exactly how a target starts under-reporting without anyone noticing. Pinned by
`test_a_sale_on_the_first_day_of_the_month_counts_toward_it`.

**The money band counted the wrong thing — fixed 2026-09-26.** The owner
looked at live Rent A Car figures and said they felt wrong: September collected
**0.00**, 2026 earned **3,459** against **160,502** all time, a monthly target of
**150 BD "added up from 21 cars"**, and a yearly box celebrating **"192% target
met, ahead of pace 261%"**. All four were real defects, all mine:

1. **"Collected" counted only hires PAID IN FULL, dated by start.** A 500 BD hire
   with a 300 BD deposit counted as zero collected AND zero earned. Every figure
   now starts from **work done** — `RevenueTargets::work(app, ?from, ?to)`
   returns `earned` / `paid` / `unpaid` / `vendors` / `jobs`:
   - Rental: `state IN (active, closed)` (a draft is a reservation that has not
     run; cancelled never will), earned = `total − outside_cost`.
   - Limousine: `status != cancelled`, earned = `fare`.
   - **The target is measured against work done; paid and still-owed sit beside
     it** (the owner's choice). The schedule and the dashboard use the same
     definition, so they still reconcile.
2. **Rental money reaches an order by two roads that never meet.** Counter
   money lands on `advance_amount`; money paid against an invoice lands on the
   invoice, and `RentalInvoice::recomputePaid()` only flips the order to `paid`
   once the invoice is paid **in full** — via a raw `update()` that never
   touches the order's `balance`. So neither `balance` nor the flag is enough
   alone. Paid = `total` if flagged paid, else
   `min(total, advance_amount + Σ receipts on invoices where invoice.order_id = order.id)`.
   **No double count on history:** the importer put every dinar received on the
   order's advance and left imported invoices' `order_id` NULL. The receipts are
   summed in ONE grouped join query, never a `whereIn` of ids (a year of orders
   is more ids than SQLite binds). **The source defect — an invoice payment not
   updating its order — is still there; this reads around it.**
   Limousine is fine: every payment through the booking lands on `advance`, and
   an invoice-settled booking is flagged paid.
3. **The fleet count lied.** `fleet()` reported `COUNT(*)` of all active cars as
   the number contributing. It now returns `cars` (with a target) AND `fleet`
   (all), and **a fleet target only exists when every car carries one** — one car
   in 21 is shown as a gap to fill ("Only 1 of your 21 cars have a monthly
   target…"), never used.
4. **No yearly target is derived any more.** 12 × monthly is gone — it was the
   1,800 BD the page was celebrating. A year nobody typed has no target.

Also: the green card now shows **cash collected all time** (part-payments
included) with **"Owed from earlier months"** under it — this month's owed lives
in the month box, where the team chases it. Tests: `RevenueTargetsTest` (part-paid
deposit counts, invoice receipts count for the order, invoice-settled order owes
nothing, older debt kept apart, reservations/cancelled excluded, target measured
against work done) + `TopCustomersTest` (fleet target needs every car, the box
says how many still need one, no yearly invented).

**Known gaps, left deliberately:** `TopCustomers` still ranks by fully-paid jobs
only (same class of bug, not in this fix's scope — a part-paying regular is
missing from the call sheet); and a Limousine part-payment recorded against an
INVOICE rather than through the booking never reaches `advance`.

**The money band shows its working, and names who to call (shipped 2026-09-10):**

Built straight on top of the revenue/targets band above. Three additions, all
**super-admin only** for the same reason the revenue card is — each of them
states, or gives away, what the business earns.

| Concern | Location |
|---|---|
| Where the money came from | `App\Erp\Targets\RevenueSchedule::forWindow(app, from, to, targetFactor)` — Rent A Car groups by **car**, Limousine by **car type** (it has no vehicle register). Rendered by `resources/views/partials/revenue-schedule.blade.php`, folded away behind "Where it came from" inside each target box |
| Where the target came from | `RevenueTargets::fleet('rental')` = `SUM(monthly_target)` over **active, owned** vehicles + the count. Rendered by `partials/revenue-target-source.blade.php` |
| Who pays us, and who stopped | `App\Erp\Customers\TopCustomers::forApp(app, now, limit)` + `partials/top-customers.blade.php` — top 15 by money collected over a rolling 12 months |
| Tests | `tests/Feature/TopCustomersTest.php` (21) |

**The schedule never disappears.** The first cut rendered nothing at all when
a period had no collected money, so on a live database whose current month had
no paid rows the owner went looking for the breakdown and could not find it —
a control that vanishes is indistinguishable from a feature that was never
built. An empty period now says "Nothing collected in this period yet.", and
the closed state names the biggest single source so the panel is worth
something before anyone clicks it. Pinned by
`test_a_period_with_no_money_says_so_instead_of_vanishing`.

**The schedule must reconcile with the box above it.** Same paid-only filter,
same bounds, and everything past the top 8 folded into an "others" row rather
than dropped — including money earned against **no car at all**, which is real
money. A breakdown that does not add up to its own headline teaches people to
distrust both. Pinned by `test_the_schedule_adds_up_to_the_figure_in_the_box_above_it`.

**A target can now derive itself from the cars.** Every vehicle already carries
a `monthly_target` (set on the car page, reported on Reports → Targets), so
when the owner has typed no monthly target the fleet total is used and the box
says "Added up from 12 cars' own monthly targets". What the owner typed always
wins. A derived **year** is labelled an **estimate** (12 × the monthly) and says
so, because twelve equal months is not how this trade runs — the same
seasonality argument that keeps monthly and yearly stored independently.
Limousine has no vehicle register, so it has no fleet figure and always types
its targets.

**Each customer is judged against THEIR OWN booking rhythm.** This is the whole
point of the call sheet and the thing not to "simplify" later:

- A company that hires every three weeks and has been quiet for eight has a
  problem. A family that hires once a year and has been quiet for eight weeks
  is behaving completely normally. One company-wide "quiet for 60 days" rule
  calls both the same thing and is therefore **wrong about one of them every
  time**. Pinned by the pair `test_a_regular_customer_who_has_stopped_is_flagged`
  / `test_an_occasional_customer_quiet_for_the_same_time_is_not` — both quiet
  for exactly 60 days, opposite verdicts.
- The rhythm is the **MEDIAN** gap between jobs, never the mean: one long break
  in an otherwise fortnightly customer would drag an average far enough to
  excuse almost any silence.
- Rhythm is read from the customer's **whole history**, not the 12-month
  ranking window, or a customer of ten years reads as "new".
- A **7-day grace floor** (`GRACE_DAYS`) stops a daily customer being called
  "lost" for being one day late.
- Statuses: `active` (within 1.25× their gap), `slipping` (to 2.5×), `lost`
  (beyond), `new` (fewer than two jobs). Retune via the constants.

**Reworked 2026-09-10 after the owner saw it on live data.** The first cut
ranked over TWELVE months in ONE list, and on real data that filled with people
who hired once and were last seen 290-350 days ago - every row reading "too few
jobs to know their rhythm". A list of strangers, not a call sheet. Three changes:

- **Six months, not twelve** (`WINDOW_MONTHS`), as whole calendar months so the
  columns line up with months anyone would name out loud. This alone removed
  every stale row from the owner's screenshot.
- **Companies and individuals are ranked APART** (`GROUPS`), as client-side
  tabs. A handful of corporate accounts otherwise crowd out every individual
  and half the business never gets looked at. A customer whose `type` is blank
  is treated as a person - an imported row has to land somewhere, not vanish.
- **Every row carries its own month-by-month record** across those six months,
  so "going quiet" is something the owner can SEE rather than trust.

The rhythm is still read from the customer's **whole history**, not the
six-month window, or a customer of years reads as new.

**Two queries, not N+1.** One grouped query ranks the top 15; one more pulls
those 15 customers' entire paid history, and rhythm, trend and last-seen are
all computed in PHP from it. Adding a query per customer for any of those is
the mistake to avoid.

**Unpaid and anonymous work are excluded from the ranking**, since neither is
money collected from someone we can ring — but anonymous money still counts in
the `collected` total the shares are a percentage of.

Every date comparison in this feature goes through
**`RevenueTargets::windowBounds()`**, which is the one place the date/datetime
rule below is decided. Use it for any new query here rather than writing bounds
by hand.

**Fleet earnings — is this car worth owning (shipped 2026-09-10):**

Owner-only page at **`/app/rental/fleet`**, linked from the Rent A Car money
band. Keeps the month-by-month matrix the office recognises (the same shape as
Reports → Sales) and puts a scorecard under every row.

**Why a revenue matrix was not enough.** It ranks a fleet BACKWARDS. A car that
earned 24,000 over 300 rented days is a worse asset than one that earned 17,000
over 120, and a revenue column puts the first one top. Pinned by
`test_the_car_that_billed_more_can_be_the_worse_asset`.

| Concern | Location |
|---|---|
| Engine | `Modules\Rental\Support\FleetPerformance::report()` — per-car months, utilisation, revenue per available day, maintenance, net, pace, idle cost, verdict; plus the fleet summary |
| Page | `Modules\Rental\Livewire\FleetEarnings` + `rental::fleet-earnings` and `rental::partials.fleet-scorecard` |
| Exports | `Modules\Rental\Http\Controllers\RentalFleetExportController` (CSV / Excel / PDF / Print via the shared `TabularRenderer`), gated the same as the page |
| Per-car yearly target | `rental_vehicles.yearly_target` (migration `2026_09_10_900040`), edited beside the monthly one on the car page |
| Tests | `tests/Feature/FleetEarningsTest.php` (21) |

Decisions to keep:

- **Utilisation and per-day are measured against the year SO FAR**, not all 365
  days, or every car reads as a failure until December.
- **Revenue per AVAILABLE day is the ranking**, not per rented day and not
  total: a car earns nothing on the days it stands still and those days still
  cost money.
- **Idle cost uses each car's OWN achieved rate** (its list `daily_rate` when it
  never moved, so a car that earned nothing still shows the full cost of
  standing still). It is the page headline because it is the figure the owner
  can act on today.
- **`underused` and `behind` are different verdicts.** Not hired often enough is
  a demand problem; hired constantly but cheaply is a pricing one. They look
  identical in a revenue column and need opposite fixes.
- **A hire is clamped to the window**, so one running December into January is
  not counted twice, and an open hire counts up to today.
- **A retired car that earned is shown but contributes 0 available days** — we
  do not record when it left, so counting it as available all year would invent
  idle days it never had. A retired car that earned nothing is dropped entirely.
- **"Others" (money billed against no car) stays**, as it did in the old report,
  or the page would disagree with the dashboard.
- **A car's yearly target is its own, not twelve monthly ones.** Left blank it
  falls back to 12 × monthly and the scorecard says on screen that it did.
- **Limousine earnings on the same car are added in** — the limo desk books out
  of this fleet, so a car's whole contribution was invisible while the two apps
  reported separately. The column hides itself when no leg carries a car, which
  is the case on imported data.

**Read by month (added 2026-09-26).** `FleetPerformance(int $year, int $month = 0)`
— 1-12 reads one month, 0 the whole year; `FleetEarnings::$month` (`#[Url]`,
`setMonth()` owner-gated, bounded 0-12) and the export's `?month=` follow it. In a
month every figure — rented/available/idle days, maintenance, limousine, idle
cost — is that month's, and a car is judged against its **monthly** target
(`row['target']`), never a year's target squeezed into one month. Pace is against
the elapsed share of the PERIOD, and a period not yet started has none. The
matrix keeps all twelve month columns (earnings are always read for the year)
and highlights the chosen one. Same day: the scorecard's "What it cost" printed
the NET figure (earned − maintenance) as its big number; it now shows the cost,
with "Left after costs" as its own line. Tests: `FleetEarningsTest` (+7).

**Limousine money on a car counts only real, dispatched trips (fixed 2026-09-26).**
`FleetPerformance::limousine()` summed every `limo_legs` row with a `car_id`,
including **quotation** legs and **cancelled** trips. It now joins `limo_bookings`
and applies the same rule as `LimoPerformance` (booking legs only, booking and leg
not cancelled). A car only earns limousine money when a trip has the car
**assigned from the queue** (`Bookings::saveAssign`). Imported trips and trips
never dispatched carry only free text in `vehicle` and reach no car. The read-only
`php artisan limo:car-usage [--workspace=] [--plate=] [--year=]`
(`Modules\Limousine\Console\CarUsageReport`, workflow `car-usage.yml`) prints
per month how much limousine money sits on trips with and without a car, the
vehicle text on the unassigned ones, and one car's own orders and trips.

**The limousine revenue breakdown was regrouped the same day.** It grouped by
`limo_bookings.car_type`, which no import ever filled, so a whole year of
takings rendered as one row reading "No car type recorded · 100%". `car_id` on
the leg is empty on historic data too. It now groups by the **service type** of
a booking's first leg (transfer / chauffeur), which is always set — while still
summing the BOOKING's fare, so the rows keep reconciling with the box above.
**Rule: pick the grouping field by what the data actually contains, not by what
the schema offers.**

**Two gotchas hit while building this:**

- `chunkById()` needs the primary key in the `select()`, or it throws "the
  chunkById operation was aborted because the [id] column is not present".
- **Use `url()`, not `route()`, for module links in a view.** A module's routes
  only register while it is installed, so a named-route lookup is fragile — and
  it breaks outright in tests, where an in-test install happens after boot. The
  test loads the module's routes by hand (`Route::middleware('web')->group(...)`),
  the same workaround the other module route tests use.

**Old driver names — the imported "driver" is a LOGIN, not a person (shipped 2026-09-12):**

The owner opened Limousine earnings and asked who "Kown" was, because no such
driver exists in the register. The answer: the previous system recorded the
driver as **the account that was logged in**, and `BookingImporter` copied that
column into `limo_legs.driver` verbatim. Across the 13,919-row Wanaan export the
column holds 112 distinct values, and they are three different things mixed
together:

| Kind | Examples | How it shows |
|---|---|---|
| A real driver's login | `habib` (1,220 trips), `sali`, `sohail`, `kown`, `smakhlooq`, `qmakki` | Many cars over several years, never appears in "Added By" |
| Office / owner staff | `admin` (1,448), `mariam` (1,014 trips but 1,581 bookings entered), `hassan`, `ali`, `qassim` | Also appears in the export's "Added By" column |
| Not a person | `via`, `apiuser`, `p`, `geasy`, `fone rent` | System logins and one supplier name |

Not a default, either: driver == whoever entered the booking on only **3.8%** of
rows, so these were deliberate assignments. `admin` was the catch-all, and its
use fell from 869 trips in 2023 to 21 in 2026.

**The fix resolves at READ time and rewrites nothing.** No backfill stamps
`driver_id`, and `limo_legs.driver` keeps the exact text the import wrote. Every
screen asks `DriverAliases` what the name means, so a match decided wrongly is a
match *changed* — there is never a history to repair. (An earlier design that
stamped the id was dropped for exactly this: un-stamping a wrong guess needs to
know which ids a rule had set, and nothing records that.)

| Concern | Location |
|---|---|
| Table | `limo_driver_aliases` — `alias` (unique, lowercased + space-collapsed), `driver_id` (logical ref to shared `rental_drivers`, no FK — Limousine installs without Rental), `is_office`, `decided_by`. **Both empty = seen but undecided**, which is why a cleared row is kept rather than deleted |
| Resolver | `Modules\Limousine\Support\DriverAliases` — `resolve()`, `aliasesFor()`, `candidates()`, `save()`. **Singleton** (bound in `LimousineServiceProvider::register`) so a 500-row queue asks once, not 500 times; `LimoDriverAlias::booted()` flushes it on every write so the cache cannot outlive its answer |
| Screen | `Modules\Limousine\Livewire\DriverAliases` + `limousine::driver-aliases` at **`/app/limousine/driver-names`** (its own path, never `/driver/names`). Gated on `limousine.driver`: Read to look, **Write to decide** |
| Readers | `LimoPerformance::drivers()` (league + new `office` count), `DriverJobHistory::trips()` (finds a driver's pre-ERP trips by the names he was recorded under), `LimoQueueRows::row()` (shows the person, not the login) |
| Tests | `tests/Feature/LimoDriverAliasTest.php` (16) |

**The system decides for itself first (`limo:match-driver-names`, added 2026-09-12).**
A hundred and twelve names is a job, not a question, and handing the owner a job
the records can mostly answer is the wrong way round. `Modules\Limousine\Console\MatchDriverNames`
runs **on every deploy** (in `deploy.yml`, after `optimize:clear` and wrapped in
`|| true` so a fleet without Limousine installed cannot fail the deploy on a
command that is not registered; across Main and every workspace) and decides each still-undecided login:

1. **A driver** when exactly ONE register driver produces the login from a
   WHOLE name — full name, name welded together, initial+surname, first+initial.
   **A half name (first name alone, surname alone) decides nothing**: it is
   offered on the screen and left there. This is the Mariam rule — she entered
   1,581 bookings AND is named on 1,014 trips, so if the register holds a
   "Mariam Hasan", half a name cannot say they are the same person.
2. **The office** when the login belongs to a `users` row — by name or by the
   part of the e-mail before the `@` — and no driver answers to it. Somebody who
   signs in here and is not in the driver register was booking, not driving.
   A person in BOTH lists stays a driver; the register is the list of who drives.
3. **Not a person** for `SYSTEM_LOGINS` — `admin`, `via`, `apiuser`, `mac`,
   `asprinter` (a Sprinter typed into the driver box), `fone rent`, `p`, `geasy`.

**The matcher may revise its OWN answers** (`limo_driver_aliases.auto`, added
by `2026_09_12_950034`) — rules improve, and an answer given under a worse rule
should not outlive it; a run that can no longer justify one WITHDRAWS it back to
undecided. A decision a PERSON made is never touched. `candidates()` therefore
computes a suggestion for auto rows too, or re-checking would see no match and
withdraw an answer that was right. The screen shows a **Decided by the system**
badge and a filter tab for reviewing exactly those rows — an answer nobody can
see is an answer nobody can correct.

**The screen carries the evidence, because the last mile is a person's.**
A login the matcher cannot settle is not a question anyone can answer from the
name alone, so each row also shows **the cars that name was driving** (top three,
from `limo_legs.vehicle`) and **the closest names in the register** (`similar_text`
>= 60% against the same forms, best three, display only — never acted on). The
office recognises "37398 FORD EXPEDITION every week for two years" when the login
means nothing to them. The command likewise prints the **drivers nobody has
claimed**: an unanswered login and an unclaimed driver are usually the two ends
of one missing match, and seeing them apart is what makes the pairing invisible.

Never overwrites a decision a person made, idempotent, `--pretend` to dry-run,
and it **prints the names it could not answer** so the screen has a short list
rather than a full one. Re-run after adding drivers to the register and the
logins that now have exactly one answer get it. Deciding automatically is only
safe because nothing is rewritten — being wrong costs a click, not a history.
Tests: `tests/Feature/LimoMatchDriverNamesTest.php` (12).

Rules worth keeping:

- **An undecided name reads as itself.** `resolve()` returns the raw text when
  no row exists, so an unanswered question never quietly becomes an answer.
- **A guess is offered, never saved.** `suggestions()` reduces each register
  driver to the forms a login is built from (full name, no-space, first,
  surname, initial+surname, first+initial) and suggests **only when exactly one
  driver produces that login** — two Alis make `ali` mean nothing. The guess
  arrives pre-filled in the box wearing an amber "check it before saving" note,
  and is written only by pressing Save.
- **Two spellings are one row.** `habib` / `Habib` / `  HABIB ` merge on the
  normalised key, so the office is asked once.
- **Office rows leave the league rather than joining `unnamed`.** The earnings
  page prints them on their own line, because "on an office account" and "no
  driver named" are different facts about the records.
- A matched login carries its **petty-cash advances** too — that column was a
  row of dashes purely because the text names pointed at no driver id.

Not done, and deliberate: the export's **"Added By"** column is still not in
`BookingImporter::HEADER_MAP`, so imported history has an empty `prepared_by`
and the screen cannot show "this name also entered N bookings" as a hint that a
login is office staff. Worth adding if that export is ever re-run.

**Duplicate-trip finder — read-only, built 2026-09-21.** The owner asked to
make new bookings' trip numbers start with a different leading digit than old
ones, then — on being shown that the old imported range already straddles
both "1" and "2" (16,074 imported trips numbered sequentially from
`LimoLeg::REFERENCE_START`=10000 naturally run past 20000) — suspected that
range is inflated by an actual duplicate-import mistake, not genuine trip
volume. `php artisan limo:find-duplicate-trips [--workspace=]`
(`Modules\Limousine\Console\FindDuplicateTrips`, registered in
`LimousineServiceProvider::boot()`) checks that suspicion against real data
**without changing anything**: it reruns `LegacyBookingImporter`'s own TWIN
heuristic (same customer, pickup time, fare) across everything already on
file rather than just at import time (so it also catches a pair that came in
through two different tools/runs that never cross-checked each other), plus a
same-booking duplicate-leg check. Iterates every workspace the same way
`MatchDriverNames` does (`Schema::hasTable` guard, per-workspace try/catch).
Workflow `find-duplicate-trips.yml` (`workflow_dispatch`, defaults to
workspace 7 / Wanaan) runs it over SSH.

**First live run + a false-positive fix, same day.** Against Wanaan: 15,502
bookings, 16,185 trips (highest reference 26,214); the raw fingerprint (same
customer + timestamp + fare) matched 586 "exact duplicate" groups. Reading
the actual rows showed most of those are **not** accidental duplicates — a
wedding or company outing books MANY real cars under one customer account,
all at the identical scheduled pickup time and the same fixed per-car fare
(one "Jain Wedding" cluster alone was 40+ bookings on one day) — the
fingerprint genuinely can't tell that apart from the same booking entered
twice, because it doesn't look at who was actually being driven. So each
group is now further classified: **"accidental-looking"** (every row shares
the same, or blank, passenger name AND the same, or blank, driver parsed out
of the free-text `notes` the legacy importer stuffed in — nothing tells the
rows apart) vs. **"probably a legitimate multi-vehicle booking"** (a
different passenger or a different driver on at least one row) — only the
first bucket counts toward the headline "accidental extras" total; both are
still printed in full so nothing is hidden. Tests:
`tests/Feature/LimoFindDuplicateTripsTest.php` (10 — exact/near/no
duplicates, a deliberate "Booking #…" cross-reference note is correctly
excluded, a duplicate leg on one booking, different-passenger and
different-driver groups are excluded from the count, a same-driver group is
still counted, writes nothing at all).

**Second live run (2026-09-21):** 836 rows out of 16,185 trips (~5%) came
back as accidental-looking after the group-booking refinement — a real
problem, but even removing all 836 still leaves ~15,349 genuine trips, which
alone crosses past 20000 with `REFERENCE_START`=10000. So the duplicate
problem and the reference-number-split question are separate — fixing one
does not resolve the other.

**The 836 is a heuristic shortlist, not a confirmed defect list — this
matters more than the count.** Reading `LegacyBookingImporter`'s own TWIN
guard closely (its doc comment on the `$twin` query in `importRow()`) shows it
deliberately never compares two already-imported legacy bookings against
each other, because "two old-system bookings carry two numbers and are two
trips (two cars at one time)". Whoever built the importer already decided
the old system's own booking-number granularity should be trusted as real,
separate trips by default. So the "accidental-looking" bucket can include
pairs the import was explicitly designed to treat as genuine — there is no
way to algorithmically resolve "double-entry mistake" vs. "two real cars
dispatched under the same driver placeholder" from the data alone; it needs
a human who knows what that meant operationally.

**`limo:review-duplicate-trips [--workspace=]`
(`Modules\Limousine\Console\ReviewDuplicateTrips`, workflow
`review-duplicate-trips.yml`, defaults to workspace 7/Wanaan) is the safe
next step, built 2026-09-21, instead of an automated cleanup.** It reuses the
identical grouping/classification logic (extracted into
`Modules\Limousine\Support\DuplicateTripGroups` — the single source of truth
both `FindDuplicateTrips` and this command call, so the two can never
disagree on what counts as a duplicate) and, for the accidental-looking
groups only, checks whether either row already has an **invoice or receipt**
recorded against it (`LimoInvoice.booking_id` / `LimoReceipt.booking_id`).
Splits into: **no money on either side** (the strongest candidates — printed
in full) vs. **money already recorded on at least one side** (printed as a
compact "do not delete without checking with accounts" list — a group with
money attached is almost certainly two real, separately-billed trips).
Entirely read-only; still decides nothing and deletes nothing. Tests:
`tests/Feature/LimoReviewDuplicateTripsTest.php` (6 — clean database, a
money-free group is the strongest candidate, a group with an invoice is
flagged not treated as clean, same for a receipt, a legitimate multi-vehicle
group never appears in the review at all, writes nothing).

**Third live run (2026-09-21):** the review shortlist came back as 252
accidental-looking groups (347 rows) across bookings alone — smaller than
836 because this review only checks EXACT+NEAR booking pairs, not the
duplicate-leg-on-one-booking check, which doesn't map onto "does either side
have money" the same way. Of those: **123 groups (188 rows) have no invoice
or receipt on either side** — the real shortlist. **129 groups (159 rows)
already have money recorded**, and in all but 3 of those groups, MONEY IS
RECORDED ON EVERY ROW IN THE GROUP, not just one side — read as strong
evidence these are genuinely separate, separately-billed trips (an
accidental duplicate wouldn't normally get separately invoiced and
receipted on both copies), matching exactly what `LegacyBookingImporter`'s
own design assumed. That bucket is effectively closed.

**The owner then asked to delete every trip numbered "1x,xxx" (the low end
of the 10000–26214 trip-reference range) and re-import fresh, on the theory
that old data starts with 1 and new data starts with 2.** Investigated and
explained rather than executed, because the plan as stated cannot work:

1. **Trips numbered 20000+ are NOT "new" ERP bookings** — nothing has ever
   created a trip organically through this ERP; the entire 10000–26214 range
   came from the SAME legacy CSV import, and a trip's number is purely a
   function of which row the import processed first (leg reference =
   `LimoLeg::REFERENCE_START` − 1 + the leg's own auto-increment id — see the
   "Old driver names" section above for the same "leg ids are pure import
   order, not preserved from the old system" fact used differently there).
2. **The math doesn't allow a clean split regardless of cleanup.** Only
   10,000 numbers exist for anything starting with "1" (10000–19999). Even
   after removing every genuine duplicate, ~15,300+ real trips remain —
   already past that budget. Deleting and reimporting the same historical
   range would not free up room; freshly reimported rows get NEW
   auto-increment ids continuing from wherever the counter sits (past
   26214), not restarting at 10000. A real "old vs. new" split needs wider
   number ranges (e.g. a 6-digit `1xxxxx` / `2xxxxx` scheme), which is a
   renumbering, not a delete-and-reimport.

**`limo_bookings.imported_at` (shipped 2026-09-21), added before any
renumbering, at the owner's request** — "add the time when the booking or
ref no. is added so we can later know which ref no. is old and which is
new." `limo_bookings.created_at` is deliberately backdated by
`LegacyBookingImporter` to the booking's ORIGINAL historical date, so it can
never answer "when did this row actually land in this database." New
nullable `imported_at` column (migration `2026_09_21_950036`) is the missing
signal: `LegacyBookingImporter` stamps it with the real wall-clock import
moment right next to where it backdates `created_at`; every other creation
path (`BookingForm`, the regular "Import" button's `BookingImporter`, which
never touches `created_at` at all) leaves it null. **Null therefore means
"created live in this ERP"; a value means "brought over from the old
system"** — reliable regardless of what reference number a trip ends up
with, and independent of the eventual renumbering decision. Not added to
`$fillable` (system-managed, never user-settable). **`limo_legs.created_at`
needed no equivalent column** — nothing anywhere backdates a leg's
timestamp, so it already faithfully records each leg's real insertion time;
this was verified by reading every leg-creation path, not assumed. Tests:
`LimoLegacyBookingImportTest::{test_the_import_stamps_when_the_row_actually_landed_here_separately_from_the_historical_date,
test_a_booking_entered_live_in_the_erp_has_no_imported_at}`.

**New trips follow the old sequence (changed 2026-09-29, the owner's choice).**
Trips entered in the ERP came out as 41,7xx: the trip number was
`REFERENCE_START - 1 + row id`, and the reimport (which forces leg ids to
`tripNumber - 9999`) plus quotation legs ran the id counter to ~31,7xx while
the real trip numbers stopped around 26,2xx. `LimoLeg::nextReference()` now
gives **one more than the highest numeric trip number on file** (longest-then-
highest, so numeric order; never below `REFERENCE_START`; skips taken numbers;
the `created` hook retries on a unique-index clash). A fresh database still
starts at 10000. Migration `2026_09_29_950037` renumbered the stray trips — any
numeric reference **≥ 30000 not from the historical import** (`imported_at`
null, or a quotation leg) — to follow straight after the highest number below
30000, in creation order, updating `limo_coupons.leg_reference`; backup first,
every old → new pair in the server log and the activity log. Known trade-off
the owner accepted over a 6-digit `2xxxxx` scheme: the numbers only start with
2 until 29999, and an imported trip numbered 30000+ would push new ones past it.
Numbers already printed or sent on WhatsApp before the change still show the
old 4xxxx value. Test: `LimoTripNumberSequenceTest`.

**…superseded the next day: live trips are numbered from 200001.** The belief
that old trips stopped at 26,2xx was wrong — the re-import had filled the
five-digit range up to ~41,7xx, so `950037` found no room and pushed the 52 live
trips ABOVE the old top (every one moved by exactly +52: 41748 → 41800). There is
no free five-digit number starting with 2. Migration `2026_09_30_950038` moves
every BOOKING trip entered live (booking `imported_at` null AND booking
`created_at` ≥ `LiveEntry::since()`) to **200001, 200002…** in creation order
(coupons follow; backup first; old → new in the logs). Only in a database that
went through the legacy import (any booking with `imported_at`); others keep
their own sequence. Quotation trips are left alone. `nextReference()` needed no
change — six digits outrank five, so new trips carry on from 2000xx. **Lesson:
never assert what live data looks like from a stale number; a renumbering needs
the real range checked first.**

**Next step, still pending an explicit decision:** run
`limo:review-duplicate-trips` against Wanaan and read the "no money
recorded" shortlist with someone who knows what a same-time/same-fare
"admin, no passenger" pair meant in the old system — only rows a human
confirms are genuine mistakes should ever be deleted, and even then take an
in-app backup (Activity Log → Backups → "Back up now") first. Separately,
if a real old-vs-new number split is still wanted, it needs a genuine
renumbering (wider ranges) — not a delete-and-reimport — and can now use
`imported_at` to know precisely which existing rows are "old" without
guessing from reference numbers.

**Limousine earnings — drivers, routes and demand (shipped 2026-09-10):**

Owner-only page at **`/app/limousine/earnings`**, linked from the Limousine
money band. The counterpart to Fleet earnings, built on different units because
**this desk has no car to rank**: no trip ever recorded one (`car_type` and the
leg's `vehicle` both come from an import column that was absent, and `car_id`
is never set by the importer). A per-car page here would be a single row
reading "not recorded" — the exact mistake the first revenue breakdown made.

| Concern | Location |
|---|---|
| Engine | `Modules\Limousine\Support\LimoPerformance::report()` — summary, drivers, routes, services, demand grid |
| Page | `Modules\Limousine\Livewire\LimoEarnings` + `limousine::earnings` |
| Tests | `tests/Feature/LimoEarningsTest.php` (18) |

Decisions to keep:

- **Everything counts TRIPS (legs), not bookings.** A driver drives a leg, a
  route is a leg, and an hour belongs to a leg — it is the only grain on which
  any of the three questions can be asked.
- **A section that cannot be built SAYS SO** rather than rendering an empty
  table. `drivers.available` / `routes.available` are false when no trip in the
  year names one, and the page explains what to record to fill it in. This is
  the whole lesson of the car-type mistake, made structural.
- **The headline is the AVERAGE FARE against last year**, not the total. A desk
  can run more trips than ever while discounting itself into trouble, and a
  trip count alone calls that a good year.
- **Routes are compared against the SAME route a year before**, never against
  the fleet average — otherwise a genuinely cheap route reads as a decline.
- **Driver and place names are trimmed and title-cased before grouping.** They
  are free text filled in by hand over years, so "RAMESH", " ramesh " and
  "Ramesh" are one person.
- **The best-paying demand slot needs at least 5 paid trips.** One lucky airport
  run at 400 would otherwise send the whole roster to an hour that never repeats.
- **`limo_legs` is shared with quotations**, so every query filters
  `legable_type` — without it a quote nobody accepted is reported as takings.
  Pinned by `test_a_quotations_legs_are_never_counted_as_takings`.
- **The night band wraps past midnight** and is the one range that cannot be
  tested with a plain `between`.
- **Petty-cash advances key on `driver_id`** while historic trips name a driver
  in text, so "Advanced" shows a dash for a name that was never a record. That
  is reported, not hidden.
- The demand grid is `dir="ltr"` — it is a grid of times, and mirroring it puts
  the week backwards.

The shared money-band partial takes an optional **`fleetLabel`**, because
"Fleet earnings" is the wrong name for a desk with no fleet.

**Saved logins — the credential store (shipped 2026-09-21):**

Admin page at **`/passwords`**, surfaced as an owner dashboard tile. The logins
a business runs on (hosting, payment gateway, social accounts, government
portals) kept in one place instead of scattered across Google Docs.

| Concern | Location |
|---|---|
| Schema | Core migration `2026_09_21_100001_create_vault_entries_table` — `name`, `url`, `username`, `password` (encrypted TEXT), `note` (encrypted TEXT), `owner_only`, `created_by`/`updated_by` name snapshots. **Core**, so every database gets its own and one business can never see another's |
| Model | `App\Models\VaultEntry` — `encrypted` casts, `scopeVisibleTo(bool $isOwner)`, `linkUrl()` |
| Page | `App\Livewire\Pages\Vault` + `livewire.pages.vault` |
| Tests | `tests/Feature/VaultTest.php` (18) |

Four rules, each easy to lose in a refactor and each pinned by a test:

- **A password is never in the page the browser first receives.** The list
  renders dots; the plaintext is fetched one entry at a time by a deliberate
  click. Opening the EDIT form is likewise not a reveal — the password box
  starts blank, and blank on save keeps the stored value, so a note edit cannot
  wipe a credential.
- **Every reveal is written to the activity log** (`vault_revealed`, coloured
  red so it stands out). If a credential ever leaks, "who looked at it" has an
  answer.
- **Visibility is a QUERY scope, not a view filter.** An owner-only entry never
  reaches a regular admin's browser at all — not even as a row to count. Every
  action (`reveal`/`edit`/`delete`/`save`) re-fetches through the scope, so a
  crafted id cannot reach one either.
- **`owner_only` defaults TRUE.** A new entry is private until somebody widens
  it deliberately, and only a super admin can change who sees an entry.

**The note is encrypted as well as the password** — deliberately. Notes are
where recovery codes and security-question answers end up, and those are worth
as much as the password they protect. Search therefore covers only `name`,
`username` and `url`: searching a note would leak whether a phrase is in one.

**Honest limit, stated on the page itself:** the key is APP_KEY on the same
server, so this defeats a stolen database file but not someone with both the
database and the application files. It is not a zero-knowledge password manager
and the page says so rather than implying otherwise.

**It shipped broken once, and the fix is worth keeping in mind.** The page was
first mounted at `/passwords` and 500d on the live site while every component
test passed. The breadcrumb in `components/layouts/app.blade.php` translates
each URL SEGMENT, so it called `__('passwords')`, which resolved to the
framework's own `lang/en/passwords.php` and returned an ARRAY for the layout to
print. Two changes:

- The route moved to **`/logins`**.
- **The breadcrumb now falls back to the raw segment when `__()` hands back
  anything but a string.** That was a landmine for any future route whose
  segment happened to name a language file (`auth`, `validation`, `pagination`).

**The test lesson is the bigger one: `Livewire::test()` never renders the
LAYOUT.** A page can pass twenty component tests and still 500 on a real
request. Every full-page Livewire component wants at least one real
`$this->get($url)->assertOk()` as a permitted user. Pinned by
`VaultTest::{test_a_real_page_load_renders,
test_a_url_segment_named_after_a_language_file_does_not_crash_the_page}`.

**Gotcha — `__('Passwords')` returns an ARRAY.** Laravel resolves a dotless key
with no JSON entry as a language-FILE group, and the framework ships
`lang/en/passwords.php` (the reset-link messages). On a case-insensitive
filesystem (macOS) `Passwords` matches it, the whole array comes back, and the
view dies in `htmlspecialchars()`. It would have behaved differently on the
Linux host. The page is called **"Saved logins"** — which is a better name
anyway, since "Passwords" reads like the user's own account password. **Never
use a bare `__()` key that collides with a file in `lang/<locale>/`.**

**Pricing API — the ERP as the only place a fare exists (shipped 2026-09-09):**

Wanaan published fares in four contradicting places (WooCommerce products, page
copy, the fare-widget plugin's built-in table, and whatever staff quoted on
WhatsApp). These tables are now the source; the website reads them over a signed
endpoint and caches the answer. **Phase 2, not built:** wiring the booking and
quotation forms to read the same fares, so staff stop quoting from memory.

| Concern | Location |
|---|---|
| Schema | Core migration `2026_09_09_100001_create_pricing_tables` — `pricing_cars` / `pricing_services` (string PKs: `sedan`, `airport`, quoted back by the website so never renumbered), `pricing_options`, `pricing_rates` (`decimal(8,3)` — the dinar is 1000 fils), `pricing_extra_hours`, `pricing_offers`, `pricing_version`. Core ⇒ lands in Main AND every tenant via `workspaces:migrate`, so each business keeps its own fares and its own version counter |
| Signing | **`PortalSignature::signRequest()` / `verifyRequest()`** — `"<METHOD>\n<PATH>\n<timestamp>.<raw-body>"`. The plain `sign()` covers only timestamp+body, which binds a POST but leaves a GET signing nothing but a timestamp: a signature for `/workspaces/7/pricing` would verify against `/workspaces/3/pricing`, so one captured read would open every database. **`sign()`/`verify()` are deliberately untouched** — the live service-order push and payment callback are signed the old way at both ends. Do NOT "upgrade" them |
| Read endpoint | `GET /api/v1/workspaces/{ws}/pricing` (`routes/web.php`, outside `auth`, `throttle:60,1`, CSRF-exempt via `api/v1/*` in `bootstrap/app.php`) → `App\Http\Controllers\PricingApiController`. ETag = the version integer, `304` on a matching `If-None-Match` (the common case). **The workspace is resolved with `find()` BEFORE `runFor()`**, because `runFor()` deliberately falls through to the current database for an unknown id — right for a job, wrong here: workspace 999 would have been answered with whichever business the connection happened to be. `find()` not `findAny()`, so a deleted database stops serving |
| Secret | Reuses the per-database `limo_portal_configuration.shared_secret` (encrypted, Settings → Service Portal). **Deliberately NOT gated on that row's `enabled` flag** — switching the payment portal off must not take the website's prices down with it |
| Payload | `App\Erp\Pricing\PricingPayload` — eager-loads everything (the 500ms budget dies to N+1 otherwise). Two rules enforced here, never trusted to the website: **a car with no rate is OMITTED, never published as `0`** (a zero on a public page is worse than a missing car; the hole is `Log::warning`ed), and **`offer.active` is resolved against the server clock — but only as an off/expired guard** (see the fixed-2026-09-10 note below for what `starts`/`ends` actually mean and why a not-yet-started offer still publishes). Amounts are JSON numbers with trailing zeros trimmed (`15`, not `15.000`) |
| Writes | `App\Erp\Pricing\PricingWriter::transaction()` is the ONE door. One transaction, **one version bump per save — not per row** (a grid save touches ~20 rates; a model observer would bump 20 times and make the site's cache stale 20 times over), and one `ActivityLogger` entry with old → new per cell. A save that changes nothing does not bump |
| Ping | `App\Erp\Pricing\PricingPortalPing` → `POST {portal}/wp-json/wanaan/v1/pricing/refresh`, body `{version, ws}` only. **Synchronous, 3s timeout, every exception caught** — queuing would mean up to a minute's staleness (once-a-minute cron) and would lose the workspace context. 5s `Cache::add()` debounce; the manual button passes `force: true`. **It carries no prices** — the site comes and fetches, so a forged ping can only make WordPress ask a question |
| Admin screen | `/fares` → `App\Livewire\Pages\PricingManager` (admin-only, dashboard tile). One tab per service, options × cars grid, saved in a single submit. **Refuses a save where an active option has a blank fare for an active car**, naming the cell — that is the failure that would otherwise publish a zero. Shows version + updated_at, and a "Send update to website" button for when the two look out of sync |
| Seeding | `php artisan pricing:seed --workspace=7` — idempotent, fails on an unknown workspace id rather than seeding Main. NOT in the deploy chain (deploy seeders run against Main; these fares belong to one business) |
| Tests | `tests/Feature/PricingApiTest.php` (39 — payload shape and real fares, trimmed amounts, unsigned/wrong-secret/stale-timestamp/wrong-workspace rejections, unknown workspace never falls through, 304, one bump per grid save, no bump on a no-op, activity log, inactive service/car dropped, missing rate omitted not zeroed, expired offers inactive, **not-yet-started offer still published so it can be booked ahead**, **starts/ends always published regardless of active**, signed ping carrying no prices, unreachable site never breaks a save, debounce, admin screen gate + blank-fare refusal + decimals + forced ping) |

**Offer window means travel dates, not booking dates (fixed 2026-09-10):** a
National Day offer dated 16–24 Sept was entered on the 10th and the website
still showed no discount — `PricingOffer::isLive()` originally required
*today* to fall between `starts_at`/`ends_at`, so a promo scheduled for the
future was published `active: false, percent: 0` right up until its first
day, which is backwards: `starts_at`/`ends_at` are the **travel** dates the
discount applies to (a trip taken between the 16th and the 24th), and a
customer has to be able to book that trip **today**, ahead of the window.
Fixed: `isLive()` now only checks the admin `active` flag and whether
`ends_at` has already passed — a not-yet-started offer publishes its real
`percent`/`label`/`cars` immediately. `starts`/`ends` were already published
unconditionally (even when inactive); **the website is responsible for
comparing the traveller's chosen pickup date against `starts`/`ends` before
applying `percent` to a specific quote** — that was always the plan (only the
ERP's own "not started yet" gate was blocking it from ever mattering). The
`wanaan-fare-finder` WordPress plugin (a separate codebase, not in this repo)
turned out to already implement exactly this: `WNF_Pricing::offer_applies()`
and its JS mirror both compare the TRIP date against `starts`/`ends`, and the
plugin's own doc comment describes this identical bug independently — so
both halves now agree; nothing further was needed on the WordPress side for
this one. Regression:
`PricingApiTest::{test_an_offer_that_has_not_started_yet_is_still_published_so_it_can_be_booked_ahead,
test_starts_and_ends_are_always_published_even_when_the_offer_is_off_or_expired}`.

**Gotcha this fix immediately ran into: a code-only fix doesn't propagate on
its own.** `PricingWriter` only bumps `pricing_version` on a genuine DATA
write, and the website treats that version as an ETag — a conditional GET
with a matching ETag gets 304, and `WNF_Rest::refresh()` skips a ping
outright when the version it's told about is one it already holds. A fix to
HOW the payload is *computed* (this one) changes what the SAME stored data
produces without writing anything, so nothing bumps the version and the
website's cached copy never learns anything changed — the existing "Send
update to website" admin button is *also* powerless here, because it pings
with the CURRENT (unchanged) version number. New command **`php artisan
pricing:republish --workspace=<id>`**
(`App\Console\Commands\RepublishPricingCommand`, workflow
`.github/workflows/republish-pricing.yml`) exists for exactly this case: it
calls `PricingVersion::bump()` with no data change, then pings with the new
number so the website's next fetch is a genuine 200. **Run this after
deploying any change to how `PricingPayload`/`PricingOffer::isLive()`
computes its output** — a data-only change (a rate, an offer) doesn't need
it, `PricingWriter` already bumps on save. Test:
`RepublishPricingCommandTest`.

**v3 increment (2026-09-09, same day): buses, per-service vehicles, settings.**
Migration `2026_09_09_100002_extend_pricing_for_buses_and_settings` adds
`pricing_service_vehicles` (**which vehicles each service offers — without it the
airport widget lists a 50-seat coach**), `pricing_settings` (`whatsapp`,
`lead_hours` — everything the widget shows that isn't a fare), a
`pricing_services.estimated` flag, and makes `pricing_cars.bags` **nullable**
(luggage on a coach depends on the group; an invented number is worse than none).
Four buses (hiace / coaster / coach / sprinter) and two services (`bus`,
`ksa_bus`) join the seed — **bus hour blocks are 6/8/12, cars are 4/8/12; do not
normalise them.** Two new payload rules: each service carries its own ordered
`cars` list, and **a service with no positive fare anywhere is never published**
(the widget refuses to render one, so the guard is mirrored here). `estimated` is
internal — it drives an admin warning, clears the first time a human saves that
grid, and is **never sent to the website**.

**Placeholders awaiting the owner's confirmation** (seeded, flagged in the PR): `pax`/`bags` per **car** (sensible per model, not measured from the fleet — the **bus** seat counts are real), chauffeur extra-hour rates (sedan 12 / suv 17 / lsuv 19 / luxury 45, derived from the 4-hour rates), KSA `return_factor` 1.80, and **all twenty `ksa_bus` fares** (estimates: each bus's own 12-hour rate scaled by the destination multipliers the car fares already imply — no bus-to-Saudi price exists on the website). The Luxury chauffeur jump from 180 (4h) to 400 (8h) is **deliberate and confirmed — do not "correct" it.**

**Gotcha for tests:** Laravel's `getJson()` sends `[]` as the body even on a GET, so a signature computed over an empty body will not match. Use `->get()` and read the JSON off the response.

**Corporate rates (shipped 2026-09-29):** prices agreed with companies that
have a deal, kept APART from the website fares and **never published** —
`PricingPayload` reads only `pricing_rates`, and saving corporate rates bumps no
version and pings nothing (pinned by `CorporateRatesTest::test_corporate_rates_never_reach_the_website`).
Core table `pricing_corporate_rates` (migration `2026_09_29_100001`): same grain
as `pricing_rates` (option × car) plus a nullable `customer_id` — **null = the
standard corporate rate every company gets**, a company's own row overrides it.
Model `PricingCorporateRate::lookup()`. `FareCalculator::quote(..., companyId:)`
resolves **company's own → standard corporate → website fare**; a corporate
price takes **no website offer** on top, and an option hidden from the website
can still carry a corporate rate (it is looked up regardless of `active` when a
company is given, but the public/no-deal path still refuses it).
`FareResult::$source` = `website | corporate | corporate_standard`.
Admin page `/corporate-rates` (`App\Livewire\Pages\CorporateRates`, dashboard
tile "Corporate rates"): pick "All companies — standard" or one company
(searchable), same options × cars grid per service; blank = no row, the grey
placeholder shows what applies instead. **Limousine customers live in the shared
`rental_customers` table** — use `(new LimoCustomer())->getTable()`, never
`limo_customers`. The WhatsApp assistant uses it: `company` on
`get_fare`/`propose_booking`/`propose_quotation` (resolved by
`AssistantActions::resolveCompany()` — exact name, else one unique partial
match; none or several → it must ask, never book as a private customer), the
company becomes the booking's **customer and requested-by**, the person named
becomes the **passenger** (pax name/contact), and the resolved company name is
stored in the proposal so the YES step prices the same company. New read tool
`get_corporate_rates`. Tests: `CorporateRatesTest` (7) + `WhatsAppStaffAssistantTest` (+4).

**Offer car scoping (shipped 2026-09-10):** an offer used to discount every car in a
service at once; not every car should get the same discount (e.g. 25% off Sedan and
SUV on Airport Transfer but not Luxury). `pricing_offer_cars` pivot (migration
`2026_09_10_100001`, mirrors `pricing_service_vehicles`) + `PricingOffer::cars()`
BelongsToMany. **Empty pivot means "not configured" and resolves to every car the
service offers** (`PricingPayload::offerCarIds()`) — the same fallback rule a service
with no vehicle list already uses, not "applies to nothing." Published as
`offer.cars` (a car id list), **zeroed to `[]` whenever the offer is not live** —
same defensive reasoning as `percent`/`label_en` being zeroed, so a site checking
only this field can't apply an expired/not-yet-started offer to anything.
`/fares` gained an "Applies to" checkbox row (`PricingManager::$offerCarIds`,
defaults to every car ticked when nothing's configured yet) between "Offer is on"
and the percent/label grid; **saving with the offer ON and zero cars ticked is
refused** ("Select at least one car for the offer, or switch it off") — the same
never-publish-a-silent-no-op philosophy as a blank rate on a shown option.
`PricingWriter::updateOffer()` gained a third `list<string> $carIds` param, synced
only when the car set actually changed (no wasted write when just the label moved).
Tests: `PricingApiTest` (+6 — scoped payload, zeroed when not live, empty-pivot
fallback, form persists the selection, form refuses on+empty, new offer defaults
every car ticked).

**Driver pay type + a free BD commission amount (shipped 2026-09-10, revised same
day):** a driver is either a **company driver** or a **commission driver**. New
`rental_drivers.pay_type` (string, default `company`) + a nullable decimal column
for the commission figure, migration `2026_09_10_960021_add_pay_type_to_drivers.php`
(Rental owns the shared table, same as the earlier licence-papers migration).

The first cut locked the commission to one of the office's own five percentages
(25/15/10/7/5%) via a `select`. **The owner asked for the opposite same-day: not
locked, not a percentage — a free amount in Bahraini Dinar.** Migration
`2026_09_10_970022_convert_driver_commission_rate_to_amount.php` renamed the column
`commission_rate` → **`commission_amount`** and widened it to `decimal(8,2)` (a
percentage never exceeds 100; a BD figure needs more headroom), and the form field
became a plain `number` widget (`nullable|numeric` via `FormView::rules()`) instead
of a `select` — so any BD amount the admin types is accepted, with no scale to pick
from. **`commission_amount` stays a plain column, not an enum cast** — irrelevant to
enforcement now that it isn't a `select`, but kept for the same reason `PosCategory.station`
avoids one: the engine form's blank "—" round-trips as `""` on any field that reuses
this pattern later.

New shared trait **`Modules\Rental\Models\Concerns\HasDriverPay`** (alongside the
existing `HasDriverLicence`/`DriverDeletionReferences`, `use`d by both `Driver` and
`LimoDriver` — one driver, two doors, see those traits' own docs): `PAY_TYPE_OPTIONS`
feeds the `pay_type` `select` on BOTH models' `irModelDefinition()` forms (still
whitelisted via `FormView::rules()`'s `in:` derivation — a pay type that isn't
`company`/`commission` can never be saved). **`bootHasDriverPay()`** (the Laravel
`boot<TraitName>()` auto-hook convention, same as
`GuardsDeletionWhenReferenced::bootGuardsDeletionWhenReferenced()`) clears
`commission_amount` to null on `saving` whenever `pay_type` isn't `commission` — an
amount left over from before a driver was switched back to Company must not linger
unseen.

**Static `select` option labels now translate.** Building the first cut surfaced
that `form-view.blade.php`'s `select` case rendered `{{ $opt['label'] }}` raw —
never `__()`-wrapped — so EVERY static-option select in the app (e.g.
`PosProduct.unit`) has been silently untranslatable since Phase 4/13; the "Unit"
field's own doc note ("the option labels stay English") was describing this gap,
not a deliberate carve-out. Fixed to `{{ __($opt['label']) }}` — safe and additive,
since `__()` returns its argument unchanged when no `ar.json` key matches, so every
existing select renders exactly as before until a translation is added for it. This
fix outlived the percentage scale it was built for — still in effect for `pay_type`'s
own options. New keys: Pay type / Company driver / Commission driver / "Commission
(BD)" / the BD help text.

**Limousine's own driver list — Active is now a checkbox (shipped 2026-09-10):**
`LimoDriver`'s list arch flipped the `active` column from `format: bool` (rendered
"Yes"/"No") to `format: toggle` (Phase 4's inline iOS-switch, `ListView::toggleBoolean`
— already Write-ACL-gated and arch-whitelisted, no new plumbing needed). **Scoped to
Limousine only, per the request** — Rent A Car's own driver list (`Modules\Rental\Models\Driver`,
same shared table, separate `ir_model`/arch) is untouched and still shows Yes/No; say
so if asked to widen it, it is a one-line arch change mirroring this one.

Tests: `DriverRecordTest` (+6 — new driver defaults to Company, a commission
driver carries a free BD amount and both apps agree, switching back to Company
clears the amount, the engine form saves any BD figure typed in, the engine form
still refuses a non-numeric value, the Limousine list's Active column is an inline
toggle).

**Scoped Administrator — narrow an admin to specific apps (shipped 2026-09-09):**

Settings → Users' role picker described Administrator as "Full access to
every app and setting in this database" with no way to narrow it — an owner
who wanted a manager to run just the POS side had to either give them every
app or fall back to Supervisor (no delete rights). The app checklist below
the role picker, previously shown only for Staff/Supervisor/Accountant (an
Administrator "bypasses the ACL, so a selection is meaningless"), now also
appears for Administrator — but for a **different purpose**: instead of
GRANTING access to the ticked apps, it **narrows** the admin to them. This is
a real restriction (a hard 403 outside the ticked apps), not menu-hiding —
`is_admin` stays `true` throughout, so every `isAdmin()`-gated screen that
ISN'T app-specific (central Settings, Activity Log, Backups, Workspaces,
Daily Report, WhatsApp/WooCommerce/Stream settings, Payroll/Monthly Profit)
is **untouched and stays fully open** to a scoped admin, exactly as for an
unscoped one. **Never a super admin** — the owner tier is always a full,
unscoped superset, regardless of what the new column holds.

| Concern | Location |
|---|---|
| Schema | `users.admin_apps` (nullable JSON, core migration `2026_09_09_100001`, so it auto-applies to Main + every tenant via `workspaces:migrate`). Null/empty = unrestricted (today's default, unchanged) |
| Model | `User::adminAppScope(): ?list<string>` (column-guarded like `isSuperAdmin()`; always null for a super admin, regardless of the column) and `User::mayAdministerApp(string $module): bool` (true when unscoped, or when `$module` is in scope) |
| Enforcement — the ONE choke point | `App\Erp\Security\AccessControl::allows()` — the admin bypass (`if ($user->isAdmin()) return true;`) became `if ($user->isAdmin()) return $user->mayAdministerApp($this->moduleOf($modelKey));`, where `moduleOf()` reads the module prefix off the model key (every key in the registry is `<module>.<name>`, e.g. `pos.order` — the same convention `UserProvisioner::grantApps()` already relies on). Because the engine List/Form/Kanban views (`HasAccessControl`) AND every bespoke module screen (`GuardsModelAccess`) both funnel through this SAME method (the point of the 2026-08-24 hardening), scoping a regular admin here transparently restricts **everything** — data screens, `ModuleMenu`'s ACL-filtered app-dropdown/tile contents, the lot — with no other code path to keep in sync. **This is why the feature was safe to build in one place**: don't duplicate the scope check elsewhere: route it through `AccessControl` |
| Also scoped explicitly | `App\Livewire\Pages\AppFeatureSettings` (an app's own Settings/feature-toggle tab) re-checks `mayAdministerApp($module)` in both `mount()` and `save()` — this screen is `isAdmin()`-gated, not model-key-gated, so it needed its own check to keep "tick an app" meaning "that app AND its settings tab," not just its data screens. `AppSwitcher`'s per-app "Settings" deep-link is hidden the same way |
| Deliberately NOT scoped | Every `isAdmin()`-gated screen that isn't tied to one app — central Settings (General/Daily Report/WhatsApp/etc. tabs), Activity Log, Backups, Workspaces, `EmployeePayroll`/`EmployeeForm`/`MonthlyProfit` — these are database-wide owner tools, not "apps" in the picker, so a scoped admin keeps full, unrestricted access to them (the owner's own words: "full access to the settings of the app and database I give them, just like superadmin") |
| Provisioning | `UserProvisioner::adminScopeFor(StaffRole, list<string> $appNames): ?list<string>` — the one place `admin_apps` is computed (null unless the role is a scopable Administrator; filtered through `Features::moduleAllowed()` so a tick from a business type that doesn't run that app can't leak in, same guard `grantApps()` already applies). Threaded through `upsertWithAccess()`/`upsertLockedRow()` alongside the `is_admin`/`is_super_admin`/`is_accountant` flags, so it is written per-database exactly like those — an admin scoped to "pos" on one database and provisioned into a second database that doesn't run POS simply has no matching app there (harmless, same as any other unmatched grant) |
| `StaffRole` additions | `isScopableAdmin(): bool` (`=== Admin`, never SuperAdmin) and `usesAppPicker(): bool` (`grantsApps() \|\| isScopableAdmin()` — the "show the checklist" question, one level above `grantsApps()`, which stayed `!isAdmin()` and still means "write `ir_model_access` grant rows") |
| UI | `UserManager`'s app checklist now renders whenever `$currentRole->usesAppPicker()` (was `grantsApps()`), with a hint line shown only for a scopable Administrator ("Leave every box unticked for unrestricted access… tick specific apps to limit this administrator to just those"); the "no picker" fallback message is now Super-admin-only wording. `editUser()` reads an Administrator's current apps from `$user->adminAppScope()` (a scope) rather than `currentApps()` (which reads ACL grant rows — always empty for an admin, since none are ever written for one) |
| Tests | `UserManagerTest::{test_a_scoped_administrator_gets_full_access_to_only_the_ticked_apps, test_an_administrator_with_no_ticked_apps_still_gets_every_app, test_editing_a_scoped_administrators_apps_replaces_the_scope, test_a_super_admin_is_never_scoped}` · `AppFeatureSettingsTest::{test_a_scoped_administrator_can_open_their_own_apps_settings, test_a_scoped_administrator_is_blocked_from_another_apps_settings}` · `RentalAccessControlTest::test_an_administrator_scoped_to_rental_is_forbidden_from_limousine` (cross-module proof on a bespoke screen — not just the engine views) |

**Deliberately out of scope this increment** (the owner's own words: "later"),
offer as a follow-up if asked: scoping an Administrator to specific
**databases** they may open/switch into (today database access is still
provision-only + admin-only switching, unrelated to `admin_apps`, which only
narrows which **apps** they see once they're in a given database) — and
hiding an out-of-scope app's icon from the topbar entirely rather than
leaving it visible-but-blocked (matches the pre-existing Staff/Supervisor
experience today; a uniform "hide what you can't open" pass across every
role would be a separate, broader change).

**Settings → Users: the app checklist reacts to which databases you pick
(shipped 2026-09-09):** creating a user from Main always offered **Main's own
apps only**, no matter which databases were ticked below — so a rental
workspace's Rent A Car / Limousine could never even be TICKED for a shared
account, because the checklist had nothing to do with the database picker
sitting right below it (found live: Wanaan Car Rental W.L.L's apps were
invisible while creating a user from Main, a café).

| Concern | Location |
|---|---|
| Per-database app list | `UserManager::appsFor(?int $workspaceId): Collection<int, IrModule>` — the installed, business-type-allowed application modules for ONE database, run on THAT database's own connection via `WorkspaceManager::runFor()` (a no-op for Main/null) so `Features::moduleAllowed()` reads that database's own `company.business_type`, not the caller's |
| Reactive union | `UserManager::appModulesForForm(?int $currentWorkspaceId): Collection` — editing/creating inside ONE specific database (a workspace, or a shared account's per-database access) still uses `appsFor()` alone (exactly one database in play, unchanged). Creating a brand-new account from Main instead returns the **UNION** of every app run by the databases currently ticked in `$this->workspaces`, deduped by module name and sorted by `sequence`. Nothing ticked yet falls back to Main's own list (today's default view, unchanged) |
| Live reactivity | The "Databases this user can access" checkboxes flipped from `wire:model` to `wire:model.live="workspaces"` — ticking one now round-trips and re-renders `appModules` immediately, so Rent A Car appears in the checklist the instant Wanaan is ticked (and disappears again if it's unticked, unless another ticked database also runs it). A hint line under "Apps this user can access" explains the behaviour |
| Why this was already safe | `UserProvisioner::grantApps()` already filters every ticked app through `Features::moduleAllowed()` **per target database** (see the "grants are scoped per database" rule above) — so a stale app value left in `$this->apps` after unticking a database was never a security gap, only a UI blind spot. This change fixes the blind spot; it changes no enforcement |
| Tests | `UserManagerTest::test_ticking_a_database_surfaces_the_apps_it_runs` (nothing ticked → Main's apps only; tick a rental workspace → its apps join the list, Main's stay too; untick Main, keep only the rental workspace → only its apps, POS drops out) |

**Fixed 2026-09-10 — a staff account granted only tenant databases could never sign in.**
Reported as "there is an issue in the login whenever someone new other than the
superadmin tries to sign in" — every freshly-created staff account hit "These
credentials do not match our records," no matter the password.

Root cause: `Login::login()` always runs `Auth::attempt()` against **Main** — a
brand-new browser has no `erp_workspace` cookie yet, so there is nothing to route
it anywhere else (see `ChooseWorkspaceController`'s own docblock: "Signing in
authenticates against Main"). But `UserProvisioner::provision()` — the plain,
non-locked "Databases this user can access" checklist path — only ever wrote a
row to Main **when Main itself was one of the ticked databases**; otherwise it
returned `null` and the comment even said so ("an account can live only in
tenant DBs"). Since **Main is a normal pickable database, not auto-included**
(by design, documented above), the natural, common case — an owner creating
staff scoped to their one business, never ticking the unrelated café/default
Main database — produced an account with no row on Main whatsoever. It could
never authenticate, full stop; only accounts that happened to include Main (like
the superadmin) worked.

Fix: `provision()` now calls the new `ensureMainLoginShell()` whenever the loop
didn't already write a Main row — a **bare, unprivileged** row (no `is_admin`,
no `is_super_admin`, no ACL grants) with the SAME hashed password as everywhere
else. This can't leak Main access: `WorkspaceManager::accessibleFor()` already
excludes Main from a non-admin's workspace picker regardless of whether a row
exists there, and the tenant workspace(s) they were actually granted still show
up exactly as before (matched by email, same as always). A regular tenant-only
account now shows up on Main's Users list looking exactly like an existing
**"global" account** ("Managed on Main") — which is accurate: its identity
(name/email/password) genuinely is managed there now, same concept the rest of
the multi-database system already uses.

Also benefits `EnsureStaffUserCommand`'s unlocked path (`user:ensure` with
`--databases` excluding Main) — same bug, same fix, no separate change needed.
The **workspace-locked** path (`provisionLocked()`, "Add users from inside any
database") was never affected — it already wrote a Main shell unconditionally.

Test: `UserManagerTest::test_user_is_provisioned_into_the_selected_databases_but_still_gets_a_bare_login_row_on_main`
(replaces the old test that asserted zero rows on Main — that assertion was
pinning the bug). Diagnosed live via `php artisan mail:test` (ruled out a mail
delivery problem first — SMTP was handing off cleanly) before tracing the actual
symptom ("these credentials do not match") back through `Login`/`ChooseWorkspaceController`/`UserProvisioner`.

**The fix above was not retroactive — 3 accounts stayed broken for 2 days
(data-repaired 2026-09-12).** Reported again as "no one can sign in except the
super admin." The code fix (2026-09-10 17:54) only changes what happens the
NEXT time `provision()` runs — it does nothing for a row that was already
written broken. Three accounts created via the plain "Databases this user can
access" checklist (Main unticked, Wanaan Car Rental ticked) **before** that
timestamp — Hasan Makhlooq, Abbas Hamdan, Hashim, all `hasan.fuad@hotmail.com`
/ `hamdanabbas98@gmail.com` / `reservations@wanaan-bh.com` — had a real,
correct row inside the Wanaan tenant database and **zero** row on Main, so
`Login::login()` (which always authenticates against Main) rejected them with
"credentials do not match" regardless of password. Confirmed live: sessions
for other, unaffected non-admin accounts (a POS cashier, a Kaleem Perfume
staff member, plus every `provisionLocked()`-created account) were active and
working at the time — this was never a blanket outage, just these 3 specific
rows. A fresh reproduction of `withTenant(withMain(...))`'s connection swap
confirmed the CURRENT code correctly writes to Main even when called from
inside an active tenant context — ruling out a second, still-live bug. Fixed
by a one-time **data repair** (no code change): a Main login shell was created
for each of the 3 (bare, unprivileged, matching what `ensureMainLoginShell()`
would have produced), a freshly generated password was hashed and stored, and
each person was emailed their new credentials via the same `WelcomeCredentials`
notification the create flow already sends. **If another account from before
2026-09-10 17:54 turns up with the same symptom, the repair is the same:**
confirm it has a tenant row but no Main row, then create the Main row + email
a new password — there is no remaining code defect to chase.

**Bespoke list pagination shows page numbers on phone (shipped 2026-09-15).**
13 bespoke Rental/Limousine list views (Limousine: bookings, coupons,
invoices, petty-cash, quotations, receipts; Rental: driver-jobs, invoices,
maintenance, orders, quotations, receipts, replacements) called `$x->links()`
— Laravel's default Tailwind pagination view, which collapses to Previous/Next
only under `sm`, hiding the active page on a phone. Switched every one to
`$x->links('vendor.pagination.compact')`, the sliding-window view already used
by POS Orders/Stock Report/Activity Log/the engine ListView, so numbered pages
show on a phone too.

**A limousine leg's exchange rate is looked up live, not typed (shipped
2026-09-15) — reverses an earlier session's "manual entry" decision.** The
office asked: pick the currency, and let the rate/BHD-equivalent fill itself
in — "10 SAR = # BHD" — rather than typing both a rate and an exchange rate by
hand. New `App\Erp\Money\ExchangeRateService` — free, keyless
`open.er-api.com/v6/latest/{FROM}` lookup, 6-hour cache **on success only**
(a failed lookup is never cached, so Retry can succeed on the next try),
constructor-injected `Illuminate\Http\Client\Factory`, **never throws**
(returns `?float`). `Modules\Limousine\Livewire\Concerns\HandlesTripLegs`:
`switchLegCurrency()` calls the new `fetchExchangeRateFor(int $i, string
$currency)`; `applyLegRate(int $i)` is the pure BHD-rate computation;
`recomputeLegRate()` retries the fetch when the rate is blank; public
`retryLegExchangeRate(int $i)` backs a "Retry" link in the UI; a `messages()`
override supplies custom exchange-rate validation text. `legs.blade.php`
dropped the manual "Exchange rate — 1 :currency in BHD" input entirely,
showing `:quote :currency ≈ :amount BHD` once resolved, or a red "Could not
fetch today's exchange rate…" + Retry when the lookup failed. **A historical
leg never re-fetches on edit** (opening an old leg must not silently reprice
it against today's rate), and the server-authoritative BHD `rate` derivation
is unchanged from the original design. Test gotcha worth remembering:
`Http::fake()` called twice for the same URL pattern does NOT let the second
call override the first (stub callbacks accumulate, first-registered-match
wins) — use `Http::fakeSequence($url)->push(...)->push(...)` to model a
failing-then-succeeding retry across several calls in one test.

**A booking/quotation leg suggests the customer's OWN past locations first
(shipped 2026-09-15).** `LimoCustomer::recentLocations(int $limit = 12):
array` — the customer's past `LimoBooking` legs' pickup/drop-off locations,
deduped, most-recent-first, capped at 12 ("their trips are usually recurring
to the same handful of places"). `HandlesTripLegs::legViewData()`'s
`locationNames` datalist merges this list AHEAD of the company-wide saved
`LimoLocation` list, so a customer's own frequent addresses surface first
while typing. Shared by both `BookingForm` and `QuotationForm`.

**Modal footers pin the dismiss action to the START, the primary action to
the end (shipped 2026-09-16).** The Limousine "New customer" modal's Cancel
button was relabelled **Back** and pinned to the left (footer flex
`justify-end` → `justify-between`, Back first in DOM order). The booking
preview modal's footer got the same treatment — Close moved before "Open full
booking" in the DOM with the same `justify-between` swap. **Rule: a
two-button modal footer keeps dismiss/back at the logical start and the
primary action at the end** — flex row order mirrors naturally under
`dir="rtl"`, so no physical left/right utility classes are needed.

**A deactivated customer/vehicle/driver/branch still shows on ITS OWN record
(fixed 2026-09-16).** Opening an existing booking/order/quotation/invoice/
maintenance record whose customer (or vehicle/driver/branch) had since been
deactivated rendered that field blank ("— Select —") even though the record
genuinely has it — every picker list queried `active = true` only, silently
dropping the record's own relation once archived. New shared trait
`App\Models\Concerns\ActiveOrSelected` (`activeOrSelected(?int $selectedId,
array $columns = ['*']): Collection`) scopes a NEW selection to active
records while always keeping whichever one is already assigned — mirrors the
pre-existing pattern in `HandlesTripLegs::carOptions()`. **Uses `self::query()`,
not `static::query()`, in the trait body** — every consuming model is `final`,
so they're always identical at runtime, and `self::` sidesteps a PHPStan/
Larastan invariant-Collection-template false positive that `static::` tripped
across all 6 consumers. Wired into `LimoCustomer`, `RentalCustomer`,
`Vehicle`, `Driver`, `Branch`, `LimoDriver`, and 8 Livewire form call sites
(Limousine `BookingForm`/`QuotationForm`/`InvoiceForm`; Rental `InvoiceForm`/
`OrderForm`/`QuotationForm`/`MaintenanceForm`). Deliberately left alone:
`OrderForm`'s `vehicles` list (its own super-admin/lapsed-papers override
logic — would need careful restructuring to also handle plain
`active=false`) and `PettyCash`'s driver filter (a filter dropdown, not an
edit-existing-record picker). **Rule: any picker list backing an existing
record's OWN relation field must use `activeOrSelected()`, not a plain
`where('active', true)` query** — the plain form stays correct only for a
brand-new record's options.

**Bookings list — every trip fact now reaches the phone (shipped 2026-09-16).**
The responsive column-hiding table (`$vis` array in `bookings.blade.php`)
originally kept only Reference/Customer/Status/Actions visible on a phone,
adding From date/Payment at `md`, To date/Type/Pickup/Drop off at `lg`,
Received/Balance/Vehicle/Driver at `xl`. Per the office's requests, **From
date, To date, Type, Pickup, Drop off and Vehicle are now always visible**
(no breakpoint prefix) — a phone shows the same trip facts a laptop does,
short of Received/Balance/Driver (still `xl`) and Added by/Comments/Booked
time (still `2xl`). **2026-09-17:** Payment and Status swapped places — the
**Payment** column (badge + take-payment button) is now always visible and
**Status** (badge + advance/cancel icons) joins at `md`.

**Bookings row Actions collapsed into a 3-dot menu with viewport-aware flip
(shipped 2026-09-16).** Five separate icon buttons (Open full booking / Edit
/ Service order PDF / Create payment link / Signed-Sent-Resend) made the
Actions column wide, forcing a sideways scroll to reach it on a phone. Now
behind one 3-dot trigger per row. New reusable Alpine component
**`rowActionsMenu`** (`resources/js/app.js`) — `fixed`-positioned at viewport
coords captured on open (same reasoning as the app-bar dropdown: the table's
`overflow-x-auto` wrapper also clips vertical overflow per the CSS overflow
spec, so an `absolute` panel would get cut off), and additionally **measures
the panel's real height on `$nextTick` and flips it to open ABOVE the
trigger** when there is no room below — without this, a row near the bottom
of the screen (phone OR laptop) cropped its lower actions off-screen,
unreachable by touch or mouse. `max-h-[70vh] overflow-y-auto` on the panel is
the last-resort scroll fallback for a panel taller than the screen. **Rule:
any new per-row popover menu inside a scrollable table should reuse
`rowActionsMenu`** (or its measure-then-flip technique) rather than the
app-bar dropdown's simpler always-below positioning, which assumes the
trigger sits near the top of the viewport.

**Bookings reference click copies the trip again, not a preview (reverted
2026-09-16).** A brief redesign made the reference number open a
quick-preview modal (customer, every leg, money, at a glance) instead of the
original one-press copy-to-clipboard; the office asked for the old behaviour
back. The reference button now runs the same `$store.limoTrip.copy(...)`
pattern the preview's own per-leg copy buttons use, reusing the
already-computed `$whatsapp[$leg->id]` array from `Bookings::render()`. **The
preview modal itself was NOT removed** — it is still reachable from the
"View" link on the just-saved flash banner after adding/editing a booking
(`openPreview()` is still called from there); only the reference cell's own
click target reverted.

**Copied trip text hides the Balance/Paid line for company customers
(shipped 2026-09-16).** `LimoQueueRows::whatsappText()` always appended
either "Balance :amount BD — collect from customer" or "✅ Paid" — but a
company's trips are settled on its account, not by the driver collecting cash
from whoever is riding, so both lines were misleading on a corporate
booking's WhatsApp copy. Now skipped entirely when `$customer->isCompany()`
(shown for an individual, or when the customer is unknown/null — unchanged).
The preview modal's per-leg copy shares the same `whatsappText()`, so it
follows automatically.

**Show-per-page chips changed to 25/50/100/300 (shipped 2026-09-16).** The
only two screens in the app with a "Show N per page" chip control —
Limousine `Bookings` (the trip queue) and Rental `DriverJobs` (a driver's job
history) — both had `PER_PAGE_OPTIONS = [10, 25, 50, 100, 500]` /
`PER_PAGE_DEFAULT = 10`. Per the office's request, both are now
`PER_PAGE_OPTIONS = [25, 50, 100, 300]` / `PER_PAGE_DEFAULT = 25`. Distinct
from the engine `ListView::PER_PAGE_OPTIONS = [20, 50, 100]` (a dropdown, not
chips) — that one is untouched.

**Saving a NEW customer returns to the list (fixed 2026-09-17).**
`Modules\Rental\Livewire\CustomerForm` — the ONE shared customer page reached
from both `/app/rental/customer/new` and `/app/limousine/customer/new` (see
the "shared customer page" section above) — used to leave the office on the
same page after creating a customer, silently retitled "Edit customer": it
just set `$this->id` and flashed a toast. Now `save()` redirects to
`$this->indexUrl` (`navigate: true`) whenever `$wasNew`, matching every other
bespoke "new record" form in the app (e.g. `BookingForm::save()` redirects to
`/app/limousine/booking`). Editing an EXISTING customer is unchanged — stays
in place, since the office often makes several small fixes in a row (upload a
CR document, fix a phone number) and re-opening the list after each save
would be its own annoyance.

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
  --max-time=50')->everyMinute()->runInBackground()`. A single
  `* * * * *` cron in hPanel runs `php artisan schedule:run` and drains the queue
  every minute. **If the cron is missing, queued jobs sit in the `jobs` table forever**
  (symptom: `whatsapp_messages_log.status='queued'` never advances to `sent`). Use
  `ps aux | grep queue:work` to verify nothing else is running first. Same scheduler
  also runs `prune-whatsapp-receipts` daily to drop PNGs older than 7 days.
  **The `queue:work` entry deliberately has NO `withoutOverlapping()`** (removed
  2026-06-09, commit `eca9b68`). With `runInBackground()` the host can kill the detached
  worker after `schedule:run` returns but before `schedule:finish` releases the overlap
  mutex — orphaning the lock, after which EVERY `schedule:run` (cron *and* manual) silently
  skips `queue:work` and the whole queue freezes (it stayed frozen 2026-05-26 → 06-09, past
  the 24h TTL). Diagnose with `schedule:list` (persistent `Has Mutex` on `queue:work` =
  stuck); clear immediately with `php artisan schedule:clear-cache`. Do NOT re-add
  `withoutOverlapping()` to that line — `--max-time=50` + the `database` driver's row
  reservation make brief overlap harmless. The daily `Schedule::call(closure)` tasks keep
  `withoutOverlapping()` safely (they run in-process, releasing the lock before
  `schedule:run` exits). Memory: `[[schedule-withoutoverlapping-orphaned-mutex]]`.
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

