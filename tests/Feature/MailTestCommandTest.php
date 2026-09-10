<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The CLI diagnostic for "the app says it sent an email but nothing
 * arrived" — reports which mailer is configured and whether the send
 * actually went through, so it can be run against production over SSH
 * without reading .env or trawling logs.
 *
 * No Mail::fake() here on purpose — phpunit.xml already pins MAIL_MAILER to
 * "array" for the whole suite (no network call, nothing delivered), so this
 * exercises the command's real Mail::raw() path exactly as it runs in prod.
 */
final class MailTestCommandTest extends TestCase
{
    public function test_it_sends_the_test_message_and_reports_success(): void
    {
        $this->artisan('mail:test', ['email' => 'owner@example.com'])
            ->expectsOutputToContain('Mailer (MAIL_MAILER): array')
            ->expectsOutputToContain('Handed off to the "array" mailer with no error.')
            ->assertSuccessful();
    }

    public function test_an_invalid_address_is_refused_before_anything_is_sent(): void
    {
        $this->artisan('mail:test', ['email' => 'not-an-email'])
            ->assertFailed();
    }
}
