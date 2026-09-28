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
 * When a required field is left blank, the form must not only show the inline
 * error but also tell the browser which field to scroll to and focus — a tall
 * form's required field can sit below the fold, making Save look like it did
 * nothing. The `scroll-to-error` event carries the first failing field.
 */
final class ScrollsToFirstErrorTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
        $this->grantEveryone('limousine.booking');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
    }

    public function test_a_failed_save_dispatches_the_first_error_field(): void
    {
        Livewire::test(BookingForm::class)
            // Everything blank → the first rule (`customer_id` required) fails.
            ->call('save')
            ->assertHasErrors('customer_id')
            ->assertDispatched('scroll-to-error', field: 'customer_id');
    }

    public function test_a_later_required_field_is_the_one_pointed_at(): void
    {
        // Satisfy the earlier required fields so `requested_by` — the field the
        // user actually forgot in the report — is the first failure. `prepared_by`
        // is locked and stamped from the signed-in user on save, so it's already
        // satisfied without being set here.
        Livewire::test(BookingForm::class)
            ->set('customer_id', 1)
            ->set('pax_name', 'Qassim Makhlooq')
            // It starts with the signed-in user's name; a cleared one still fails.
            ->set('requested_by', '')
            ->call('save')
            ->assertHasErrors('requested_by')
            ->assertDispatched('scroll-to-error', field: 'requested_by');
    }

    public function test_a_valid_save_does_not_dispatch_a_scroll(): void
    {
        Livewire::test(BookingForm::class)
            ->set('customer_id', 1)
            ->set('pax_name', 'Qassim')
            ->set('requested_by', 'Ops')
            // `prepared_by` is locked and stamped from the signed-in user on save.
            // A complete transfer leg, so nothing is left invalid.
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.car_id', 1)
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'Hotel')
            ->set('legs.0.start_at', '2026-09-01T10:00')
            ->set('legs.0.rate', '25')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotDispatched('scroll-to-error');
    }
}
