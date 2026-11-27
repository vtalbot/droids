<?php

namespace Tests\Feature;

use App\Integrations\Cloudflare;
use App\Models\Project;
use App\Models\ServiceKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Settings > DNS and a project's DNS tab, on the Cloudflare API. */
class DnsTest extends TestCase
{
    use RefreshDatabase;

    private const API = Cloudflare::URL;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        Project::create(['slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red']);
    }

    private function fakeZone(): void
    {
        ServiceKey::create(['service' => 'cloudflare', 'token' => 'cf-token']);
        Http::fake([
            self::API.'/zones?name=mail.droids.dev' => Http::response(['result' => []]),
            self::API.'/zones?name=droids.dev' => Http::response(['result' => [['id' => 'z1', 'name' => 'droids.dev']]]),
            self::API.'/zones/z1/dns_records?*' => Http::response(['result' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'droids.dev', 'content' => '1.2.3.4', 'ttl' => 1, 'proxied' => true],
                ['id' => 'r2', 'type' => 'MX', 'name' => 'send.droids.dev', 'content' => 'feedback-smtp.us-east-1.amazonses.com', 'ttl' => 1, 'priority' => 10],
            ]]),
            self::API.'/zones/z1/dns_records' => Http::response(['result' => ['id' => 'r3']]),
            self::API.'/zones/z1/dns_records/r1' => Http::response(['result' => ['id' => 'r1']]),
        ]);
    }

    public function test_a_cloudflare_token_is_checked_before_it_is_saved(): void
    {
        Http::fake([self::API.'/user/tokens/verify' => Http::sequence()
            ->push(['result' => ['status' => 'active']])
            ->push(['success' => false], 401)]);

        $this->post('/settings/services', ['service' => 'cloudflare', 'token' => ' cf-token '])->assertRedirect('/settings/services');
        $this->assertSame('cf-token', ServiceKey::for('cloudflare')?->token);

        $this->post('/settings/services', ['service' => 'cloudflare', 'token' => 'bad'])->assertSessionHasErrors('token');
        $this->assertSame('cf-token', ServiceKey::for('cloudflare')?->token);

        $this->delete('/settings/services/cloudflare')->assertRedirect('/settings/services');
        $this->assertNull(ServiceKey::for('cloudflare'));
    }

    public function test_the_dns_tab_finds_the_zone_of_the_project_domain(): void
    {
        $this->fakeZone();

        $this->putJson('/factory/projects/droids/domain', ['domain' => ' Mail.Droids.dev. '])->assertOk()
            ->assertJsonPath('domain', 'mail.droids.dev')
            ->assertJsonPath('zone', ['id' => 'z1', 'name' => 'droids.dev'])
            ->assertJsonPath('dns.1.priority', 10)
            ->assertJsonPath('error', null);
        $this->putJson('/factory/projects/droids/domain', ['domain' => 'not a domain'])->assertJsonValidationErrors('domain');
    }

    public function test_records_are_added_with_absolute_names_and_removed(): void
    {
        $this->fakeZone();
        Project::where('slug', 'droids')->update(['domain' => 'droids.dev']);

        $this->postJson('/factory/projects/droids/dns', ['type' => 'TXT', 'name' => 'send', 'content' => 'v=spf1 include:amazonses.com ~all'])->assertCreated();
        $this->postJson('/factory/projects/droids/dns', ['type' => 'CNAME', 'name' => '@', 'content' => 'droids.laravel.cloud', 'proxied' => true])->assertCreated();
        $this->postJson('/factory/projects/droids/dns', ['type' => 'MX', 'name' => 'send', 'content' => 'x'])->assertJsonValidationErrors('priority');
        $this->deleteJson('/factory/projects/droids/dns/r1')->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'send.droids.dev' && $r['type'] === 'TXT' && $r['proxied'] === false);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'droids.dev' && $r['proxied'] === true);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/zones/z1/dns_records/r1');
    }

    public function test_without_a_token_the_tab_says_so(): void
    {
        Project::where('slug', 'droids')->update(['domain' => 'droids.dev']);

        $this->getJson('/factory/projects/droids/domain')->assertOk()->assertJsonPath('cloudflare', false);
        $this->postJson('/factory/projects/droids/dns', ['type' => 'A', 'name' => '@', 'content' => '1.2.3.4'])
            ->assertUnprocessable()->assertJsonPath('message', 'Add a Cloudflare API token in Settings > DNS first.');
    }
}
