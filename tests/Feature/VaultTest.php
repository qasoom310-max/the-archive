<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Pages\Vault;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The saved logins a business runs on.
 *
 * What is pinned hardest is the handling of the secret itself: it is encrypted
 * in the database, it is never in the page the browser first receives, an
 * owner-only entry cannot be reached by a regular admin even with a crafted
 * id, and every reveal leaves a record of who asked.
 */
final class VaultTest extends TestCase
{
    use DatabaseMigrations;

    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => false]);
    }

    private function entry(string $name, string $password, bool $ownerOnly = true): VaultEntry
    {
        return VaultEntry::query()->create([
            'name' => $name,
            'url' => 'example.com',
            'username' => 'admin@example.com',
            'password' => $password,
            'note' => 'recovery code 1234',
            'owner_only' => $ownerOnly,
        ]);
    }

    // ── The secret itself ────────────────────────────────────────────────────

    public function test_the_password_and_the_note_are_encrypted_in_the_database(): void
    {
        // The note is encrypted too: it is where recovery codes end up, and
        // those are worth as much as the password they protect.
        $this->entry('Hosting', 'sup3rsecret');

        $raw = DB::table('vault_entries')->first();

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sup3rsecret', (string) $raw->password);
        $this->assertStringNotContainsString('recovery code', (string) $raw->note);
        // ... and still reads back correctly through the model.
        $this->assertSame('sup3rsecret', VaultEntry::query()->first()?->password);
    }

    public function test_a_password_is_not_in_the_page_until_it_is_asked_for(): void
    {
        $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->assertSee('Hosting')
            ->assertDontSee('sup3rsecret');
    }

    public function test_revealing_shows_it_and_hiding_takes_it_away_again(): void
    {
        $entry = $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->call('reveal', $entry->id)
            ->assertSee('sup3rsecret')
            ->call('hide', $entry->id)
            ->assertDontSee('sup3rsecret');
    }

    public function test_every_reveal_is_written_to_the_activity_log(): void
    {
        // If a credential ever turns up where it should not, "who looked at
        // it" has to have an answer.
        $entry = $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)->call('reveal', $entry->id);

        $logged = ActivityLog::query()->where('action', 'vault_revealed')->first();

        $this->assertNotNull($logged);
        $this->assertSame('Hosting', $logged->subject);
    }

    public function test_searching_clears_whatever_was_on_screen(): void
    {
        $entry = $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->call('reveal', $entry->id)
            ->assertSee('sup3rsecret')
            ->set('search', 'something else')
            ->assertDontSee('sup3rsecret');
    }

    // ── Who sees what ────────────────────────────────────────────────────────

    public function test_an_owner_only_entry_never_reaches_a_regular_admin(): void
    {
        $this->entry('Bank portal', 'bank-pass', ownerOnly: true);
        $this->entry('Instagram', 'insta-pass', ownerOnly: false);
        $this->actingAs($this->admin());

        Livewire::test(Vault::class)
            ->assertSee('Instagram')
            // Excluded by the QUERY, so the row is not in the page at all -
            // not even as something to count.
            ->assertDontSee('Bank portal');
    }

    public function test_a_crafted_id_cannot_reveal_an_owner_only_entry(): void
    {
        // Hiding it in the view would not be enough: the action takes an id
        // straight off the browser.
        $secret = $this->entry('Bank portal', 'bank-pass', ownerOnly: true);
        $this->actingAs($this->admin());

        Livewire::test(Vault::class)
            ->call('reveal', $secret->id)
            ->assertDontSee('bank-pass');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'vault_revealed']);
    }

    public function test_a_crafted_id_cannot_edit_or_delete_an_owner_only_entry(): void
    {
        $secret = $this->entry('Bank portal', 'bank-pass', ownerOnly: true);
        $this->actingAs($this->admin());

        Livewire::test(Vault::class)
            ->call('edit', $secret->id)
            ->assertSet('editingId', null)
            ->call('delete', $secret->id);

        $this->assertDatabaseHas('vault_entries', ['name' => 'Bank portal']);
    }

    public function test_a_regular_admin_cannot_make_an_entry_private_or_public(): void
    {
        // Who sees a credential is the owner's call, not something an admin
        // can change from the form.
        $shared = $this->entry('Instagram', 'insta-pass', ownerOnly: false);
        $this->actingAs($this->admin());

        Livewire::test(Vault::class)
            ->call('edit', $shared->id)
            ->set('ownerOnly', true)
            ->call('save');

        $this->assertFalse((bool) $shared->fresh()?->owner_only);
    }

    public function test_a_new_entry_is_private_unless_somebody_widens_it(): void
    {
        // The safe direction for a default to fail in.
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->call('create')
            ->assertSet('ownerOnly', true)
            ->set('name', 'Tap payments')
            ->set('password', 'tap-pass')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) VaultEntry::query()->where('name', 'Tap payments')->first()?->owner_only);
    }

    public function test_the_page_is_closed_to_anyone_who_is_not_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/passwords')->assertForbidden();
        Livewire::test(Vault::class)->assertForbidden();
    }

    // ── Editing ──────────────────────────────────────────────────────────────

    public function test_opening_the_form_does_not_hand_over_the_password(): void
    {
        // Opening an entry to fix a typo in its note is not a reveal.
        $entry = $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->call('edit', $entry->id)
            ->assertSet('password', '')
            ->assertDontSee('sup3rsecret');

        $this->assertDatabaseMissing('activity_logs', ['action' => 'vault_revealed']);
    }

    public function test_saving_with_the_password_box_blank_keeps_the_current_one(): void
    {
        // Otherwise a quick note edit silently wipes the credential.
        $entry = $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->call('edit', $entry->id)
            ->set('note', 'moved to the new host')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $entry->fresh();
        $this->assertSame('sup3rsecret', $fresh?->password);
        $this->assertSame('moved to the new host', $fresh?->note);
    }

    public function test_a_service_needs_a_name(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->call('create')
            ->set('password', 'x')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_the_generated_password_is_long_and_typeable(): void
    {
        // No symbols: it has to survive being read down a phone line and typed
        // on a keyboard set to another language.
        $this->actingAs($this->owner());

        $generated = Livewire::test(Vault::class)->call('generate')->get('password');

        $this->assertSame(16, strlen($generated));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $generated);
    }

    public function test_deleting_removes_it_and_is_recorded(): void
    {
        $entry = $this->entry('Old host', 'x');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)->call('delete', $entry->id);

        $this->assertDatabaseMissing('vault_entries', ['name' => 'Old host']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'vault_deleted', 'subject' => 'Old host']);
    }

    public function test_an_address_without_a_scheme_still_opens_as_a_link(): void
    {
        $entry = VaultEntry::query()->create(['name' => 'Host', 'url' => 'wanaan-bh.com/wp-admin']);

        $this->assertSame('https://wanaan-bh.com/wp-admin', $entry->linkUrl());
        $this->assertSame('http://x.test', VaultEntry::query()->create(['name' => 'B', 'url' => 'http://x.test'])->linkUrl());
        $this->assertNull(VaultEntry::query()->create(['name' => 'C'])->linkUrl());
    }

    public function test_search_matches_the_fields_that_are_not_secret(): void
    {
        // Searching a password would mean comparing against decrypted text,
        // and searching a note would leak whether a phrase is in one.
        $this->entry('Hosting', 'sup3rsecret');
        $this->actingAs($this->owner());

        Livewire::test(Vault::class)
            ->set('search', 'Host')
            ->assertSee('Hosting')
            ->set('search', 'sup3rsecret')
            ->assertDontSee('Hosting');
    }
}
