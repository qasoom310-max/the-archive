<?php

declare(strict_types=1);

namespace App\Models\Demo;

use App\Erp\Chatter\Chatterable;
use App\Erp\Chatter\HasChatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Sample Chatter-enabled record for the Phase 3 dashboard demo.
 *
 * @property int $id
 * @property string $subject
 * @property string $stage
 * @property float $amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class DemoTicket extends Model implements Chatterable
{
    use HasChatter;

    protected $table = 'demo_tickets';

    /** @var list<string> */
    protected $fillable = ['subject', 'stage', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
