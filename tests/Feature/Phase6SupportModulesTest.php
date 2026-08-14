<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Center;
use App\Models\FamilyTimeSchedule;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\StickyNote;
use App\Models\SupportRequest;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6SupportModulesTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrg(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'North', 'code' => 'NZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Gandhinagar', 'code' => 'GND', 'status' => 'active']);
        $other = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Patan', 'code' => 'PTN', 'status' => 'active']);
        return [$zone, $center, $other];
    }

    private function user(string $roleSlug, string $email, Zone $zone, ?Center $center = null): User
    {
        $user = User::query()->create(['name' => $roleSlug, 'email' => $email, 'password' => 'StrongPassword123!', 'status' => 'active']);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['zone_id' => $center?->zone_id ?? $zone->id, 'center_id' => $center?->id, 'is_primary' => true]);
        return $user->fresh('roles.permissions');
    }

    public function test_center_scoped_user_sees_global_and_own_center_announcements_only(): void
    {
        [$zone, $center, $other] = $this->setupOrg();
        $user = $this->user('karyakar', 'field@example.test', $zone, $center);
        Announcement::query()->create(['title' => 'Global', 'body' => 'All', 'audience' => 'all', 'status' => 'published', 'published_at' => now()]);
        Announcement::query()->create(['center_id' => $center->id, 'title' => 'Own', 'body' => 'Own', 'audience' => 'all', 'status' => 'published', 'published_at' => now()]);
        Announcement::query()->create(['center_id' => $other->id, 'title' => 'Other', 'body' => 'Other', 'audience' => 'all', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($user)->get('/support/announcements')
            ->assertOk()->assertInertia(fn ($page) => $page->has('announcements', 2));
    }

    public function test_family_time_completion_is_idempotent_per_user_schedule_and_day(): void
    {
        [$zone, $center] = $this->setupOrg();
        $user = $this->user('karyakar', 'familytime@example.test', $zone, $center);
        $schedule = FamilyTimeSchedule::query()->create(['center_id' => $center->id, 'title' => 'Evening Family Time', 'audience' => 'all', 'starts_at' => now(), 'status' => 'active']);
        $payload = ['completed_on' => now()->toDateString(), 'note' => 'Done'];

        $this->actingAs($user)->post("/support/family-time/schedules/{$schedule->id}/complete", $payload)->assertRedirect();
        $this->actingAs($user)->post("/support/family-time/schedules/{$schedule->id}/complete", $payload)->assertRedirect();
        $this->assertDatabaseCount('family_time_completions', 1);
    }

    public function test_shared_content_management_is_restricted_but_view_is_available(): void
    {
        [$zone, $center] = $this->setupOrg();
        $user = $this->user('karyakar', 'content@example.test', $zone, $center);
        $this->actingAs($user)->get('/support/content')->assertOk();
        $this->actingAs($user)->post('/support/content', [
            'content_type' => 'quote', 'title' => 'Not allowed', 'audience' => 'all', 'status' => 'published',
        ])->assertForbidden();
    }

    public function test_inventory_prevents_negative_stock_and_records_audited_transactions(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'inventory@example.test', $zone, $center);
        $this->actingAs($admin)->post('/support/inventory', ['center_id' => $center->id, 'sku' => 'BOOK-1', 'name' => 'Happy Family Book', 'unit' => 'pcs', 'minimum_stock' => 5, 'status' => 'active'])->assertRedirect();
        $item = InventoryItem::query()->firstOrFail();
        $this->actingAs($admin)->post("/support/inventory/{$item->id}/transactions", ['transaction_type' => 'inward', 'quantity' => 10])->assertRedirect();
        $this->actingAs($admin)->post("/support/inventory/{$item->id}/transactions", ['transaction_type' => 'outward', 'quantity' => 12])->assertSessionHasErrors('quantity');
        $this->assertSame(10, $item->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_transactions', ['inventory_item_id' => $item->id, 'transaction_type' => 'inward', 'stock_after' => 10]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'inventory', 'action' => 'inward']);
    }

    public function test_sticky_notes_are_private_to_the_owner(): void
    {
        [$zone, $center] = $this->setupOrg();
        $one = $this->user('karyakar', 'one@example.test', $zone, $center);
        $two = $this->user('karyakar', 'two@example.test', $zone, $center);
        $note = StickyNote::query()->create(['user_id' => $one->id, 'title' => 'Private', 'body' => 'Only owner', 'status' => 'open']);
        $this->actingAs($two)->put("/support/sticky-notes/{$note->id}", ['title' => 'Changed', 'body' => 'No', 'status' => 'done'])->assertForbidden();
        $this->assertSame('Private', $note->fresh()->title);
    }

    public function test_support_request_and_testimonial_review_workflows_are_available(): void
    {
        [$zone, $center] = $this->setupOrg();
        $field = $this->user('karyakar', 'help@example.test', $zone, $center);
        $admin = $this->user('super_admin', 'super@example.test', $zone);
        $this->actingAs($field)->post('/support/contact', ['subject' => 'Need help', 'category' => 'technical', 'message' => 'Portal question', 'priority' => 'normal'])->assertRedirect();
        $ticket = SupportRequest::query()->firstOrFail();
        $this->actingAs($admin)->put("/support/contact/{$ticket->id}", ['status' => 'resolved', 'response_note' => 'Resolved'])->assertRedirect();
        $this->assertNotNull($ticket->fresh()->resolved_at);

        $this->actingAs($field)->post('/support/testimonials', ['display_name' => 'Field User', 'message' => 'Useful portal', 'rating' => 5])->assertRedirect();
        $testimonial = Testimonial::query()->firstOrFail();
        $this->actingAs($admin)->post("/support/testimonials/{$testimonial->id}/review", ['status' => 'published', 'review_note' => 'Approved'])->assertRedirect();
        $this->assertSame('published', $testimonial->fresh()->status);
    }
}
