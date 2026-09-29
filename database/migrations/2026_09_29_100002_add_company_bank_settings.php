<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How to pay: the cheque payee and the bank account printed under the totals
 * of every invoice (the shared <x-bank-details /> component), as on the
 * office's old printed invoice.
 *
 * Insert-only, and a core migration rather than a seeder edit alone, because
 * `SettingSeeder` only runs against Main on deploy while `workspaces:migrate`
 * carries a core migration into every workspace.
 *
 * Wanaan's database is recognised by its company name or its VAT number and
 * gets the account from its printed invoice. A detail somebody already typed
 * is never overwritten, and every other database starts blank, so nothing
 * prints there until its own admin fills Settings → General in.
 */
return new class extends Migration
{
    /** @var list<array{key: string, label: string, sort: int, description: string}> */
    private array $params = [
        ['key' => 'company.bank_payee', 'label' => 'Cheques payable to', 'sort' => 28, 'description' => 'Printed on invoices. Leave blank to use the company name.'],
        ['key' => 'company.bank_name', 'label' => 'Bank name', 'sort' => 29, 'description' => 'Printed on invoices for bank transfers.'],
        ['key' => 'company.bank_account', 'label' => 'Bank account number', 'sort' => 30, 'description' => 'Printed on invoices for bank transfers.'],
        ['key' => 'company.bank_iban', 'label' => 'IBAN', 'sort' => 31, 'description' => 'Printed on invoices for bank transfers.'],
        ['key' => 'company.bank_swift', 'label' => 'SWIFT code', 'sort' => 32, 'description' => 'Printed on invoices for bank transfers from abroad.'],
    ];

    /** From Wanaan's printed invoice. @var array<string, string> */
    private array $wanaan = [
        'company.bank_payee' => 'WANAAN CAR RENTAL W.L.L',
        'company.bank_name' => 'Al Salam Bank',
        'company.bank_account' => '765765150000',
        'company.bank_iban' => 'BH47ALSA00765765150000',
        'company.bank_swift' => 'ALSABHBM',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        $isWanaan = $this->isWanaan();

        foreach ($this->params as $p) {
            $value = $isWanaan ? $this->wanaan[$p['key']] : '';
            $existing = DB::table('ir_config_parameter')->where('key', $p['key'])->first(['value']);

            if ($existing !== null) {
                if ($value !== '' && trim((string) $existing->value) === '') {
                    DB::table('ir_config_parameter')->where('key', $p['key'])->update(['value' => $value, 'updated_at' => now()]);
                }

                continue;
            }

            DB::table('ir_config_parameter')->insert([
                'key' => $p['key'],
                'value' => $value,
                'type' => 'string',
                'group' => 'General',
                'label' => $p['label'],
                'description' => $p['description'],
                'sort' => $p['sort'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The settings are cached per database.
        try {
            app(\App\Erp\Settings\SettingManager::class)->flush();
        } catch (Throwable) {
            // A stale cache only delays the change until the next save.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        DB::table('ir_config_parameter')
            ->whereIn('key', array_column($this->params, 'key'))
            ->delete();
    }

    private function isWanaan(): bool
    {
        $name = strtolower((string) DB::table('ir_config_parameter')->where('key', 'company.name')->value('value'));
        $vat = preg_replace('/\D/', '', (string) DB::table('ir_config_parameter')->where('key', 'company.vat_number')->value('value'));

        return str_contains($name, 'wanaan') || $vat === '220015215500002';
    }
};
