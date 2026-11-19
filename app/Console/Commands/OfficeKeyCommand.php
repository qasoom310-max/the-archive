<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Erp\Office\OfficeTokens;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\OfficeToken;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Keys for the AI office's read-only API (/api/office/v1).
 *
 *   php artisan office:key create "AI office" --workspace=main --workspace=wanaan
 *   php artisan office:key list
 *   php artisan office:key revoke 3
 *
 * The plain key is printed once, at create. Workspaces are given by id or slug.
 */
final class OfficeKeyCommand extends Command
{
    protected $signature = 'office:key {action : create, list or revoke} {target? : the name (create) or id (revoke)} {--workspace=* : a workspace id or slug the key may read; repeat for more}';

    protected $description = 'Create, list or revoke read-only keys for the AI office';

    public function handle(OfficeTokens $tokens, WorkspaceManager $workspaces): int
    {
        $workspaces->ensureMain();

        return match ($this->argument('action')) {
            'create' => $this->create($tokens),
            'list' => $this->listKeys(),
            'revoke' => $this->revoke($tokens),
            default => $this->failWith('Unknown action. Use create, list or revoke.'),
        };
    }

    private function create(OfficeTokens $tokens): int
    {
        $name = trim((string) $this->argument('target'));
        if ($name === '') {
            return $this->failWith('Give the key a name, e.g. office:key create "AI office" --workspace=main');
        }

        /** @var list<string> $asked */
        $asked = (array) $this->option('workspace');
        if ($asked === []) {
            return $this->failWith('Name at least one --workspace (id or slug) the key may read.');
        }

        $ids = [];
        foreach ($asked as $value) {
            $workspace = Workspace::query()
                ->where(ctype_digit($value) ? 'id' : 'slug', $value)
                ->first();
            if (! $workspace instanceof Workspace) {
                return $this->failWith('No workspace "' . $value . '".');
            }
            $ids[] = (int) $workspace->id;
        }

        [$token, $plain] = $tokens->issue($name, $ids);

        $this->info('Key #' . $token->id . ' "' . $name . '" may read workspace(s): ' . implode(', ', $ids));
        $this->line('');
        $this->line($plain);
        $this->line('');
        $this->warn('Shown once. Store it in the office app\'s .env, never in chat or git.');

        return self::SUCCESS;
    }

    private function listKeys(): int
    {
        $rows = OfficeToken::query()->orderBy('id')->get()->map(static fn (OfficeToken $t): array => [
            $t->id,
            $t->name,
            implode(', ', $t->workspace_ids),
            $t->last_used_at?->format('Y-m-d H:i') ?? 'never',
            $t->isRevoked() ? 'revoked ' . $t->revoked_at?->format('Y-m-d') : 'live',
        ])->all();

        $this->table(['#', 'Name', 'Workspaces', 'Last used', 'State'], $rows);

        return self::SUCCESS;
    }

    private function revoke(OfficeTokens $tokens): int
    {
        $id = (string) $this->argument('target');
        $token = ctype_digit($id) ? OfficeToken::query()->find((int) $id) : null;
        if (! $token instanceof OfficeToken) {
            return $this->failWith('No key #' . $id . '. See office:key list.');
        }

        $tokens->revoke($token);
        $this->info('Key #' . $token->id . ' revoked. It stops working at once.');

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
