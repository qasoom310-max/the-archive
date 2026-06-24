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
 * @property float $amount
 * @property string|null $payee
 * @property int|null $booking_id
 * @property string|null $notes
 */
final class LimoExpense extends Model implements DefinesIrModel
{
    protected $table = 'limo_expenses';

    /** @var list<string> */
    protected $fillable = ['reference', 'date', 'category', 'amount', 'payee', 'booking_id', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['category' => 'fuel', 'amount' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'float',
            'booking_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (LimoExpense $expense): void {
            if ($expense->reference === null || $expense->reference === '') {
                $expense->reference = 'EXP/' . str_pad((string) $expense->id, 5, '0', STR_PAD_LEFT);
                $expense->saveQuietly();
            }
        });
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function categoryOptions(): array
    {
        return [
            ['value' => 'fuel', 'label' => 'Fuel'],
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
                new FieldDefinition('amount', 'Amount', 'float', sequence: 40),
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
                        ['field' => 'amount', 'label' => 'Amount', 'format' => 'money', 'align' => 'right', 'sortable' => true],
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
                        ['field' => 'amount', 'label' => 'Amount (BHD)', 'widget' => 'number'],
                        ['field' => 'payee', 'label' => 'Paid to', 'widget' => 'text'],
                        ['field' => 'notes', 'label' => 'Notes', 'widget' => 'textarea'],
                    ],
                ]),
            ],
        );
    }
}
