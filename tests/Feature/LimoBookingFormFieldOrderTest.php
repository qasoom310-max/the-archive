<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Tests\TestCase;

/**
 * The passenger's name and number sit right under "Requested by": the desk
 * fills in who asked for the trip, then who rides in it.
 */
final class LimoBookingFormFieldOrderTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_pax_name_and_contact_come_right_after_requested_by(): void
    {
        $html = Livewire::test(BookingForm::class)->html();

        $email = mb_strpos($html, 'wire:model="email"');
        $requested = mb_strpos($html, 'wire:model="requested_by"');
        $paxName = mb_strpos($html, 'wire:model="pax_name"');
        $paxContact = mb_strpos($html, 'wire:model="pax_contact"');
        $preparedBy = mb_strpos($html, 'Prepared by');

        $this->assertNotFalse($requested);
        $this->assertLessThan($requested, $email);
        $this->assertLessThan($paxName, $requested);
        $this->assertLessThan($paxContact, $paxName);
        $this->assertLessThan($preparedBy, $paxContact);
    }
}
