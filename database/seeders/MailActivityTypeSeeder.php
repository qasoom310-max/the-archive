<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Mail\MailActivityType;
use Illuminate\Database\Seeder;

final class MailActivityTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'To Do', 'icon' => 'clipboard-document-check', 'default_days' => 0, 'sequence' => 10],
            ['name' => 'Email', 'icon' => 'envelope', 'default_days' => 0, 'sequence' => 20],
            ['name' => 'Call', 'icon' => 'phone', 'default_days' => 1, 'sequence' => 30],
            ['name' => 'Meeting', 'icon' => 'calendar', 'default_days' => 2, 'sequence' => 40],
        ];

        foreach ($types as $type) {
            MailActivityType::query()->updateOrCreate(['name' => $type['name']], $type);
        }
    }
}
