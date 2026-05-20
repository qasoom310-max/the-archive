<?php

declare(strict_types=1);

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $messageable_type
 * @property int $messageable_id
 * @property MessageType $type
 * @property string|null $subject
 * @property string $body
 * @property int|null $author_id
 * @property string|null $author_name
 * @property array<string, mixed>|null $tracking
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class MailMessage extends Model
{
    protected $table = 'mail_messages';

    /** @var list<string> */
    protected $fillable = [
        'type',
        'subject',
        'body',
        'author_id',
        'author_name',
        'tracking',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'tracking' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function messageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function authorLabel(): string
    {
        return $this->author_name ?? 'System';
    }
}
