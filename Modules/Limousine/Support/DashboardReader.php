<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

/**
 * Reads a photo of a car's dashboard: the odometer and the fuel gauge.
 *
 * An interface so the trip page can be tested without calling out: the real
 * reader sends the photo to Claude, a test hands back a fixed answer.
 */
interface DashboardReader
{
    /**
     * @return array{readable: bool, km: int, fuel_eighths: int, fuel_readable: bool}
     *   km 0 when the odometer cannot be read; fuel in eighths of a tank
     */
    public function read(string $apiKey, string $model, string $image, string $mime): array;
}
