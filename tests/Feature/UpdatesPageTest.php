<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Updates\UpdateLog;
use App\Livewire\Pages\Updates;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "What's new": the system's own news page.
 *
 * Two jobs. The page itself (everyone signed in can read it, in their
 * language). And the gate on the entries: every file in `resources/updates/`
 * is read through the same checks the page uses, so a malformed or
 * half-translated entry fails here and never reaches the live site.
 */
final class UpdatesPageTest extends TestCase
{
    use DatabaseMigrations;

    public function test_every_update_file_is_well_formed_and_in_both_languages(): void
    {
        $files = glob(resource_path('updates/*.json'));
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files, 'There should be at least one update entry.');

        $log = new UpdateLog();
        foreach ($files as $file) {
            // Throws, naming the file and the fault, on anything wrong.
            $entry = $log->read($file);
            $this->assertNotSame('', $entry->titleIn('ar'));
        }

        // Only entry files live in that folder (plus the README).
        $others = array_diff(scandir(resource_path('updates')) ?: [], ['.', '..', 'README.md', 'images']);
        foreach ($others as $name) {
            $this->assertStringEndsWith('.json', $name, "Unexpected file in resources/updates: {$name}");
        }
    }

    public function test_anyone_signed_in_can_read_it_not_only_admins(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/updates')->assertOk()->assertSee("What's new")
            ->assertSee('data-updates-link', false);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/updates')->assertRedirect('/login');
    }

    public function test_it_lists_entries_newest_first(): void
    {
        $log = $this->fakeLog([
            '2026-01-05-older' => $this->entry('limousine', 'Older thing', 'شيء أقدم'),
            '2026-02-10-newer' => $this->entry('rental', 'Newer thing', 'شيء أحدث'),
        ]);

        $this->assertSame('2026-02-10-newer', $log->latestKey());

        $this->actingAs(User::factory()->create());
        Livewire::test(Updates::class)
            ->assertSeeInOrder(['10/02/2026', 'Newer thing', '05/01/2026', 'Older thing']);
    }

    public function test_it_reads_in_arabic_when_the_user_does(): void
    {
        $this->fakeLog(['2026-02-10-newer' => $this->entry('rental', 'Newer thing', 'شيء أحدث')]);
        $this->actingAs(User::factory()->create());
        app()->setLocale('ar');

        Livewire::test(Updates::class)
            ->assertSee('شيء أحدث')
            ->assertDontSee('Newer thing');
    }

    public function test_it_filters_by_app_and_by_search(): void
    {
        $this->fakeLog([
            '2026-01-05-a' => $this->entry('limousine', 'Trip numbers on invoices', 'أرقام الرحلات'),
            '2026-02-10-b' => $this->entry('rental', 'Several cars on a quotation', 'عدة سيارات'),
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Updates::class)
            ->call('setApp', 'rental')
            ->assertSee('Several cars on a quotation')
            ->assertDontSee('Trip numbers on invoices')
            ->call('setApp', '')
            ->set('search', 'trip')
            ->assertSee('Trip numbers on invoices')
            ->assertDontSee('Several cars on a quotation')
            // Arabic words find the entry too, whatever language is showing.
            ->set('search', 'عدة')
            ->assertSee('Several cars on a quotation')
            ->set('search', 'nothing like this')
            ->assertSee('No updates match.');
    }

    public function test_an_unknown_app_in_the_address_bar_shows_everything(): void
    {
        $this->fakeLog(['2026-02-10-b' => $this->entry('rental', 'Several cars on a quotation', 'عدة سيارات')]);
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['app' => 'not-an-app'])
            ->test(Updates::class)
            ->assertOk()
            ->assertSee('Several cars on a quotation');
    }

    public function test_a_broken_entry_is_refused_with_the_reason(): void
    {
        $dir = $this->tempDir();

        $cases = [
            'bad-name.json' => $this->entry('rental', 'x', 'س'),
            '2026-02-30-not-a-date.json' => $this->entry('rental', 'x', 'س'),
            '2026-02-10-no-arabic.json' => ['app' => 'rental', 'title' => ['en' => 'x'], 'points' => ['en' => ['y']]],
            '2026-02-10-bad-app.json' => $this->entry('spaceship', 'x', 'س'),
            '2026-02-10-uneven.json' => ['app' => 'rental', 'title' => ['en' => 'x', 'ar' => 'س'], 'points' => ['en' => ['a', 'b'], 'ar' => ['أ']]],
            // From 16 Nov 2026 the summary alone is not enough.
            '2026-11-16-summary-only.json' => $this->entry('rental', 'x', 'س'),
            '2026-02-10-guide-one-language.json' => $this->entry('rental', 'x', 'س') + ['guide' => ['en' => ['Step.']]],
            '2026-02-10-picture-missing.json' => $this->entry('rental', 'x', 'س') + ['images' => [['after' => 'nowhere.png', 'caption' => ['en' => 'c', 'ar' => 'ت']]]],
            '2026-02-10-picture-path.json' => $this->entry('rental', 'x', 'س') + ['images' => [['after' => '../../.env', 'caption' => ['en' => 'c', 'ar' => 'ت']]]],
        ];

        $log = new UpdateLog($dir);
        foreach ($cases as $name => $data) {
            file_put_contents("{$dir}/{$name}", json_encode($data, JSON_UNESCAPED_UNICODE));

            try {
                $log->read("{$dir}/{$name}");
                $this->fail("{$name} should have been refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString(basename($name, '.json'), $e->getMessage());
            }
        }
    }

    public function test_an_update_opens_with_its_explanation_pictures_and_guide(): void
    {
        $full = $this->entry('rental', 'Several cars on a quotation', 'عدة سيارات') + [
            'details' => ['en' => ['Why this was needed.'], 'ar' => ['لماذا كان هذا مطلوباً.']],
            'guide' => ['en' => ['Open the quotation.', 'Press Add another car.'], 'ar' => ['افتح عرض السعر.', 'اضغط إضافة سيارة أخرى.']],
            'images' => [['before' => 'before.png', 'after' => 'after.png', 'caption' => ['en' => 'The car lines.', 'ar' => 'سطور السيارات.']]],
        ];
        $log = $this->fakeLog(
            ['2026-11-20-many-cars' => $full, '2026-01-05-old' => $this->entry('rental', 'Old summary', 'ملخص قديم')],
            ['2026-11-20-many-cars' => ['before.png', 'after.png', 'unlisted.png']],
        );
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        // The list links to the full story only where there is one.
        Livewire::test(Updates::class)
            ->assertSeeHtml('/updates/2026-11-20-many-cars')
            ->assertDontSeeHtml('/updates/2026-01-05-old');

        $this->get('/updates/2026-11-20-many-cars')->assertOk()
            ->assertSeeInOrder(['Several cars on a quotation', 'Why this was needed.', 'Before', 'After', 'The car lines.', 'Open the quotation.', 'Press Add another car.'])
            ->assertSee('/updates/2026-11-20-many-cars/image/before.png', false)
            ->assertSee('/updates/2026-11-20-many-cars/image/after.png', false);

        $this->get('/updates/2026-01-01-no-such-update')->assertNotFound();

        // A picture is served to a signed-in user…
        $this->get('/updates/2026-11-20-many-cars/image/after.png')->assertOk();
        // …but never a file the entry does not list, even one sitting beside it.
        $this->get('/updates/2026-11-20-many-cars/image/unlisted.png')->assertNotFound();
        $this->assertNull($log->imagePath('2026-11-20-many-cars', '../2026-11-20-many-cars.json'));
    }

    public function test_pictures_need_sign_in(): void
    {
        $this->fakeLog(
            ['2026-11-20-many-cars' => $this->entry('rental', 'x', 'س') + [
                'details' => ['en' => ['d'], 'ar' => ['ت']], 'guide' => ['en' => ['g'], 'ar' => ['د']],
                'images' => [['after' => 'after.png', 'caption' => ['en' => 'c', 'ar' => 'ت']]],
            ]],
            ['2026-11-20-many-cars' => ['after.png']],
        );

        $this->get('/updates/2026-11-20-many-cars/image/after.png')->assertRedirect('/login');
    }

    /**
     * @return array{app: string, title: array<string, string>, points: array<string, list<string>>}
     */
    private function entry(string $app, string $en, string $ar): array
    {
        return ['app' => $app, 'title' => ['en' => $en, 'ar' => $ar], 'points' => ['en' => ['Point.'], 'ar' => ['نقطة.']]];
    }

    /**
     * Point the page at a folder of made-up entries, so these tests do not
     * depend on what news happens to be in the repository.
     *
     * @param array<string, array<string, mixed>> $entries  key => entry
     * @param array<string, list<string>>         $pictures key => picture files to create for it
     */
    private function fakeLog(array $entries, array $pictures = []): UpdateLog
    {
        $dir = $this->tempDir();
        foreach ($pictures as $key => $files) {
            mkdir("{$dir}/images/{$key}", 0777, true);
            foreach ($files as $file) {
                // The smallest valid PNG: one transparent pixel.
                file_put_contents("{$dir}/images/{$key}/{$file}", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));
            }
        }
        foreach ($entries as $key => $data) {
            file_put_contents("{$dir}/{$key}.json", json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $log = new UpdateLog($dir);
        $this->app->instance(UpdateLog::class, $log);

        return $log;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/erp-updates-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
        });

        return $dir;
    }
}
