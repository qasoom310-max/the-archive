<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A per-phone open discount. An admin assigns a percentage to a customer's
 * phone number; when the cashier attaches a customer (or types that phone)
 * at the register, the matching percentage comes off the whole order total.
 *
 * Admin-only by deny-default: no `pos_user` ACL row is granted for
 * `pos.customer_discount`, so cashiers never see the list/form (the engine
 * Read/Write guards 403 them); the superuser bypasses ACL entirely.
 *
 * @property int $id
 * @property string $phone
 * @property float $discount_percent
 * @property string|null $label
 * @property bool $active
 * @property Carbon|null $expires_at
 */
final class PosCustomerDiscount extends Model implements DefinesIrModel
{
    /**
     * Length of the rolling discount window. A purchase pushes the expiry
     * to (order date + this); going this long with no purchase lapses it.
     */
    public const WINDOW_DAYS = 90;

    protected $table = 'pos_customer_discounts';

    /** @var list<string> */
    protected $fillable = ['phone', 'discount_percent', 'label', 'active', 'expires_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'discount_percent' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_percent' => 'float',
            'active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $discount): void {
            // Clamp the open number to a sane percentage range so a typo
            // (e.g. 1000) can't zero out or invert an order total.
            $discount->discount_percent = round(max(0.0, min(100.0, $discount->discount_percent)), 2);

            // Start (or restart) the 90-day clock whenever the discount is
            // active but has no live window — i.e. on first create and when
            // an admin re-enables a lapsed one. An already-future expiry is
            // left untouched, so editing the percent/label doesn't reset it.
            if ($discount->active && ($discount->expires_at === null || $discount->expires_at->isPast())) {
                $discount->expires_at = Carbon::now()->addDays(self::WINDOW_DAYS);
            }
        });
    }

    /**
     * True while the rolling window is still open (or unbounded). The
     * register only applies a discount that is both `active` and unexpired.
     */
    public function isWithinWindow(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Push the window to (base date + 90 days). Called from the
     * PosOrderPaid listener so each qualifying purchase renews the discount.
     */
    public function renewFrom(Carbon $base): void
    {
        $this->expires_at = $base->copy()->addDays(self::WINDOW_DAYS);
        $this->save();
    }

    /**
     * Flip `active` off for every discount whose rolling window has lapsed.
     * A mass update (bypasses the `saving` hook on purpose) so deactivating
     * does NOT restart the window. Returns the number deactivated. Driven by
     * the daily scheduler in `routes/console.php`.
     */
    public static function deactivateLapsed(): int
    {
        return self::query()
            ->where('active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['active' => false]);
    }

    /**
     * Find the active discount rule matching a phone number. Both the query
     * input and every stored phone are normalised to bare digits (leading
     * trunk zero dropped), then compared:
     *   1. exact normalised equality, then
     *   2. suffix match (one ends with the other, ≥ 7 digits) so a stored
     *      local number ("33123456") still matches a register phone that
     *      carries the country code ("97333123456") and vice-versa.
     *
     * Returns null when nothing matches (the common walk-in case).
     */
    public static function findForPhone(string $phone): ?self
    {
        $needle = self::normalise($phone);

        if ($needle === '') {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, self> $candidates */
        $candidates = self::query()
            ->where('active', true)
            // Only discounts whose rolling window is still open. A lapsed
            // discount (90 days with no purchase) stops applying here even
            // if the daily sweep hasn't flipped `active` off yet.
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', Carbon::now());
            })
            ->get();

        foreach ($candidates as $candidate) {
            if (self::normalise($candidate->phone) === $needle) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            $stored = self::normalise($candidate->phone);

            if (strlen($stored) >= 7 && strlen($needle) >= 7
                && (str_ends_with($needle, $stored) || str_ends_with($stored, $needle))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Strip every non-digit and a single leading national-trunk zero so
     * differently-formatted versions of the same number compare equal.
     */
    public static function normalise(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.customer_discount',
            name: 'Customer Discount',
            class: self::class,
            table: 'pos_customer_discounts',
            module: 'pos',
            fields: [
                new FieldDefinition('phone', 'Phone', 'char', required: true, sequence: 10),
                new FieldDefinition('discount_percent', 'Discount %', 'float', required: true, sequence: 20),
                new FieldDefinition('label', 'Label', 'char', sequence: 30),
                new FieldDefinition('active', 'Active', 'boolean', sequence: 40),
                new FieldDefinition('expires_at', 'Expires', 'datetime', sequence: 50),
            ],
            views: [
                new ViewDefinition('Customer Discounts', 'list', [
                    'columns' => [
                        ['field' => 'phone', 'label' => 'Phone', 'sortable' => true],
                        ['field' => 'label', 'label' => 'Label', 'sortable' => true],
                        ['field' => 'discount_percent', 'label' => 'Discount %', 'align' => 'right', 'sortable' => true],
                        ['field' => 'expires_at', 'label' => 'Expires', 'format' => 'datetime', 'sortable' => true],
                        ['field' => 'active', 'label' => 'Active', 'format' => 'toggle'],
                    ],
                    'default_sort' => [['field' => 'phone', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/customer_discount/{id}',
                    'searchable' => ['phone', 'label'],
                ]),
                new ViewDefinition('Customer Discount', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'phone', 'label' => 'Phone', 'widget' => 'text', 'required' => true, 'placeholder' => '+973 33123456', 'help' => 'The customer phone this discount applies to. Country code optional — it matches with or without it.'],
                        ['field' => 'discount_percent', 'label' => 'Discount %', 'widget' => 'number', 'required' => true, 'help' => 'Percent off the whole order total (0–100). Applied when this customer is added at the register.'],
                        ['field' => 'label', 'label' => 'Label', 'widget' => 'text', 'placeholder' => 'e.g. VIP — Abu Ali', 'help' => 'Optional note so you recognise this number. Never shown to the customer.'],
                        ['field' => 'active', 'label' => 'Active', 'widget' => 'checkbox', 'help' => 'Auto-expires 90 days after activation if the customer doesn\'t buy. Each purchase within the window renews it for another 90 days. Re-enabling a lapsed one starts a fresh 90 days.'],
                    ],
                ]),
            ],
        );
    }
}
