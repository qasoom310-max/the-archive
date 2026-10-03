<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Laptops and desktops are signed out after 1 hour without input; phones
 * are not. The timing lives in the browser (only it can see a mouse move), so
 * these tests pin the server half - the idle endpoint - and that the timer is
 * actually shipped on every signed-in page.
 */
final class IdleLogoutTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_idle_endpoint_signs_out_and_says_why(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout/idle')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were signed out after 1 hour of inactivity.');

        $this->assertGuest();
    }

    public function test_an_idle_sign_out_is_audited_apart_from_a_deliberate_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout/idle');
        $this->actingAs($user)->post('/logout');

        $this->assertSame(1, ActivityLog::query()->where('action', 'logout_idle')->where('user_id', $user->getKey())->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'logout')->where('user_id', $user->getKey())->count());
    }

    public function test_a_guest_cannot_hit_the_idle_endpoint(): void
    {
        $this->post('/logout/idle')->assertRedirect(route('login'));
    }

    public function test_every_signed_in_page_ships_the_desktop_only_idle_timer(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('window.__erpIdleLogout', $html);
        $this->assertStringContainsString("(pointer: fine)", $html);
        $this->assertStringContainsString('60 * 60 * 1000', $html);
        // @js() escapes the slashes in the URL it writes out.
        $this->assertStringContainsString(route('logout.idle'), str_replace('\/', '/', $html));
    }

    public function test_the_sign_in_page_clears_the_stale_activity_clock(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString("localStorage.removeItem('erp.lastActivity')", $html);
    }
}
