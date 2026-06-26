<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single month's imported booking revenue for a car (by plate), used to seed
 * the Sales matrix + seasonality with history from a previous system.
 *
 * @property int $id
 * @property string|null $plate_no
 * @property string|null $vehicle_label
 * @property int $year
 * @property int $month
 * @property float $amount
 */
final class RentalRevenueHistory extends Model
{
    protected $table = 'rental_revenue_history';

    /** @var list<string> */
    protected $fillable = ['plate_no', 'vehicle_label', 'year', 'month', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'float',
        ];
    }
}
