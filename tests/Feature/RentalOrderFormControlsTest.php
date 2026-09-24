<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Tests\TestCase;

/**
 * The rental order form's controls are actually there to be used.
 *
 * Two of its fields were invisible on the live site. The pick-up and return
 * dates carried a conditional `min` as an @if INSIDE the component tag, and
 * Blade's component compiler cannot read a tag whose attributes hold a
 * directive — it gives up and prints `<x-date-field …>` as literal text. A
 * browser makes nothing of an unknown element, so the field simply was not
 * drawn, with no error anywhere to say so. The car picker was a <button>
 * wearing `o-input`, which sets a border COLOUR but no border WIDTH, so it
 * drew no box either.
 */
final class RentalOrderFormControlsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_every_component_on_the_order_form_is_compiled(): void
    {
        $html = Livewire::test(OrderForm::class)->html();

        $this->assertStringNotContainsString(
            '<x-',
            $html,
            'A component tag reached the browser uncompiled, so its field is invisible.',
        );
    }

    /** All three date boxes are drawn: order date, pick-up and return. */
    public function test_the_date_fields_are_drawn(): void
    {
        $html = Livewire::test(OrderForm::class)->html();

        $this->assertSame(3, substr_count($html, 'class="date-field '));
        $this->assertStringContainsString('wire:model.live="start_date"', $html);
        $this->assertStringContainsString('wire:model.live="end_date"', $html);
    }

    /**
     * The conditional `min` moved out of the tag, so this checks it still
     * arrives — a fix that silently dropped the rule would let someone book a
     * return before the pick-up.
     */
    public function test_the_return_date_cannot_be_set_before_the_pick_up(): void
    {
        $html = Livewire::test(OrderForm::class)
            ->set('start_date', '2026-10-05')
            ->html();

        $this->assertMatchesRegularExpression(
            '/<input type="date"[^>]*wire:model[^>]*="end_date"[^>]*min="2026-10-05"/',
            $html,
            'The return date no longer carries the earliest day it may be.',
        );
    }

    /** The car picker is a button, so it needs the box drawn by hand. */
    public function test_the_car_picker_draws_a_box(): void
    {
        $html = Livewire::test(OrderForm::class)->html();

        $at = strpos($html, 'Search car or plate');
        $this->assertNotFalse($at, 'The car picker is gone — re-point this guard.');

        $button = strrpos(substr($html, 0, $at), '<button');
        $this->assertNotFalse($button);

        $markup = substr($html, (int) $button, $at - (int) $button);
        $this->assertStringContainsString('border', $markup);
        $this->assertStringContainsString('px-3', $markup);
    }

    /**
     * Repo-wide, because the failure is SILENT: no exception, no log, just a
     * field that is not there. Nothing warns you, so the guard has to.
     */
    public function test_no_blade_component_tag_hides_a_directive(): void
    {
        $offenders = [];

        foreach (['Modules', 'resources/views', 'app'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                // An opening <x-…> tag, up to its close, carrying a directive.
                if (preg_match('/<x-[a-zA-Z0-9.:_-]+[^>]*@(if|unless|else|endif|endunless|isset|empty|foreach|php)\b/', $contents) === 1) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A Blade directive inside a component tag stops the tag compiling, and the field vanishes with no error:\n".implode("\n", $offenders),
        );
    }
}
