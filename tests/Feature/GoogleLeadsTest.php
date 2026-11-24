<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GoogleLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leads.google.lead_key' => 'test-google-key', 'leads.google.demo_tools' => true, 'leads.dedupe_days' => 7]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(json_decode(file_get_contents(base_path('tests/fixtures/google/lead_form.json')), true), $overrides);
    }

    /** POST the raw body exactly as Google does: no session, no CSRF token. */
    private function postWebhook(array|string $payload): TestResponse
    {
        return $this->call('POST', '/webhooks/google-leads', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], is_string($payload) ? $payload : json_encode($payload));
    }

    public function test_a_google_lead_form_becomes_a_new_lead_and_google_gets_200(): void
    {
        $response = $this->postWebhook($this->payload())->assertOk();
        $this->assertSame('{}', $response->getContent());

        $lead = Lead::sole();
        $this->assertSame(LeadSource::GoogleLeadAd, $lead->source);
        $this->assertSame('TeSter-123-ABCDEFGHIJKLMNOPQRSTUVWXYZ-abcdefghijklmnopqrstuvwxyz-0123456789', $lead->external_id);
        $this->assertSame('Priya Sharma', $lead->name);
        $this->assertSame('+919876543210', $lead->phone);
        $this->assertSame('priya.sharma@gmail.com', $lead->email);
        $this->assertSame('Nashik', $lead->city);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame('Google campaign 20000000000', $lead->campaign_name);
        $this->assertSame('Google form 40000000000', $lead->form_name);
        $this->assertSame('Ad group 20000000001', $lead->ad_name);
        $this->assertArrayNotHasKey('google_key', $lead->raw_payload);
        $this->assertTrue($lead->source->isLeadAd());
        $this->assertSame(0, $lead->messages()->count()); // lead ads have no chat thread
        $this->assertFalse($lead->isTest());
    }

    public function test_a_wrong_or_missing_key_is_rejected_with_401(): void
    {
        $this->postWebhook($this->payload(['google_key' => 'wrong']))->assertStatus(401)->assertJson(['message' => 'Invalid google_key.']);

        $payload = $this->payload();
        unset($payload['google_key']);
        $this->postWebhook($payload)->assertStatus(401);

        $this->assertSame(0, Lead::count());
    }

    public function test_browsers_on_other_sites_may_post_to_the_webhook_but_not_to_the_app(): void
    {
        // Preflight a browser sends before a cross-site JSON POST; without these headers it reports "Failed to fetch".
        $preflight = ['HTTP_ORIGIN' => 'https://ads.google.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type'];

        $this->call('OPTIONS', '/webhooks/google-leads', [], [], [], $preflight)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods');

        $this->call('POST', '/webhooks/google-leads', [], [], [], [
            'HTTP_ORIGIN' => 'https://ads.google.com', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($this->payload()))->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertSame(1, Lead::count());

        // Only that route: the session-based app and the Meta webhook stay same-origin.
        $this->call('OPTIONS', '/webhooks/meta', [], [], [], $preflight)->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->call('OPTIONS', '/google/sample-lead', [], [], [], $preflight)->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_without_a_configured_key_live_mode_rejects_and_mock_mode_accepts(): void
    {
        config(['leads.google.lead_key' => null, 'leads.mode' => 'live']);
        $this->postWebhook($this->payload(['google_key' => '']))->assertStatus(401);

        config(['leads.mode' => 'mock']);
        $this->postWebhook($this->payload(['google_key' => '']))->assertOk();
        $this->assertSame(1, Lead::count());
    }

    public function test_the_same_lead_id_delivered_twice_is_stored_once(): void
    {
        $this->postWebhook($this->payload())->assertOk();
        $this->postWebhook($this->payload())->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame(0, Lead::sole()->messages()->count());
    }

    public function test_a_known_phone_adds_the_form_to_the_existing_lead_instead_of_a_new_one(): void
    {
        $existing = Lead::factory()->create(['phone' => '9876543210', 'status' => LeadStatus::Closed, 'last_activity_at' => now()->subDays(2)]);

        $this->postWebhook($this->payload())->assertOk();
        $this->postWebhook($this->payload())->assertOk(); // redelivery

        $this->assertSame(1, Lead::count());
        $message = $existing->messages()->sole();
        $this->assertSame('lead_ad', $message->channel);
        $this->assertSame('leadgen:'.$this->payload()['lead_id'], $message->external_message_id);
        $this->assertStringContainsString('Google Ads Lead Form', $message->body);
        $this->assertStringContainsString('Which service are you interested in?: Website Development', $message->body);
        $this->assertSame(LeadStatus::New, $existing->fresh()->status); // closed lead reopened
    }

    public function test_first_and_last_name_unknown_fields_and_large_ids(): void
    {
        $body = '{"lead_id":"big-1","google_key":"test-google-key","some_future_field":{"x":1},'
            .'"user_column_data":[{"column_id":"FIRST_NAME","string_value":"Rohan"},{"column_id":"LAST_NAME","string_value":"Patil"},'
            .'{"column_id":"PHONE_NUMBER","string_value":"98220 12345"}],'
            .'"campaign_id":12345678901234567890,"form_id":9007199254740993}';

        $this->postWebhook($body)->assertOk();

        $lead = Lead::sole();
        $this->assertSame('Rohan Patil', $lead->name);
        $this->assertSame('+919822012345', $lead->phone);
        $this->assertSame('Google campaign 12345678901234567890', $lead->campaign_name);
        $this->assertSame('Google form 9007199254740993', $lead->form_name);
        $this->assertSame('12345678901234567890', $lead->raw_payload['campaign_id']);
    }

    public function test_bad_requests_get_400(): void
    {
        $this->postWebhook('not json')->assertStatus(400);
        $this->postWebhook('[1,2]')->assertStatus(400);

        $payload = $this->payload();
        unset($payload['lead_id']);
        $this->postWebhook($payload)->assertStatus(400)->assertJson(['message' => 'lead_id is required.']);
    }

    public function test_inbox_shows_the_google_lead_with_form_answers_and_test_badge(): void
    {
        $admin = User::factory()->admin()->create();
        $this->postWebhook($this->payload(['is_test' => true]))->assertOk();
        $lead = Lead::sole();
        $this->assertTrue($lead->isTest());

        $this->actingAs($admin)->get('/inbox')->assertOk()->assertSee('Priya Sharma')->assertSee('Google')->assertSee('Test');
        $this->actingAs($admin)->get("/inbox/{$lead->id}")
            ->assertOk()
            ->assertSee('Google Ads Lead Form')
            ->assertSee('Which service are you interested in?')
            ->assertSee('Website Development')
            ->assertSee('Lead form submitted')
            ->assertDontSee('test-google-key');

        $this->actingAs($admin)->get('/reports')->assertOk()->assertSee('Google Ads Lead Form');
        $this->actingAs($admin)->get('/inbox?source=google_lead_ad')->assertOk()->assertSee('Priya Sharma');
    }

    public function test_inbox_updates_returns_new_leads_the_user_can_see(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $old = Lead::factory()->create();

        $this->actingAs($admin)->getJson("/inbox/updates?since={$old->id}")
            ->assertOk()->assertExactJson(['latest_id' => $old->id, 'new' => []]);

        $this->postWebhook($this->payload())->assertOk();
        $new = Lead::latest('id')->first();

        $this->actingAs($admin)->getJson("/inbox/updates?since={$old->id}")
            ->assertOk()
            ->assertJsonPath('latest_id', $new->id)
            ->assertJsonPath('new.0.name', 'Priya Sharma')
            ->assertJsonPath('new.0.source', 'Google Ads Lead Form');

        // Filters apply, and an unassigned lead is invisible to a branch manager.
        $this->actingAs($admin)->getJson("/inbox/updates?since={$old->id}&source=messenger")->assertJsonCount(0, 'new');
        $this->actingAs($manager)->getJson("/inbox/updates?since={$old->id}")->assertJsonCount(0, 'new');
    }

    public function test_sample_button_flow_creates_a_lead_through_the_real_webhook(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/inbox')->assertSee('send-sample-google');

        $prepared = $this->actingAs($admin)->postJson('/google/sample-lead')->assertOk();
        $this->assertSame('/webhooks/google-leads', $prepared->json('webhook_url'));
        $body = json_decode($prepared->json('body'), true);
        $this->assertSame('test-google-key', $body['google_key']);
        $this->assertSame(['column_name', 'string_value', 'column_id'], array_keys($body['user_column_data'][0]));

        // What the browser does next.
        $this->postWebhook($prepared->json('body'))->assertOk();
        $lead = Lead::sole();
        $this->assertStringStartsWith('sample-', $lead->external_id);
        $this->assertStringStartsWith('+91', $lead->phone);
    }

    public function test_demo_tools_are_admin_only_and_can_be_switched_off(): void
    {
        $manager = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($manager)->postJson('/google/sample-lead')->assertForbidden();
        $this->actingAs($manager)->get('/inbox')->assertDontSee('send-sample-google');

        config(['leads.google.demo_tools' => false]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/google/sample-lead')->assertNotFound();
        $this->actingAs($admin)->delete('/google/sample-leads')->assertNotFound();
        $this->actingAs($admin)->get('/inbox')->assertDontSee('send-sample-google');
    }

    public function test_clear_samples_removes_only_sample_leads(): void
    {
        $admin = User::factory()->admin()->create();
        $this->postWebhook($this->payload())->assertOk(); // real
        $this->postWebhook($this->actingAs($admin)->postJson('/google/sample-lead')->json('body'))->assertOk();
        $this->postWebhook($this->actingAs($admin)->postJson('/google/sample-lead')->json('body'))->assertOk();
        $this->assertSame(3, Lead::count());

        $this->actingAs($admin)->delete('/google/sample-leads')->assertRedirect('/inbox')->assertSessionHas('status', 'Removed 2 sample Google leads.');
        $this->assertSame(['Priya Sharma'], Lead::pluck('name')->all());

        $this->postWebhook($this->actingAs($admin)->postJson('/google/sample-lead')->json('body'))->assertOk();
        $this->artisan('google:clear-samples --force')->assertSuccessful();
        $this->assertSame(1, Lead::count());
    }

    public function test_send_sample_command_posts_googles_format_to_the_webhook(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        config(['app.url' => 'http://localhost']);

        $this->artisan('google:send-sample --count=2 --test')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'http://localhost/webhooks/google-leads'
                && $body['google_key'] === 'test-google-key'
                && $body['is_test'] === true
                && is_int($body['campaign_id'])
                && collect($body['user_column_data'])->pluck('column_id')->contains('PHONE_NUMBER');
        });

        $this->artisan('google:send-sample --key=wrong')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $request) => json_decode($request->body(), true)['google_key'] === 'wrong');
    }
}
