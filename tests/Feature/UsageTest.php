<?php

namespace Tests\Feature;

use App\Models\Droid;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Worker;
use App\Worker\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Plan usage the worker reads from its logins, and tokens used through Droids. */
class UsageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->dir = sys_get_temp_dir().'/droids-usage-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['droids.worker.logins' => $this->dir, 'droids.worker.default_login' => ['claude' => null, 'codex' => null]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_worker_reads_claude_and_codex_usage_from_its_logins(): void
    {
        File::ensureDirectoryExists($this->dir.'/claude-max');
        File::put($this->dir.'/claude-max/.credentials.json', json_encode(['claudeAiOauth' => ['accessToken' => 'sk-ant-oat-x']]));
        File::ensureDirectoryExists($this->dir.'/chatgpt-plus/sessions/2026/11/27');
        $reset = now()->addHours(2)->timestamp;
        File::put($this->dir.'/chatgpt-plus/auth.json', '{}');
        File::put($this->dir.'/chatgpt-plus/sessions/2026/11/27/rollout-1.jsonl', implode("\n", [
            json_encode(['timestamp' => now()->toIso8601String(), 'type' => 'event_msg', 'payload' => ['type' => 'token_count', 'rate_limits' => [
                'primary' => ['used_percent' => 10, 'window_minutes' => 300, 'resets_at' => $reset],
            ]]]),
            json_encode(['timestamp' => now()->toIso8601String(), 'type' => 'event_msg', 'payload' => ['type' => 'token_count', 'rate_limits' => [
                'primary' => ['used_percent' => 42.5, 'window_minutes' => 300, 'resets_at' => $reset],
                'secondary' => ['used_percent' => 7, 'window_minutes' => 10080, 'resets_in_seconds' => 3600],
            ]]]),
        ]));
        Http::fake([Usage::CLAUDE_URL => Http::response([
            'five_hour' => ['utilization' => 64.0, 'resets_at' => now()->addHour()->toIso8601String()],
            'seven_day' => ['utilization' => 12.0, 'resets_at' => now()->addDays(3)->toIso8601String()],
            'seven_day_opus' => null,
        ])]);

        $usage = (new Usage)->read();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-ant-oat-x') && $r->hasHeader('anthropic-beta'));
        $this->assertSame(['5-hour window', 'Weekly limit'], array_column($usage['claude-max'], 'label'));
        $this->assertSame([0.64, 0.12], array_column($usage['claude-max'], 'used'));
        $this->assertSame(['5-hour window', 'Weekly limit'], array_column($usage['chatgpt-plus'], 'label'));
        $this->assertSame([0.425, 0.07], array_column($usage['chatgpt-plus'], 'used'));
    }

    public function test_a_login_without_usage_is_left_out(): void
    {
        File::ensureDirectoryExists($this->dir.'/claude-team');

        $this->assertSame([], (new Usage)->read());
    }

    public function test_reported_usage_shows_on_the_subscriptions_page(): void
    {
        $sub = Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        Subscription::create(['slug' => 'other', 'provider' => 'claude', 'name' => 'Other', 'color' => 'red', 'enabled' => true]);
        [$worker, $token] = Worker::issue('MacBook');
        $worker->forceFill(['subscriptions' => ['claude-max']])->save();
        $droid = Droid::create(['slug' => 'cog', 'unit' => 'CL-01', 'name' => 'Cog', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder']);
        Run::forceCreate(['droid_id' => $droid->id, 'subscription_id' => $sub->id, 'kind' => 'chat', 'status' => 'succeeded', 'tokens_in' => 1500, 'tokens_out' => 500]);

        $this->withToken($token)->postJson('/api/worker/usage', ['usage' => [
            'claude-max' => [
                ['label' => '5-hour window', 'used' => 1, 'resets_at' => now()->addHours(2)->toIso8601String()],
                ['label' => 'Weekly limit', 'used' => 0.3, 'resets_at' => now()->addDays(3)->toIso8601String()],
            ],
            'other' => [['label' => '5-hour window', 'used' => 0.5]],
        ]])->assertOk();

        $this->assertTrue($sub->fresh()->isLimited(), 'a full window holds the subscription back');
        $this->assertNull(Subscription::where('slug', 'other')->value('usage_at'), 'only logins on that worker count');

        $this->actingAs(User::factory()->create());
        $subs = $this->getJson('/factory/state')->assertOk()->json('store.subs');
        $card = collect($subs)->firstWhere('id', 'claude-max');
        $this->assertSame(1, $card['windows'][0]['used']);
        $this->assertStringStartsWith('resets ', $card['windows'][0]['resets']);
        $this->assertNotNull($card['usageAt']);
        $this->assertSame(2000, $card['tokens']['week']);
        $this->assertSame(2000, $card['tokens']['days'][now()->dayOfWeekIso - 1]);
    }
}
