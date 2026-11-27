<?php

namespace Tests\Feature;

use App\Http\Controllers\Factory\CloudDomainController;
use App\Integrations\Cloudflare;
use App\Integrations\Dns;
use App\Integrations\Resend;
use App\Models\CloudConnection;
use App\Models\Project;
use App\Models\ServiceKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Laravel Cloud variables and domains, and the Email tab, with their DNS put into Cloudflare. */
class CloudEmailTest extends TestCase
{
    use RefreshDatabase;

    private const CLOUD = 'https://cloud.laravel.com/api';

    private const CF = Cloudflare::URL;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['droids.cloud.url' => self::CLOUD]);
        $this->actingAs(User::factory()->create());
        CloudConnection::create(['token' => 'cloud-token', 'organization_name' => 'Talbot', 'organization_slug' => 'talbot']);
        ServiceKey::create(['service' => 'cloudflare', 'token' => 'cf-token']);
        $this->project = Project::create([
            'slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red', 'domain' => 'droids.dev',
            'cloud_application_id' => 'app-1',
            'cloud_snapshot' => ['envs' => [['id' => 'env-prod', 'name' => 'production', 'email' => null]]],
        ]);
    }

    /** A droids.dev zone that already holds the apex A record. */
    private function zone(): array
    {
        return [
            self::CF.'/zones?name=*droids.dev' => fn (Request $r) => Http::response(['result' => str_ends_with((string) ($r->data()['name'] ?? ''), 'droids.dev') && $r->data()['name'] === 'droids.dev' ? [['id' => 'z1', 'name' => 'droids.dev']] : []]),
            self::CF.'/zones/z1/dns_records?*' => Http::response(['result' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'droids.dev', 'content' => '1.2.3.4'],
                ['id' => 'r2', 'type' => 'TXT', 'name' => 'send.droids.dev', 'content' => '"v=spf1 include:amazonses.com ~all"'],
            ]]),
            self::CF.'/zones/z1/dns_records' => Http::response(['result' => ['id' => 'new']]),
        ];
    }

    public function test_environment_variables_are_listed_set_and_removed(): void
    {
        Http::fake([
            self::CLOUD.'/environments/env-prod' => Http::response(['data' => ['id' => 'env-prod', 'attributes' => ['environment_variables' => [['key' => 'APP_DEBUG', 'value' => 'false']]]]]),
            self::CLOUD.'/environments/env-prod/variables' => Http::response(['data' => []]),
            self::CLOUD.'/environments/env-prod/variables/delete' => Http::response(['data' => []]),
        ]);
        $url = '/factory/projects/droids/cloud/environments/env-prod/variables';

        $this->getJson($url)->assertOk()->assertJsonPath('variables', [['key' => 'APP_DEBUG', 'value' => 'false']]);
        $this->putJson($url, ['variables' => [['key' => 'MAIL_MAILER', 'value' => 'resend']]])->assertOk();
        $this->putJson($url, ['variables' => [['key' => '1BAD', 'value' => 'x']]])->assertJsonValidationErrors('variables.0.key');
        $this->postJson("{$url}/delete", ['keys' => ['APP_DEBUG']])->assertOk();
        $this->getJson('/factory/projects/droids/cloud/environments/env-other/variables')->assertNotFound();

        Http::assertSent(fn (Request $r) => $r->url() === self::CLOUD.'/environments/env-prod/variables' && $r['method'] === 'set' && $r['variables'] === [['key' => 'MAIL_MAILER', 'value' => 'resend']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/variables/delete') && $r['keys'] === ['APP_DEBUG']);
    }

    public function test_record_names_relative_to_the_zone_are_not_doubled_on_a_subdomain(): void
    {
        $sub = 'notifications.omiyagi.com';
        $this->assertSame('send.notifications.omiyagi.com', Dns::absolute('send.notifications', $sub));
        $this->assertSame('resend._domainkey.notifications.omiyagi.com', Dns::absolute('resend._domainkey.notifications', $sub));
        $this->assertSame('notifications.omiyagi.com', Dns::absolute('notifications', $sub));
        $this->assertSame('notifications.omiyagi.com', Dns::absolute('', $sub));
        $this->assertSame('send.notifications.omiyagi.com', Dns::absolute('send.notifications.omiyagi.com', $sub));
        $this->assertSame('send.notifications.omiyagi.com', Dns::absolute('send', $sub), 'a name relative to the subdomain still works');
        $this->assertSame('send.omiyagi.com', Dns::absolute('send', 'omiyagi.com'));
        $this->assertSame('com.omiyagi.com', Dns::absolute('com', 'omiyagi.com'), 'the zone itself never overlaps');
    }

    public function test_a_cloud_domain_asks_for_records_by_purpose(): void
    {
        $records = CloudDomainController::records(['attributes' => [
            'name' => 'droids.dev', 'hostname_status' => 'pending',
            'dns_records' => ['ssl' => [['type' => 'TXT', 'name' => '_acme-challenge', 'value' => 'abc']], 'origin' => '', 'origin_cname' => 'droids.laravel.cloud', 'pre_verification' => 'cf-1', 'dcv' => 'dcv.example'],
        ]]);

        $this->assertSame([
            ['type' => 'TXT', 'name' => '_acme-challenge.droids.dev', 'content' => 'abc', 'priority' => null],
            ['type' => 'CNAME', 'name' => 'droids.dev', 'content' => 'droids.laravel.cloud', 'priority' => null],
            ['type' => 'TXT', 'name' => '_cf-custom-hostname.droids.dev', 'content' => 'cf-1', 'priority' => null],
        ], $records, 'the dcv CNAME is skipped when SSL already has an _acme-challenge record');
    }

    public function test_adding_a_cloud_domain_puts_its_records_in_cloudflare_without_clobbering(): void
    {
        $domain = ['id' => 'dom-1', 'attributes' => ['name' => 'droids.dev', 'hostname_status' => 'pending', 'ssl_status' => 'pending', 'origin_status' => 'pending',
            'dns_records' => ['ssl' => [['type' => 'TXT', 'name' => '_acme-challenge.droids.dev', 'value' => 'abc']], 'origin_cname' => 'droids.laravel.cloud']]];
        Http::fake([
            self::CLOUD.'/environments/env-prod/domains' => fn (Request $r) => $r->method() === 'POST' ? Http::response(['data' => $domain]) : Http::response(['data' => [$domain]]),
            self::CLOUD.'/domains/dom-1' => Http::response(['data' => $domain]),
            ...$this->zone(),
        ]);

        $this->postJson('/factory/projects/droids/cloud/domains', ['environment' => 'env-prod', 'name' => 'Droids.dev'])->assertCreated()
            ->assertJsonPath('result.added', 1)
            ->assertJsonPath('result.conflicts', ['CNAME droids.dev'])
            ->assertJsonPath('domains.0.records.0.state', 'missing')
            ->assertJsonPath('domains.0.records.1.state', 'conflict');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::CF.'/zones/z1/dns_records' && $r['name'] === '_acme-challenge.droids.dev' && $r['type'] === 'TXT');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::CF.'/zones/z1/dns_records' && $r['type'] === 'CNAME');
    }

    public function test_email_setup_sends_through_cloud_receives_through_resend_and_adds_the_dns(): void
    {
        ServiceKey::create(['service' => 'resend', 'token' => 're_key']);
        $resendDomain = ['id' => 'rd-1', 'name' => 'droids.dev', 'status' => 'not_started', 'capabilities' => ['sending' => 'enabled', 'receiving' => 'enabled'], 'records' => [
            ['record' => 'SPF', 'name' => 'send', 'type' => 'TXT', 'value' => 'v=spf1 include:amazonses.com ~all'],
            ['record' => 'Receiving', 'name' => '', 'type' => 'MX', 'value' => 'inbound-smtp.us-east-1.amazonaws.com', 'priority' => 10],
        ]];
        Http::fake([
            self::CLOUD.'/environments/env-prod/resend' => Http::response(['data' => []]),
            self::CLOUD.'/applications/app-1/environments*' => Http::response(['data' => [], 'included' => []]),
            self::CLOUD.'/applications/*' => Http::response(['data' => ['id' => 'app-1', 'attributes' => []], 'included' => []]),
            self::CLOUD.'/resend/domains' => Http::response(['data' => [['id' => 'sd-1', 'attributes' => ['name' => 'droids.dev', 'status' => 'pending', 'records' => [
                ['type' => 'TXT', 'name' => 'resend._domainkey', 'value' => 'p=CLOUD'],
            ]]]]]),
            Resend::URL.'/domains' => fn (Request $r) => $r->method() === 'POST' ? Http::response($resendDomain) : Http::response(['data' => []]),
            Resend::URL.'/domains/rd-1' => Http::response($resendDomain),
            ...$this->zone(),
        ]);

        $res = $this->postJson('/factory/projects/droids/email/setup', ['environment' => 'env-prod', 'from_address' => 'hi@droids.dev', 'from_name' => 'Droids', 'receiving' => true])->assertOk()
            ->assertJsonPath('resend.receiving', true)
            ->assertJsonPath('sending.status', 'pending');

        $this->assertSame('rd-1', $this->project->fresh()->resend_domain_id);
        $this->assertStringContainsString('Added 2 DNS records to Cloudflare, 1 already there.', implode(' ', $res->json('result')));
        Http::assertSent(fn (Request $r) => $r->url() === Resend::URL.'/domains' && $r->method() === 'POST' && $r['capabilities']['receiving'] === 'enabled');
        Http::assertSent(fn (Request $r) => $r->url() === self::CF.'/zones/z1/dns_records' && $r->method() === 'POST' && $r['type'] === 'MX' && $r['name'] === 'droids.dev' && $r['priority'] === 10);
        Http::assertSent(fn (Request $r) => $r->url() === self::CF.'/zones/z1/dns_records' && $r->method() === 'POST' && $r['name'] === 'resend._domainkey.droids.dev');

        $this->postJson('/factory/projects/droids/email/setup', ['environment' => 'env-prod', 'from_address' => 'hi@other.dev', 'from_name' => 'Droids'])->assertJsonValidationErrors('from_address');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/environments/env-prod/resend'));
        $this->assertStringContainsString("Laravel Cloud hasn't verified droids.dev yet (it is pending)", $res->json('warnings.0'));
    }

    public function test_cloud_sends_from_its_verified_sending_domain(): void
    {
        $this->project->update(['domain' => 'notifications.droids.dev']);
        Http::fake([
            self::CLOUD.'/environments/env-prod/resend' => Http::response(['data' => []]),
            self::CLOUD.'/applications/app-1/environments*' => Http::response(['data' => [], 'included' => []]),
            self::CLOUD.'/applications/*' => Http::response(['data' => ['id' => 'app-1', 'attributes' => []], 'included' => []]),
            self::CLOUD.'/resend/domains' => Http::response(['data' => [['id' => 'sd-1', 'attributes' => ['name' => 'droids.dev', 'status' => 'verified', 'records' => []]]]]),
            ...$this->zone(),
        ]);

        $res = $this->postJson('/factory/projects/droids/email/setup', ['environment' => 'env-prod', 'from_address' => 'hi@notifications.droids.dev', 'from_name' => 'Droids'])->assertOk();
        $this->assertStringContainsString("Laravel Cloud's verified sending domain is droids.dev", $res->json('warnings.0'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/environments/env-prod/resend'));

        $this->postJson('/factory/projects/droids/email/setup', ['environment' => 'env-prod', 'from_address' => 'hi@droids.dev', 'from_name' => 'Droids'])->assertOk()->assertJsonPath('warnings', []);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/environments/env-prod/resend') && $r['from_address'] === 'hi@droids.dev');
    }

    public function test_a_resend_key_is_checked_before_it_is_saved(): void
    {
        Http::fake([Resend::URL.'/domains' => Http::sequence()->push(['data' => []])->push(['message' => 'restricted'], 401)]);

        $this->post('/settings/services', ['service' => 'resend', 'token' => 're_full'])->assertRedirect('/settings/services');
        $this->assertSame('re_full', ServiceKey::for('resend')?->token);
        $this->post('/settings/services', ['service' => 'resend', 'token' => 're_send_only'])->assertSessionHasErrors('token');
    }
}
