<?php

declare(strict_types=1);

namespace App\Erp\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when a record is asked to delete itself while other records still
 * point at it — a customer with bookings, a car with rental history. Deleting
 * would either orphan those rows (tables without a foreign key) or quietly
 * blank their reference (tables with `nullOnDelete`); either way the history
 * stops adding up. The message names what is holding the record, so the
 * person can see why and what to look at.
 */
final class RecordInUseException extends RuntimeException
{
    /**
     * @param array<string, int> $uses label => how many rows still point here
     */
    public static function for(Model $model, array $uses): self
    {
        $parts = [];
        foreach ($uses as $label => $count) {
            $parts[] = $label . ' (' . $count . ')';
        }

        $name = $model->getAttribute('name');
        if (! is_string($name) || trim($name) === '') {
            $name = (string) $model->getKey();
        }

        return new self(__(':name cannot be deleted while records still point to it: :uses.', [
            'name' => $name,
            'uses' => implode(', ', $parts),
        ]));
    }
}
