<?php

declare(strict_types=1);

namespace App\Erp\Security;

/**
 * CRUD permission, valued by its `ir_model_access` column.
 */
enum Permission: string
{
    case Read = 'perm_read';
    case Write = 'perm_write';
    case Create = 'perm_create';
    case Unlink = 'perm_unlink';
}
