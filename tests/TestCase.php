<?php

namespace Tests;

use App\Models\Auth\ModelAccess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Grant every user full operate access (read/write/create, no delete) on
     * the given `ir_model` keys, via a global rule.
     *
     * Bespoke module screens are ACL-gated like the engine ones, and access is
     * deny-by-default — so a bare `User::factory()->create()` can't open them.
     * Tests whose subject is a ROLE rule (accountant, manager) rather than the
     * ACL itself use this to put the user in the same position a staff account
     * with that app granted would be in.
     */
    protected function grantEveryone(string ...$models): void
    {
        foreach ($models as $model) {
            ModelAccess::query()->firstOrCreate(
                ['model' => $model, 'group_id' => null],
                [
                    'name' => 'test:' . $model,
                    'perm_read' => true,
                    'perm_write' => true,
                    'perm_create' => true,
                    'perm_unlink' => false,
                ],
            );
        }
    }
}
