<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

/**
 * Lifecycle of a journal entry.
 *
 * Draft  — editable, not reflected in any balance. Sum(debits) may still
 *          differ from sum(credits); the user is mid-edit.
 * Posted — locked, included in every balance/report. The transition draft
 *          → posted enforces the double-entry invariant in
 *          {@see \Modules\Accounting\Services\JournalPoster::post()}.
 */
enum JournalEntryState: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Posted => __('Posted'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Posted => 'emerald',
        };
    }
}
