<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modular Addon System (Odoo "addons" paradigm)
    |--------------------------------------------------------------------------
    |
    | Every application (CRM, Inventory, Contacts, …) lives as a self-contained
    | module under the directory below and is registered with the PSR-4
    | namespace prefix. The ModuleManager discovers, installs and uninstalls
    | these modules dynamically, mirroring Odoo's `ir.module.module`.
    |
    */

    'modules_path' => env('ERP_MODULES_PATH', base_path('Modules')),

    'modules_namespace' => 'Modules',

    /*
    |--------------------------------------------------------------------------
    | Manifest File
    |--------------------------------------------------------------------------
    |
    | The per-module manifest file name. Equivalent to Odoo's __manifest__.py.
    |
    */

    'manifest_file' => 'module.json',

    /*
    |--------------------------------------------------------------------------
    | Core Pseudo-Module
    |--------------------------------------------------------------------------
    |
    | The implicit dependency every module may rely on. It is always considered
    | "installed" and is never resolved against the filesystem.
    |
    */

    'core_module' => 'base',

];
