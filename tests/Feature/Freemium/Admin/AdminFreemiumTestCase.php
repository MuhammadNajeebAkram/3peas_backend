<?php

namespace Tests\Feature\Freemium\Admin;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Middleware\CheckPermission;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumAuditLog;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\Freemium\FreemiumExamQuota;
use App\Models\Freemium\FreemiumQuotaConsumption;
use App\Models\Freemium\FreemiumQuotaConsumptionEnvelope;
use App\Models\Freemium\FreemiumQuotaCounter;
use App\Models\Freemium\FreemiumStudentGrant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class AdminFreemiumTestCase extends TestCase
{
    protected Role $superAdminRole;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->shouldUse('api');
        app('router')->aliasMiddleware('permission', CheckPermission::class);

        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);

        $this->ensurePermissionsExist();

        $this->superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $this->superAdmin = User::firstOrCreate(
            ['email' => 'super_freemium_admin@example.com'],
            ['name' => 'Super Freemium Admin', 'role_id' => $this->superAdminRole->id, 'password' => bcrypt('secret')]
        );
    }

    protected function tearDown(): void
    {
        FreemiumQuotaConsumptionEnvelope::query()->delete();
        FreemiumQuotaConsumption::query()->delete();
        FreemiumQuotaCounter::query()->delete();
        FreemiumStudentGrant::query()->forceDelete();
        FreemiumAccessRule::query()->delete();
        FreemiumExamQuota::query()->forceDelete();
        FreemiumCampaign::query()->forceDelete();
        FreemiumAuditLog::query()->delete();

        parent::tearDown();
    }

    protected function ensurePermissionsExist(): void
    {
        $permissions = [
            'freemium.view',
            'freemium.create',
            'freemium.update',
            'freemium.activate',
            'freemium.delete',
            'freemium.analytics',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    protected function createAdminWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'role_' . uniqid()]);
        $user = User::create([
            'name'     => 'Admin ' . uniqid(),
            'email'    => 'admin_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id'  => $role->id,
        ]);

        foreach ($permissions as $permName) {
            $permission = Permission::firstOrCreate(['name' => $permName]);
            DB::table('role_permissions')->insert([
                'role_id'       => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        return $user;
    }

    protected function createStudent(): WebUser
    {
        return WebUser::create([
            'name'     => 'Student ' . uniqid(),
            'email'    => 'student_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'role'     => 'student',
        ]);
    }

    protected function createAcademicTest(): \App\Models\Test
    {
        return \App\Models\Test::create([
            'title'      => 'Test ' . uniqid(),
            'test_mode'  => 'test',
            'scope_type' => 'chapter',
        ]);
    }
}
