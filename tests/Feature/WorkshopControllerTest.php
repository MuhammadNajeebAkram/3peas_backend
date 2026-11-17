<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebUser;
use App\Models\Workshop;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WorkshopControllerTest extends TestCase
{
    use DatabaseTransactions;
    /**
     * Test that an unauthenticated user can view the workshops index without HTTP 500 error.
     * Previously failed with: InvalidArgumentException: Auth guard [web_user] is not defined.
     */
    public function test_unauthenticated_user_can_view_workshops_list_without_500_error(): void
    {
        $response = $this->getJson('/web_api/lms/workshops');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'current_page',
            'data',
            'total',
        ]);
    }

    /**
     * Test that an unauthenticated user requesting an invalid slug returns 404 rather than 500.
     */
    public function test_unauthenticated_user_requesting_invalid_slug_returns_404(): void
    {
        $response = $this->getJson('/web_api/lms/workshops/non-existent-workshop-slug-999');

        $response->assertStatus(404);
    }

    /**
     * Test that unauthenticated users are rejected with 401 when attempting to register.
     */
    public function test_unauthenticated_user_cannot_register_for_workshop(): void
    {
        $response = $this->postJson('/web_api/lms/workshops/1/register');

        $response->assertStatus(401);
    }

    /**
     * Test that unauthenticated users are rejected with 401 when attempting to unregister.
     */
    public function test_unauthenticated_user_cannot_unregister_for_workshop(): void
    {
        $response = $this->postJson('/web_api/lms/workshops/1/unregister');

        $response->assertStatus(401);
    }

    /**
     * Test that an authenticated WebUser (via web_api guard) can view workshops without guard errors.
     */
    public function test_authenticated_web_user_can_view_workshops_without_guard_error(): void
    {
        $webUser = WebUser::first();
        if (!$webUser) {
            $webUser = new WebUser();
            $webUser->id = 1;
            $webUser->name = 'Test Student';
            $webUser->role = 'student';
        }

        $response = $this->actingAs($webUser, 'web_api')->getJson('/web_api/lms/workshops');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
        ]);
    }

    /**
     * Test that an authenticated admin User (via api guard) can view workshops without guard errors.
     */
    public function test_authenticated_admin_user_can_view_workshops_without_guard_error(): void
    {
        $adminUser = User::first();
        if (!$adminUser) {
            $adminUser = new User();
            $adminUser->id = 1;
            $adminUser->name = 'Test Admin';
        }

        $response = $this->actingAs($adminUser, 'api')->getJson('/web_api/lms/workshops');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
        ]);
    }

    /**
     * Test that published upcoming workshops appear in the default upcoming index.
     */
    public function test_published_upcoming_workshop_appears_in_index(): void
    {
        $admin = User::first() ?? User::factory()->create();

        $workshop = Workshop::create([
            'title' => 'Upcoming Test Workshop',
            'slug' => 'upcoming-test-workshop-' . uniqid(),
            'short_description' => 'Test upcoming workshop description',
            'workshop_mode' => 'online',
            'start_at' => Carbon::now()->addDays(7),
            'end_at' => Carbon::now()->addDays(7)->addHours(2),
            'is_published' => true,
            'is_registration_open' => true,
            'seat_limit' => 50,
            'registered_count' => 0,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson('/web_api/lms/workshops?type=upcoming');
        $response->assertStatus(200);

        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains($workshop->slug), 'Upcoming published workshop must appear in index');

        $showResponse = $this->getJson('/web_api/lms/workshops/' . $workshop->slug);
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('slug', $workshop->slug);
    }

    /**
     * Test that published past workshops appear under type=past and are excluded from type=upcoming.
     */
    public function test_published_past_workshop_listing_behavior(): void
    {
        $admin = User::first() ?? User::factory()->create();

        $pastWorkshop = Workshop::create([
            'title' => 'Past Test Workshop',
            'slug' => 'past-test-workshop-' . uniqid(),
            'short_description' => 'Test past workshop description',
            'workshop_mode' => 'online',
            'start_at' => Carbon::now()->subDays(7),
            'end_at' => Carbon::now()->subDays(7)->addHours(2),
            'is_published' => true,
            'is_registration_open' => false,
            'seat_limit' => 50,
            'registered_count' => 20,
            'created_by' => $admin->id,
        ]);

        // Default upcoming should NOT contain past workshop
        $upcomingResponse = $this->getJson('/web_api/lms/workshops?type=upcoming');
        $upcomingResponse->assertStatus(200);
        $upcomingSlugs = collect($upcomingResponse->json('data'))->pluck('slug');
        $this->assertFalse($upcomingSlugs->contains($pastWorkshop->slug), 'Past workshop must be excluded from upcoming index');

        // Past filter should contain past workshop
        $pastResponse = $this->getJson('/web_api/lms/workshops?type=past');
        $pastResponse->assertStatus(200);
        $pastSlugs = collect($pastResponse->json('data'))->pluck('slug');
        $this->assertTrue($pastSlugs->contains($pastWorkshop->slug), 'Past workshop must appear under type=past');
    }

    /**
     * Test that unpublished workshops do not appear in index and return 404 for show.
     */
    public function test_unpublished_workshop_does_not_appear(): void
    {
        $admin = User::first() ?? User::factory()->create();

        $unpublishedWorkshop = Workshop::create([
            'title' => 'Draft Secret Workshop',
            'slug' => 'draft-secret-workshop-' . uniqid(),
            'short_description' => 'Unpublished workshop',
            'workshop_mode' => 'online',
            'start_at' => Carbon::now()->addDays(10),
            'end_at' => Carbon::now()->addDays(10)->addHours(2),
            'is_published' => false,
            'is_registration_open' => true,
            'created_by' => $admin->id,
        ]);

        // Must not appear in index
        $response = $this->getJson('/web_api/lms/workshops?type=all');
        $response->assertStatus(200);
        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertFalse($slugs->contains($unpublishedWorkshop->slug), 'Unpublished workshop must never appear in index');

        // Must return 404 for show
        $showResponse = $this->getJson('/web_api/lms/workshops/' . $unpublishedWorkshop->slug);
        $showResponse->assertStatus(404);
    }

    /**
     * Test that closed registration does not hide a published workshop from listing.
     */
    public function test_closed_registration_does_not_hide_published_workshop(): void
    {
        $admin = User::first() ?? User::factory()->create();

        $closedWorkshop = Workshop::create([
            'title' => 'Full Capacity Workshop',
            'slug' => 'full-capacity-workshop-' . uniqid(),
            'short_description' => 'Closed registration workshop',
            'workshop_mode' => 'physical',
            'start_at' => Carbon::now()->addDays(5),
            'end_at' => Carbon::now()->addDays(5)->addHours(2),
            'is_published' => true,
            'is_registration_open' => false, // Registration closed
            'registration_deadline' => Carbon::now()->subDay(), // Deadline passed
            'seat_limit' => 20,
            'registered_count' => 20, // Full
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson('/web_api/lms/workshops?type=upcoming');
        $response->assertStatus(200);
        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains($closedWorkshop->slug), 'Closed registration workshop must remain visible in listing');

        $showResponse = $this->getJson('/web_api/lms/workshops/' . $closedWorkshop->slug);
        $showResponse->assertStatus(200);
        $this->assertFalse($showResponse->json('can_register'), 'can_register must be false when registration is closed/full');
    }
}
