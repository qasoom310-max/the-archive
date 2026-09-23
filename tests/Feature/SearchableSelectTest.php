<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Tests\TestCase;

/**
 * The searchable select.
 *
 * A long list — every customer, every car — is unusable as a plain select: you
 * scroll for a name you already know. The picker puts a search box over it.
 *
 * It is an enhancement over a REAL control rather than a replacement for one:
 * the <select> stays in the DOM carrying the wire:model, so the binding, its
 * modifiers and validation all behave exactly as they did. That is the property
 * worth pinning down here — the filtering itself is Alpine, in the browser.
 */
final class SearchableSelectTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function render(string $template, array $data = []): string
    {
        return Blade::render($template, $data);
    }

    public function test_it_keeps_a_real_select_carrying_the_binding(): void
    {
        $html = $this->render(
            '<x-searchable-select wire:model.live="customer_id" :options="$options" />',
            ['options' => [['value' => 7, 'label' => 'Helen Friberg']]]
        );

        // The control Livewire binds to is still a select, with the modifier
        // intact — anything else would change how the field behaves.
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('wire:model.live="customer_id"', $html);
        $this->assertStringContainsString('<option value="7">Helen Friberg</option>', $html);
        // And a blank first option, so the field can be cleared.
        $this->assertStringContainsString('<option value="">', $html);
    }

    public function test_the_caller_class_dresses_the_visible_control(): void
    {
        $html = $this->render(
            '<x-searchable-select wire:model="car_id" class="o-input w-full" :options="$options" />',
            ['options' => []]
        );

        // The button is what the user sees, so it wears the caller's classes…
        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*o-input w-full/', $html);
        // …while the select behind it is hidden rather than styled.
        $this->assertMatchesRegularExpression('/<select[^>]*class="sr-only"/', $html);
    }

    public function test_it_carries_nested_field_names(): void
    {
        // Leg pickers bind to `legs.0.car_id`; the picker must not mangle it.
        $html = $this->render(
            '<x-searchable-select wire:model="legs.0.car_id" :options="$options" />',
            ['options' => [['value' => 3, 'label' => 'Mercedes']]]
        );

        $this->assertStringContainsString('wire:model="legs.0.car_id"', $html);
    }

    /** The list on screen is exactly what the component was given. */
    public function test_every_option_reaches_the_markup(): void
    {
        $options = [];
        foreach (range(1, 60) as $i) {
            $options[] = ['value' => $i, 'label' => "Customer {$i}"];
        }

        $html = $this->render('<x-searchable-select wire:model="x" :options="$options" />', ['options' => $options]);

        $this->assertSame(61, substr_count($html, '<option '));
        $this->assertStringContainsString('Customer 60', $html);
    }

    /**
     * The real thing: the booking form's customer field still selects a
     * customer. If the picker had broken the binding, this is where it shows.
     */
    public function test_the_booking_form_still_binds_its_customer(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '+973 1234']);

        Livewire::test(BookingForm::class)
            ->assertOk()
            // Rendered through the picker, name and number together — which is
            // what makes searching by either of them work.
            ->assertSee('Helen Friberg · +973 1234')
            ->set('customer_id', $customer->id)
            ->assertSet('customer_id', $customer->id);
    }

    /** Picking a customer still fills the passenger block, as .live promises. */
    public function test_the_live_modifier_still_fires_its_side_effect(): void
    {
        $customer = LimoCustomer::query()->create([
            'name' => 'Helen Friberg',
            'phone' => '+973 1234',
        ]);

        $component = Livewire::test(BookingForm::class)->set('customer_id', $customer->id);

        // updatedCustomerId() ran off the binding the picker writes to.
        $component->assertSet('pax_contact', '+973 1234');
    }

    /**
     * Opening a saved record shows the chosen name straight away. The HTML
     * itself has to say which option is on: Livewire only fills the control
     * once the page is running, and the button reads the name before that —
     * so a saved booking's customer read as "— Select —" until someone
     * opened the list, which re-read it.
     */
    public function test_a_saved_records_choice_is_marked_in_the_html(): void
    {
        $chosen = LimoCustomer::query()->create(['name' => 'Braxtone Plus W.L.L']);
        $other = LimoCustomer::query()->create(['name' => 'Someone Else']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $chosen->id,
            'pickup_at' => '2026-07-05 09:00:00',
            'pax_name' => 'Sara', 'requested_by' => 'Office', 'prepared_by' => 'Office',
        ]);

        $html = Livewire::test(BookingForm::class, ['id' => $booking->id])->html();

        $this->assertStringContainsString('value="'.$chosen->id.'" selected', $html);
        $this->assertStringNotContainsString('value="'.$other->id.'" selected', $html);
    }

    public function test_a_new_record_marks_nothing(): void
    {
        LimoCustomer::query()->create(['name' => 'Braxtone Plus W.L.L']);

        $this->assertStringNotContainsString(' selected', Livewire::test(BookingForm::class)->html());
    }

    /**
     * The picker re-reads the control on `change`, from a listener Alpine adds
     * before the one wire:model puts on the select (the wrapper initialises
     * first). So a write to the control inside sync() lands BEFORE Livewire has
     * read the event — which is how a freshly picked customer was wiped back to
     * the old value and a new booking's picker did nothing at all.
     *
     * There is no JS test runner here, so this pins the rule where it can be
     * checked: the control is what the person and Livewire both write; sync()
     * only ever reads it.
     */
    public function test_the_picker_never_writes_back_to_the_control_while_reading_it(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/app.js'));

        // Anchored to this component: another one on the page has a sync() too.
        $component = strpos($js, "Alpine.data('searchableSelect'");
        $this->assertNotFalse($component, 'The picker component has been renamed — re-point this guard.');

        $start = strpos($js, 'sync() {', $component);
        $this->assertNotFalse($start, 'searchableSelect.sync() has been renamed — re-point this guard.');

        // As far as the next method on the component.
        $end = strpos($js, 'get matches()', $start);
        $this->assertNotFalse($end);

        $body = substr($js, $start, $end - $start);

        $this->assertDoesNotMatchRegularExpression(
            '/native\.value\s*=[^=]/',
            $body,
            'sync() assigns to the control. That overwrites a choice before Livewire reads it.'
        );
    }
}
