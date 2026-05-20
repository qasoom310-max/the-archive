<?php

declare(strict_types=1);

namespace App\Models\Mail;

/**
 * Kind of a `mail_messages` row.
 *  - comment: a message posted to the thread ("Send message")
 *  - note:    an internal note ("Log note")
 *  - log:     a system-generated audit entry (field changes, lifecycle)
 */
enum MessageType: string
{
    case Comment = 'comment';
    case Note = 'note';
    case Log = 'log';

    public function label(): string
    {
        return match ($this) {
            self::Comment => 'Message',
            self::Note => 'Note',
            self::Log => 'Logged',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Comment => 'chat-bubble-left-right',
            self::Note => 'pencil-square',
            self::Log => 'clock',
        };
    }
}
