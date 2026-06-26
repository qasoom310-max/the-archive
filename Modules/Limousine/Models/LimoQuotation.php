<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A limousine trip quotation. Accepting it converts to a queued
 * {@see LimoBooking} via convertToBooking().
 *
 * @property int $id
 * @property string|null $reference
 * @property int|null $customer_id
 * @property int|null $pickup_location_id
 * @property int|null $dropoff_location_id
 * @property int|null $booking_id
 * @property Carbon|null $pickup_at
 * @property Carbon|null $valid_until
 * @property string|null $car_type
 * @property float $fare
 * @property string $status
 * @property string|null $notes
 * @property-read LimoCustomer|null $customer
 */
final class LimoQuotation extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'limo_quotations';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CONVERTED = 'converted';

    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'pickup_location_id', 'dropoff_location_id',
        'booking_id', 'pickup_at', 'valid_until', 'car_type', 'fare', 'status', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['fare' => 0, 'status' => self::STATUS_DRAFT];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'pickup_location_id' => 'integer',
            'dropoff_location_id' => 'integer',
            'booking_id' => 'integer',
            'pickup_at' => 'datetime',
            'valid_until' => 'date',
            'fare' => 'float',
        ];
    }

    public function referencePrefix(): string
    {
        return 'QT';
    }

    /**
     * @return BelongsTo<LimoCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LimoCustomer::class, 'customer_id');
    }

    /** Spawn a queued booking from this quotation (idempotent). */
    public function convertToBooking(): LimoBooking
    {
        if ($this->booking_id !== null) {
            $existing = LimoBooking::query()->find($this->booking_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $booking = new LimoBooking();
        $booking->customer_id = $this->customer_id;
        $booking->pickup_location_id = $this->pickup_location_id;
        $booking->dropoff_location_id = $this->dropoff_location_id;
        $booking->pickup_at = $this->pickup_at;
        $booking->car_type = $this->car_type;
        $booking->fare = $this->fare;
        $booking->notes = $this->notes;
        $booking->save();

        $this->booking_id = $booking->id;
        $this->status = self::STATUS_CONVERTED;
        $this->save();

        return $booking;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_DRAFT, 'label' => 'Draft'],
            ['value' => self::STATUS_SENT, 'label' => 'Sent'],
            ['value' => self::STATUS_ACCEPTED, 'label' => 'Accepted'],
            ['value' => self::STATUS_DECLINED, 'label' => 'Declined'],
            ['value' => self::STATUS_CONVERTED, 'label' => 'Converted'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.quotation',
            name: 'Quotation',
            class: self::class,
            table: 'limo_quotations',
            module: 'limousine',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', sequence: 10),
                new FieldDefinition('customer_id', 'Customer', 'many2one', relation: 'limousine.customer', sequence: 20),
                new FieldDefinition('valid_until', 'Valid until', 'date', sequence: 30),
                new FieldDefinition('fare', 'Fare', 'float', sequence: 40),
                new FieldDefinition('status', 'Status', 'selection', selection: self::statusOptions(), sequence: 50),
            ],
            views: [
                new ViewDefinition('Quotations', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'valid_until', 'label' => 'Valid until', 'format' => 'date'],
                        ['field' => 'fare', 'label' => 'Fare', 'format' => 'money', 'align' => 'right', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference'],
                    'open' => '/app/limousine/quotation/{id}',
                ]),
            ],
        );
    }
}
