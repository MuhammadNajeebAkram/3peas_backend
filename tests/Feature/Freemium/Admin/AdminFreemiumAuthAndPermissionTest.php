<?php

namespace Tests\Feature\Freemium\Admin;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Middleware\CheckPermission;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminFreemiumAuthAndPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        auth()->shouldUse('api');
        app('router')->aliasMiddleware('permission', CheckPermission::class);

        foreach (['freemium.view', 'freemium.create', 'freemium.update', 'freemium.activate', 'freemium.delete', 'freemium.analytics'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        FreemiumCampaign::query()->forceDelete();
        parent::tearDown();
    }

    private function createAdminWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'role_' . uniqid()]);
        $user = User::create([
            'name'     => 'Perm Admin ' . uniqid(),
            'email'    => 'perm_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role_id'  => $role->id,
        ]);

        foreach ($permissions as $p) {
            $perm = Permission::firstOrCreate(['name' => $p]);
            DB::table('role_permissions')->insert([
                'role_id'       => $role->id,
                'permission_id' => $perm->id,
            ]);
        }

        return $user;
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        // Unauthenticated request hits the cookie/JWT auth middleware
        $response = $this->getJson('/api/admin/auth/freemium/campaigns');
        $response->assertUnauthorized();
    }

    public function test_admin_without_permission_is_forbidden_with_403(): void
    {
        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);

        // Admin with no permissions
        $noPermAdmin = $this->createAdminWithPermissions([]);
        $this->actingAs($noPermAdmin, 'api');

        $this->getJson('/api/admin/auth/freemium/campaigns')->assertForbidden();
        $this->getJson('/api/admin/auth/freemium/rules')->assertForbidden();
        $this->getJson('/api/admin/auth/freemium/quotas')->assertForbidden();
        $this->getJson('/api/admin/auth/freemium/grants')->assertForbidden();
        $this->getJson('/api/admin/auth/freemium/audit-logs')->assertForbidden();
        $this->getJson('/api/admin/auth/freemium/analytics/summary')->assertForbidden();

        // Admin with only view permission cannot mutate or activate or delete
        $viewAdmin = $this->createAdminWithPermissions(['freemium.view']);
        $this->actingAs($viewAdmin, 'api');

        $this->getJson('/api/admin/auth/freemium/campaigns')->assertOk();

        // Forbidden on create
        $this->postJson('/api/admin/auth/freemium/campaigns', [
            'name'     => 'Unauthorized Post',
            'start_at' => Carbon::now()->toIso8601String(),
            'end_at'   => Carbon::now()->addDays(7)->toIso8601String(),
        ])->assertForbidden();

        // Forbidden on activate
        $this->patchJson('/api/admin/auth/freemium/campaigns/1/status', [
            'status' => 'active',
        ])->assertForbidden();

        // Forbidden on delete
        $this->deleteJson('/api/admin/auth/freemium/campaigns/1')->assertForbidden();

        // Forbidden on analytics
        $this->getJson('/api/admin/auth/freemium/analytics/summary')->assertForbidden();
    }

    public function test_admin_with_specific_permission_is_allowed(): void
    {
        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);

        // Create permission allows store
        $createAdmin = $this->createAdminWithPermissions(['freemium.create', 'freemium.view']);
        $this->actingAs($createAdmin, 'api');

        $createRes = $this->postJson('/api/admin/auth/freemium/campaigns', [
            'name'     => 'Auth Test Campaign',
            'status'   => 'draft',
            'start_at' => Carbon::now()->toIso8601String(),
            'end_at'   => Carbon::now()->addDays(14)->toIso8601String(),
        ]);
        $createRes->assertCreated();
        $campaignId = $createRes->json('data.id');

        // Analytics permission allows summary
        $analyticsAdmin = $this->createAdminWithPermissions(['freemium.analytics']);
        $this->actingAs($analyticsAdmin, 'api');
        $this->getJson('/api/admin/auth/freemium/analytics/summary')->assertOk();
    }

    public function test_super_admin_bypasses_all_permission_checks(): void
    {
        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);

        $superRole = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin = User::create([
            'name'     => 'Super Test ' . uniqid(),
            'email'    => 'super_test_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role_id'  => $superRole->id,
        ]);

        $this->actingAs($superAdmin, 'api');

        // Access view without explicit freemium.view permission
        $this->getJson('/api/admin/auth/freemium/campaigns')->assertOk();

        // Access create without explicit freemium.create permission
        $res = $this->postJson('/api/admin/auth/freemium/campaigns', [
            'name'     => 'Super Admin Campaign',
            'status'   => 'draft',
            'start_at' => Carbon::now()->toIso8601String(),
            'end_at'   => Carbon::now()->addDays(30)->toIso8601String(),
        ]);
        $res->assertCreated();

        // Access analytics without explicit freemium.analytics permission
        $this->getJson('/api/admin/auth/freemium/analytics/summary')->assertOk();
    }
}
