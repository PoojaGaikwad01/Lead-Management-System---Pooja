<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Google\GoogleLeadProcessor;
use App\Services\Google\SampleGoogleLead;
use App\Services\Simulation\LeadSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SimulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        config(['leads.agency_numbers' => [], 'leads.google.lead_key' => 'test-google-key']);
        $this->admin = User::factory()->admin()->create();
    }

    private function simulate(string $type): Lead
    {
        $before = Lead::max('id') ?? 0;
        $response = $this->actingAs($this->admin)->post("/simulate/{$type}");

        $lead = Lead::where('id', '>', $before)->orderBy('id')->firstOrFail();
        $response->assertRedirect(route('inbox.show', $lead))
            ->assertSessionHas('status', 'Simulated '.LeadSimulator::label($type).": {$lead->display_name}.");

        return $lead;
    }

    public function test_facebook_and_instagram_lead_forms_go_through_the_graph_lookup(): void
    {
        $fb = $this->simulate('fb_lead_ad');
        $this->assertSame(LeadSource::FbLeadAd, $fb->source);
        $this->assertStringStartsWith('sim-', $fb->external_id);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $fb->name);
        $this->assertMatchesRegularExpression('/^\+91[6-9]\d{9}$/', $fb->phone);
        $this->assertStringEndsWith('@gmail.com', $fb->email);
        $this->assertNotNull($fb->city);
        $this->assertStringEndsWith('– Leads', $fb->campaign_name);
        $this->assertNotNull($fb->form_name);
        $this->assertSame(LeadStatus::New, $fb->status);
        $this->assertSame(0, $fb->messages()->count()); // lead ads have no chat thread
        $this->assertContains('which_service_are_you_interested_in?', array_column($fb->raw_payload['field_data'], 'name'));

        $ig = $this->simulate('ig_lead_ad');
        $this->assertSame(LeadSource::IgLeadAd, $ig->source);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $ig->name); // first_name + last_name
    }

    public function test_messenger_instagram_and_whatsapp_chats_open_a_thread(): void
    {
        foreach (['messenger' => [LeadSource::Messenger, 'messenger'], 'ig_dm' => [LeadSource::IgDm, 'instagram'], 'whatsapp' => [LeadSource::WhatsappCustomer, 'whatsapp']] as $type => [$source, $channel]) {
            $lead = $this->simulate($type);
            $this->assertSame($source, $lead->source);
            $this->assertNotNull($lead->name, "{$type}: name from the profile lookup / WhatsApp contact");

            $message = $lead->messages()->sole();
            $this->assertSame($channel, $message->channel);
            $this->assertTrue($message->isInbound());
            $this->assertStringStartsWith('sim-', $message->external_message_id);
            $this->assertNotEmpty($message->body);
            $this->assertTrue($lead->replyWindow()['open'], "{$type}: a new chat opens the 24h reply window");
        }

        $this->assertMatchesRegularExpression('/^\+91[6-9]\d{9}$/', Lead::where('source', LeadSource::WhatsappCustomer)->sole()->phone);
        $this->assertStringStartsWith('sim-', Lead::where('source', LeadSource::Messenger)->sole()->contact_id);
    }

    public function test_an_agency_text_becomes_one_lead_per_person(): void
    {
        $first = $this->simulate('agency_text');
        $leads = Lead::where('source', LeadSource::WhatsappAgency)->orderBy('id')->get();

        $this->assertCount(2, $leads);
        $this->assertTrue($leads->first()->is($first));
        $this->assertContains($first->agency_name, ['Digital Reach Agency', 'LeadBoost Media']);
        foreach ($leads as $lead) {
            $this->assertSame(LeadStatus::New, $lead->status);
            $this->assertMatchesRegularExpression('/^\+91[6-9]\d{9}$/', $lead->phone);
            $this->assertSame('agency', $lead->messages()->sole()->channel);
        }
    }

    public function test_an_agency_excel_file_is_read_row_by_row_and_kept_as_an_attachment(): void
    {
        config(['leads.agency_numbers' => ['9876500000']]); // a configured agency number is used as the sender

        $first = $this->simulate('agency_excel');
        $leads = Lead::where('source', LeadSource::WhatsappAgency)->get();

        $this->assertGreaterThanOrEqual(2, $leads->count());
        $this->assertLessThanOrEqual(4, $leads->count());
        $this->assertTrue($leads->every(fn (Lead $l) => preg_match('/^\+91[6-9]\d{9}$/', $l->phone) && $l->status === LeadStatus::New));

        $path = $first->messages()->sole()->attachment_url;
        $this->assertStringStartsWith('media/whatsapp/sim_media_', $path);
        $this->assertStringStartsWith('PK', Storage::get($path)); // a real .xlsx (zip) file
    }

    public function test_every_click_is_a_new_lead(): void
    {
        $this->simulate('fb_lead_ad');
        $this->simulate('fb_lead_ad');
        $this->simulate('whatsapp');

        $this->assertSame(3, Lead::count());
    }

    public function test_clear_removes_simulated_and_sample_google_leads_only(): void
    {
        $real = Lead::factory()->create(['name' => 'Real Customer']);
        $this->simulate('messenger');
        $this->simulate('agency_text');
        $this->simulate('agency_excel');
        app(GoogleLeadProcessor::class)->process(SampleGoogleLead::payload());
        $this->assertGreaterThanOrEqual(7, Lead::count());
        $this->assertNotEmpty(Storage::directories('media/whatsapp'));

        $removed = Lead::count() - 1;
        $this->actingAs($this->admin)->delete('/simulate')
            ->assertRedirect('/inbox')->assertSessionHas('status', "Removed {$removed} simulated leads.");

        $this->assertTrue(Lead::sole()->is($real));
        $this->assertEmpty(Storage::directories('media/whatsapp'));
    }

    public function test_admins_see_the_menu_and_managers_cannot_use_it(): void
    {
        $this->actingAs($this->admin)->get('/inbox')->assertOk()
            ->assertSee('Simulate a lead')->assertSee('Agency Excel file')->assertSee('send-sample-google');

        $manager = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($manager)->get('/inbox')->assertDontSee('Simulate a lead');
        $this->actingAs($manager)->post('/simulate/whatsapp')->assertForbidden();
        $this->actingAs($manager)->delete('/simulate')->assertForbidden();
        $this->assertSame(0, Lead::count());
    }

    public function test_live_mode_has_no_simulation(): void
    {
        config(['leads.mode' => 'live']);

        $this->actingAs($this->admin)->get('/inbox')->assertDontSee('Simulate a lead');
        $this->actingAs($this->admin)->post('/simulate/whatsapp')->assertNotFound();
        $this->actingAs($this->admin)->delete('/simulate')->assertNotFound();
        $this->actingAs($this->admin)->post('/simulate/unknown')->assertNotFound();
        $this->assertSame(0, Lead::count());
    }
}
