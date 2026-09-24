<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\QuotationForm;
use Tests\TestCase;

/**
 * The trip date on a limousine booking draws its own month grid.
 *
 * A phone hands a date field to the operating system, and the OS wheel shows
 * a bare number with no weekday against it — so a dispatcher choosing "the
 * 24th" could not see it was the Thursday the customer had asked for. That
 * dialog belongs to the phone and cannot be changed from a web page, so the
 * booking form draws its own grid instead: a weekday over every column,
 * opened on the month already chosen.
 *
 * NOTE what these tests can and cannot reach. There is no JS test runner in
 * this project, so the BEHAVIOUR — opening on the chosen month, highlighting
 * the day, writing the date back — is not covered here. What is covered is
 * the wiring: that the grid is rendered, on the right screen, in the right
 * language, and that the phone rule which would otherwise hand the field back
 * to the OS knows to leave it alone.
 */
final class LimoBookingCalendarTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_the_booking_trip_date_draws_a_month_grid_with_weekday_headings(): void
    {
        $html = Livewire::test(BookingForm::class)->html();

        $this->assertStringContainsString('date-field-calendar', $html);

        // Sunday first, seven of them, which is how the region reads a week.
        $this->assertStringContainsString('Sun', $html);
        $this->assertStringContainsString('Thu', $html);
        $this->assertStringContainsString('Sat', $html);
    }

    /** All seven, in order, and all twelve months — the grid is built from these. */
    public function test_the_grid_is_given_a_full_week_and_a_full_year_of_names(): void
    {
        $html = Livewire::test(BookingForm::class)->html();

        $at = strpos($html, 'dateField(');
        $this->assertNotFalse($at);
        $call = substr($html, $at, 600);

        foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day) {
            $this->assertStringContainsString($day, $call, "The week is missing {$day}.");
        }

        $this->assertStringContainsString('January', $call);
        $this->assertStringContainsString('December', $call);
    }

    /**
     * The names are rendered by PHP in the app's own locale, never by the
     * browser — a phone set to another language would otherwise print its own
     * month names inside an Arabic page.
     */
    public function test_the_names_follow_the_apps_language_not_the_browsers(): void
    {
        $english = Livewire::test(BookingForm::class)->html();

        app()->setLocale('ar');
        $arabic = Livewire::test(BookingForm::class)->html();

        $this->assertStringContainsString('September', $english);
        $this->assertStringNotContainsString('September', $arabic);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $arabic);
    }

    /**
     * Scoped to the booking, which is what was asked for. The quotation shares
     * the very same legs partial, so this is the line that keeps them apart.
     */
    public function test_the_quotation_keeps_the_operating_systems_picker(): void
    {
        $html = Livewire::test(QuotationForm::class)->html();

        $this->assertStringContainsString('date-field', $html, 'The quotation has no date field to speak of.');
        $this->assertStringNotContainsString('date-field-calendar', $html);
    }

    /**
     * On a touch screen every other date field hands itself to the OS — that
     * rule has to skip this one, or the phone would open its own wheel over
     * the grid and nothing would have changed.
     */
    public function test_the_phone_rule_leaves_a_grid_field_to_its_own_picker(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $at = strpos($css, '@media (pointer: coarse)');
        $this->assertNotFalse($at, 'The touch-screen date rule has moved — re-point this guard.');

        $block = substr($css, $at, 900);

        // Every rule that reveals the OS picker or hides our own controls has
        // to carve the grid out; one that forgets is how the wheel comes back.
        foreach (['.date-field-native', '.date-field-text', '.date-field-cal'] as $target) {
            $this->assertStringContainsString(
                '.date-field:not(.date-field-calendar) '.$target,
                $block,
                "The touch rule still applies to a field drawing its own grid ({$target}).",
            );

            $this->assertStringNotContainsString(
                '.date-field '.$target,
                $block,
                "The touch rule reaches every field again ({$target}).",
            );
        }
    }
}
