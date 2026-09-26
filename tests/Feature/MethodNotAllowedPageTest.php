<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * The page shown when an address is reached the wrong way round.
 *
 * Laravel ships its own templates for 404, 403, 419, 500 and a few more, but
 * NOT for 405 — so with debug off it fell through to Symfony's stock "Oops!
 * An Error Occurred … Something is broken. Please let us know", which reads
 * to the office like the system has failed and offers nowhere to go.
 *
 * It also carries the method and path, because this arrives as a photograph
 * of a phone showing a bare domain: the one fact that identifies which
 * address was hit should not have to be fished out of the address bar.
 */
final class MethodNotAllowedPageTest extends TestCase
{
    use DatabaseMigrations;

    /** A GET at an import endpoint — the commonest way to meet a 405 here. */
    public function test_a_get_at_a_post_only_address_shows_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $response = $this->get('/logout');

        $response->assertStatus(405);
        $response->assertSee('This page cannot be opened directly');
        $response->assertDontSee('Something is broken');
    }

    public function test_the_page_names_the_address_that_was_refused(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $response = $this->get('/logout');

        $response->assertSee('GET logout');
    }

    /** A way out, rather than a dead end. */
    public function test_the_page_offers_the_way_back(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $response = $this->get('/logout');

        $response->assertSee('Go back');
        $response->assertSee(url('/'));
    }

    /**
     * Deliberately NOT the 419 page's trick. That one meta-refreshes the
     * current address; here the current address is precisely the one that
     * cannot be opened this way, so a refresh would loop for ever.
     */
    public function test_the_page_does_not_reload_itself(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $html = $this->get('/logout')->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('http-equiv="refresh"', $html);
        $this->assertStringNotContainsString('location.replace', $html);
    }
}
