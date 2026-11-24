<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Ai\AiUsage;
use App\Erp\Modules\ModuleManager;
use App\Livewire\Settings\AbdullahSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every Claude answer is costed from its tokens and model, and Settings →
 * Abdullah shows today, this month by use, and last month, in USD and BD.
 */
final class AiUsageTest extends TestCase
{
    use DatabaseMigrations;

    private function answer(string $model, int $in, int $out, int $cacheRead = 0): object
    {
        return (object) ['model' => $model, 'usage' => (object) ['inputTokens' => $in, 'outputTokens' => $out, 'cacheCreationInputTokens' => null, 'cacheReadInputTokens' => $cacheRead]];
    }

    public function test_a_call_is_costed_from_its_tokens_and_model(): void
    {
        // Opus 5: $5 in / $25 out per million.
        $this->assertSame(0.03, AiUsage::cost('claude-opus-5', 1000, 1000));
        // Cache reads at a tenth of the input price; an unknown model uses Opus 5.
        $this->assertSame(0.0005, AiUsage::cost('claude-opus-5', 0, 0, 0, 1000));
        $this->assertSame(AiUsage::cost('claude-opus-5', 10, 10), AiUsage::cost('some-new-model', 10, 10));

        AiUsage::record('abdullah', $this->answer('claude-opus-5', 2000, 400));
        AiUsage::record('bill_reader', $this->answer('claude-opus-5', 1500, 100));
        AiUsage::record('abdullah', (object) ['model' => 'x']); // no usage: ignored

        $month = AiUsage::between(Carbon::now()->startOfMonth(), Carbon::now());
        $this->assertSame(2, $month['calls']);
        $this->assertSame(0.02, $month['features']['abdullah']['cost']);
        $this->assertSame(0.01, $month['features']['bill_reader']['cost']);
    }

    public function test_the_settings_page_shows_the_spending(): void
    {
        app(ModuleManager::class)->install('whatsapp');
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        AiUsage::record('abdullah', $this->answer('claude-opus-5', 200000, 40000));

        Livewire::test(AbdullahSettings::class)
            ->assertSeeHtml('data-ai-spending')
            ->assertSee('$2.00')
            ->assertSee('0.75 BD')
            ->assertSee('Abdullah messages');
    }
}
