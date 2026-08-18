<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Center;
use App\Models\Family;
use App\Models\GroupFamilyAssignment;
use App\Models\SankalpGroup;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_applied_to_web_responses(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_liveness_endpoint_does_not_depend_on_database_or_cache(): void
    {
        $this->getJson('/health/live')->assertOk()->assertJson(['status' => 'alive']);
    }

    public function test_readiness_endpoint_checks_database_and_cache(): void
    {
        $this->getJson('/health/ready')->assertOk()->assertJson(['status' => 'ready', 'checks' => ['database' => true, 'cache' => true, 'redis' => true, 'schema' => true], 'missing_tables' => [], 'missing_columns' => []]);
    }

    public function test_health_routes_bypass_redis_backed_session_and_inertia_middleware(): void
    {
        foreach (['health.live', 'health.ready'] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertNotContains(StartSession::class, $middleware);
            $this->assertNotContains(HandleInertiaRequests::class, $middleware);
        }
    }

    public function test_database_constraint_is_last_line_of_defense_against_duplicate_active_family_assignment(): void
    {
        $zone = Zone::query()->create(['name' => 'Zone', 'code' => 'Z', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Center', 'code' => 'CTR', 'status' => 'active']);
        $family = Family::query()->create(['center_id' => $center->id, 'manual_reference' => 'HF-CTR-000001', 'source' => 'manual', 'head_name' => 'Family', 'status' => 'active']);
        $one = SankalpGroup::query()->create(['center_id' => $center->id, 'group_code' => 'CTR-001', 'group_type' => 'two_male', 'status' => 'draft']);
        $two = SankalpGroup::query()->create(['center_id' => $center->id, 'group_code' => 'CTR-002', 'group_type' => 'two_male', 'status' => 'draft']);
        GroupFamilyAssignment::query()->create(['group_id' => $one->id, 'family_id' => $family->id, 'slot_number' => 1, 'assignment_type' => 'fixed', 'assignment_source' => 'admin', 'status' => 'active', 'assigned_at' => now()]);

        $this->expectException(QueryException::class);
        GroupFamilyAssignment::query()->create(['group_id' => $two->id, 'family_id' => $family->id, 'slot_number' => 1, 'assignment_type' => 'remaining', 'assignment_source' => 'admin', 'status' => 'active', 'assigned_at' => now()]);
    }
}
