<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Stache;
use Tests\TestCase;

class FleetKitTest extends TestCase
{
    public function test_homepage_renders_fleet_content_and_native_navigation(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertOk()->assertSeeText('Delivering tomorrow.');
        $response->assertSee('href="/services"', false);
        $response->assertSee('<title>Home — Fleet</title>', false);
        $response->assertSee('mailto:hello@example.com', false);
        $response->assertDontSee('webflow.js');
    }

    public function test_service_detail_renders_from_a_collection_entry(): void
    {
        $this->withoutVite();

        $response = $this->get('/services/ocean-freight');

        $response->assertOk()->assertSeeText('Ocean freight');
        $response->assertSeeText('Full-container and shared-container options');
    }

    public function test_quote_page_uses_a_real_form_with_validation_fields(): void
    {
        $this->withoutVite();

        $response = $this->get('/contact');

        $response->assertOk()->assertSee('method="POST"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="_token"', false);
    }

    #[DataProvider('pageRoutes')]
    public function test_each_content_route_renders_with_a_title(string $route, string $title): void
    {
        $this->withoutVite();

        $this->get($route)->assertOk()->assertSeeText($title);
    }

    /** @return array<string, array{string, string}> */
    public static function pageRoutes(): array
    {
        return [
            'about' => ['/about', 'Good people.'],
            'platform' => ['/platform', 'Less complexity.'],
            'services' => ['/services', 'Services'],
            'newsroom' => ['/newsroom', 'Newsroom'],
            'careers' => ['/careers', 'Careers'],
            'air freight' => ['/services/air-freight', 'Air freight'],
            'road freight' => ['/services/road-freight', 'Road freight'],
            'warehousing' => ['/services/warehousing', 'Warehousing'],
            'customs' => ['/services/customs-support', 'Customs support'],
            'supply chain' => ['/services/supply-chain', 'Supply chain'],
            'news detail' => ['/newsroom/better-shipment-briefs', 'A better brief makes a better shipment'],
            'freight guide' => ['/newsroom/choosing-a-freight-mode', 'Choosing the right mode'],
            'planning guide' => ['/newsroom/planning-a-resilient-supply-chain', 'Planning for a more resilient supply chain'],
            'operations job' => ['/careers/operations-coordinator', 'Operations coordinator'],
            'success job' => ['/careers/customer-success-specialist', 'Customer success specialist'],
            'analyst job' => ['/careers/logistics-analyst', 'Logistics analyst'],
        ];
    }

    public function test_invalid_quote_is_rejected_by_server_validation(): void
    {
        $this->postJson('/!/forms/quote', [
            'email' => 'invalid-email',
            'message' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'message']);
    }

    public function test_valid_quote_is_stored_and_honeypot_submission_is_discarded(): void
    {
        Mail::fake();
        $submissionDirectory = sys_get_temp_dir().'/fleet-forms-'.bin2hex(random_bytes(8));
        Stache::store('form-submissions')->directory($submissionDirectory.'/');

        try {
            $details = [
                'name' => 'Test Customer',
                'email' => 'customer@example.com',
                'message' => 'Please quote for a shipment from London to Paris.',
            ];

            $this->postJson('/!/forms/quote', $details)
                ->assertOk()->assertJson(['success' => true, 'submission_created' => true]);

            $submissions = FormSubmission::whereForm('quote');
            $this->assertCount(1, $submissions);
            $this->assertSame($details['email'], $submissions->first()->get('email'));
            $this->assertFileExists($submissions->first()->path());

            $this->postJson('/!/forms/quote', [...$details, 'website' => 'spam.example.com'])
                ->assertOk()->assertJson(['success' => true, 'submission_created' => false]);

            $this->assertCount(1, FormSubmission::whereForm('quote'));
            Mail::assertNothingSent();
        } finally {
            File::deleteDirectory($submissionDirectory);
        }
    }

    public function test_missing_page_shows_the_custom_not_found_page(): void
    {
        $this->withoutVite();

        $this->get('/missing-fleet-page')->assertNotFound()->assertSeeText('404');
    }
}
