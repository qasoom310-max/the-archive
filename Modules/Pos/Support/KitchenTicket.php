<?php

declare(strict_types=1);

namespace Modules\Pos\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Pos\Enums\PrepStatus;
use Modules\Pos\Models\PosOrderLine;

/**
 * One KDS ticket = one order's lines that route to a single station.
 * Built by {@see \Modules\Pos\Livewire\KitchenDisplay::loadTickets()} and
 * consumed by the matching Blade view. Keeping it a real class (not an
 * anonymous shape) is what lets the engine + PHPStan + the view template
 * agree on the contract.
 *
 * `status` is the LEAST-progressed line's status — a multi-item ticket
 * only graduates to Ready once every line is Ready.
 *
 * @phpstan-type LineCollection Collection<int, PosOrderLine>
 */
final readonly class KitchenTicket
{
    /**
     * @param  LineCollection  $lines
     */
    public function __construct(
        public int $orderId,
        public string $reference,
        public Carbon $sentAt,
        public PrepStatus $status,
        public Collection $lines,
    ) {}
}
