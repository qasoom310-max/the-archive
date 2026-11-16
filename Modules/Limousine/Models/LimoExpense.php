<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A limousine running expense (fuel, driver pay, …). Engine-driven CRUD.
 *
 * @property int $id
 * @property string|null $reference
 * @property Carbon|null $date
 * @property string $category
 * @property float $amount      the total paid: before VAT + VAT
 * @property float $net_amount  the amount before VAT, as typed
 * @property int $vat_rate      0, 10 or 15 (percent)
 * @property float $vat_amount  worked out from the two above
 * @property string|null $payee
 * @property int|null $booking_id
 * @property string|null $notes
 * @property string|null $bill_path  the photo this expense was scanned from (private disk)
 */
final class LimoExpense extends Model implements DefinesIrModel
{
    use \App\Models\Concerns\HasReference;

    protected $table = 'limo_expenses';

    /** @var list<string> */
    protected $fillable = ['reference', 'date', 'category', 'amount', 'net_amount', 'vat_rate', 'vat_amount', 'payee', 'booking_id', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['category' => 'fuel', 'amount' => 0, 'net_amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0];

    /** The VAT rates a bill can carry: none, Bahrain's 10%, Saudi Arabia's 15%. */
    public const VAT_RATES = [0, 10, 15];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'float',
            'net_amount' => 'float',
            'vat_rate' => 'integer',
            'vat_amount' => 'float',
            'booking_id' => 'integer',
        ];
    }

    public function referencePrefix(): string
    {
        return 'EXP';
    }

    /**
     * The total is never typed: it is the amount before VAT plus the VAT the
     * chosen rate gives. Worked out here, on every save, so the form, an
     * import and petty cash can never disagree about it.
     *
     * Code that predates VAT (petty cash, the importer) writes `amount` alone.
     * That figure is then taken as the amount before VAT with no VAT, which
     * leaves the total exactly what was written.
     */
    protected static function booted(): void
    {
        static::saving(static function (LimoExpense $expense): void {
            $rate = (int) $expense->vat_rate;
            if (! in_array($rate, self::VAT_RATES, true)) {
                $rate = 0;
            }
            $expense->vat_rate = $rate;

            $wroteTotalOnly = $expense->isDirty('amount') && ! $expense->isDirty('net_amount') && ! $expense->isDirty('vat_rate');
            if ($wroteTotalOnly && $rate === 0) {
                $expense->net_amount = (float) $expense->amount;
            }

            $net = round(max(0.0, (float) $expense->net_amount), 3);
            $expense->net_amount = $net;
            $expense->vat_amount = round($net * $rate / 100, 3);
            $expense->amount = round($net + $expense->vat_amount, 3);
        });
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function vatOptions(): array
    {
        return [
            ['value' => '0', 'label' => 'No VAT'],
            ['value' => '10', 'label' => 'VAT 10%'],
            ['value' => '15', 'label' => 'VAT 15%'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function categoryOptions(): array
    {
        return [
            ['value' => 'fuel', 'label' => 'Fuel'],
            // The road costs petty cash settles into — one slot per default
            // petty category, so a driver's receipt lands here under the same
            // name it was entered as.
            ['value' => 'wash', 'label' => 'Car wash & cleaning'],
            ['value' => 'parking', 'label' => 'Parking'],
            ['value' => 'tolls', 'label' => 'Tolls'],
            ['value' => 'insurance', 'label' => 'Insurance'],
            ['value' => 'spare_parts', 'label' => 'Spare parts'],
            ['value' => 'food', 'label' => 'Food'],
            ['value' => 'office', 'label' => 'Office expenses'],
            ['value' => 'driver_pay', 'label' => 'Driver pay'],
            ['value' => 'maintenance', 'label' => 'Maintenance'],
            ['value' => 'salaries', 'label' => 'Salaries'],
            ['value' => 'rent', 'label' => 'Rent'],
            ['value' => 'fees', 'label' => 'Fees'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'limousine.expense',
            name: 'Expense',
            class: self::class,
            table: 'limo_expenses',
            module: 'limousine',
            fields: [
                new FieldDefinition('reference', 'Reference', 'char', readonly: true, sequence: 10),
                new FieldDefinition('date', 'Date', 'date', sequence: 20),
                new FieldDefinition('category', 'Category', 'selection', selection: self::categoryOptions(), sequence: 30),
                new FieldDefinition('net_amount', 'Amount before VAT', 'float', sequence: 36),
                new FieldDefinition('vat_rate', 'VAT', 'selection', selection: self::vatOptions(), sequence: 37),
                new FieldDefinition('vat_amount', 'VAT amount', 'float', readonly: true, sequence: 38),
                new FieldDefinition('amount', 'Total', 'float', readonly: true, sequence: 40),
                new FieldDefinition('payee', 'Paid to', 'char', sequence: 50),
                new FieldDefinition('notes', 'Notes', 'text', sequence: 60),
            ],
            views: [
                new ViewDefinition('Expenses', 'list', [
                    'columns' => [
                        ['field' => 'reference', 'label' => 'Reference', 'sortable' => true],
                        ['field' => 'date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
                        ['field' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'payee', 'label' => 'Paid to'],
                        ['field' => 'net_amount', 'label' => 'Before VAT', 'format' => 'money', 'align' => 'right', 'sortable' => true, 'sum' => true],
                        ['field' => 'vat_amount', 'label' => 'VAT', 'format' => 'money', 'align' => 'right', 'sortable' => true, 'sum' => true],
                        ['field' => 'amount', 'label' => 'Total', 'format' => 'money', 'align' => 'right', 'sortable' => true, 'sum' => true],
                    ],
                    'default_sort' => [['field' => 'id', 'dir' => 'desc']],
                    'per_page' => 20,
                    'searchable' => ['reference', 'payee'],
                    'open' => '/app/limousine/expense/{id}',
                ]),
                new ViewDefinition('Expense', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'date', 'label' => 'Date', 'widget' => 'date'],
                        ['field' => 'category', 'label' => 'Category', 'widget' => 'select', 'options' => self::categoryOptions()],
                        ['field' => 'net_amount', 'label' => 'Amount before VAT (BHD)', 'widget' => 'number', 'help' => 'Type the amount on the bill without VAT. The VAT and the total are worked out for you.'],
                        ['field' => 'vat_rate', 'label' => 'VAT', 'widget' => 'select', 'options' => self::vatOptions(), 'required' => true],
                        ['field' => 'vat_amount', 'label' => 'VAT amount (BHD)', 'widget' => 'number', 'readonly' => true],
                        ['field' => 'amount', 'label' => 'Total with VAT (BHD)', 'widget' => 'number', 'readonly' => true],
                        ['field' => 'payee', 'label' => 'Paid to', 'widget' => 'text'],
                        ['field' => 'notes', 'label' => 'Notes', 'widget' => 'textarea'],
                    ],
                ]),
            ],
        );
    }
}
