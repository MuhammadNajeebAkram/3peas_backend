<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Middleware\CheckPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSettlement;
use App\Models\TeacherSubscription;
use App\Models\TeacherSubscriptionHistory;
use App\Models\TeacherSubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use App\Models\WebUserProfile;
use App\Models\WebUserRoleChange;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminWebUserRoleChangeTest extends TestCase
{
    private User $superAdmin;
    private User $roleChangeAdmin;
    private User $lowPrivAdmin;
    private WebUser $student;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'role_change_test',
            'database.connections.role_change_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('role_change_test');

        $this->buildIsolatedSchema();

        $this->seed(\Database\Seeders\TeacherSubscriptionPlanSeeder::class);
        $this->seed(\Database\Seeders\LmsPermissionsSeeder::class);
        $this->seed(\Database\Seeders\WebUserRoleChangePermissionSeeder::class);

        $superRole = Role::where('name', 'super_admin')->firstOrFail();
        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@test.local',
            'password' => Hash::make('secret123'),
            'role_id' => $superRole->id,
        ]);

        $roleManager = Role::create(['name' => 'role_manager']);
        $roleManager->permissions()->sync([
            Permission::where('name', 'web-users.change-role')->value('id'),
            Permission::where('name', 'web-users.view')->value('id'),
        ]);
        $this->roleChangeAdmin = User::create([
            'name' => 'Role Manager',
            'email' => 'rolemanager@test.local',
            'password' => Hash::make('secret123'),
            'role_id' => $roleManager->id,
        ]);

        $lowPrivRole = Role::create(['name' => 'content_viewer']);
        $this->lowPrivAdmin = User::create([
            'name' => 'Low Priv Admin',
            'email' => 'lowpriv@test.local',
            'password' => Hash::make('secret123'),
            'role_id' => $lowPrivRole->id,
        ]);

        DB::table('city_tbl')->insert(['id' => 1, 'name' => 'Islamabad']);
        DB::table('institute_tbl')->insert(['id' => 1, 'name' => 'TST Academy']);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => '9th']);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics']);
        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'Federal Board']);
        DB::table('offered_classes')->insert(['id' => 1, 'curriculum_board_id' => 1]);
        DB::table('offered_programs')->insert(['id' => 1, 'title' => '9th Federal']);

        $this->student = WebUser::create([
            'name' => 'Demo Teacher Specimen',
            'email' => 'specimen@test.local',
            'password' => Hash::make('password123'),
            'phone' => '+923001112233',
            'role' => 'student',
            'status' => 'active',
            'login_provider' => 'email',
            'last_token' => hash('sha256', 'active-student-jwt'),
        ]);

        WebUserProfile::create([
            'user_id' => $this->student->id,
            'city_id' => 1,
            'institute_id' => 1,
            'profile_completed' => true,
            'preferred_language' => 'en',
        ]);

        app('router')->aliasMiddleware('permission', CheckPermission::class);
        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);
    }

    private function buildIsolatedSchema(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique()->nullable();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('role_id');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('city_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });

        Schema::create('institute_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });

        Schema::create('class_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('class_name')->nullable();
        });

        Schema::create('subject_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('subject_name')->nullable();
        });

        Schema::create('curriculum_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
        });

        Schema::create('offered_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('curriculum_board_id')->nullable();
        });

        Schema::create('offered_programs', function (Blueprint $t) {
            $t->id();
            $t->string('title')->nullable();
        });

        Schema::create('subjects_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('subject_name')->nullable();
        });

        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('role');
            $t->string('phone')->unique()->nullable();
            $t->string('google_id')->unique()->nullable();
            $t->string('login_provider')->default('email');
            $t->string('avatar')->nullable();
            $t->string('status')->default('active');
            $t->text('last_token')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('user_profile_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('address')->nullable();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->unsignedBigInteger('institute_id')->nullable();
            $t->integer('gender_id')->nullable();
            $t->date('dob')->nullable();
            $t->string('designation')->nullable();
            $t->unsignedBigInteger('heard_about_id')->nullable();
            $t->string('referral_code')->nullable();
            $t->boolean('profile_completed')->default(false);
            $t->string('preferred_language')->default('en');
            $t->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('offered_program_id');
            $t->string('status')->default('active');
            $t->string('access_type')->default('free_specimen');
            $t->decimal('price_paid', 10, 2)->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('method')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('subscription_payment_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('offered_program_id')->default(1);
            $t->string('payment_method')->default('teacher');
            $t->unsignedBigInteger('teacher_profile_id')->nullable();
            $t->string('status')->default('pending');
            $t->decimal('final_amount', 10, 2)->default(0);
            $t->string('receipt_number')->nullable();
            $t->string('transaction_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('confirmed_by_web_user_id')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('admin_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_user_id');
            $t->string('action');
            $t->string('module');
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        foreach ([
            '2026_04_01_191541_create_permissions_table.php',
            '2026_04_01_191631_create_role_permissions_table.php',
            '2026_09_17_000001_create_teacher_profiles_table.php',
            '2026_09_17_000003_create_teacher_settlements.php',
            '2026_11_10_000001_create_teacher_classes_table.php',
            '2026_11_10_000002_create_teacher_subscription_tables.php',
            '2026_11_10_000003_create_teacher_subscription_plan_history_table.php',
            '2026_11_10_000004_add_teacher_collection_permission_to_profiles.php',
            '2026_11_11_000001_create_teacher_class_enrollments_and_notifications_tables.php',
            '2026_11_26_000001_create_web_user_role_changes_table.php',
        ] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }

    public function test_role_change_audit_migration_supports_clean_rollback(): void
    {
        $this->assertTrue(Schema::hasTable('web_user_role_changes'));
        $migration = require database_path('migrations/2026_11_26_000001_create_web_user_role_changes_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('web_user_role_changes'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('web_user_role_changes'));
    }

    public function test_admin_without_permission_gets_403_on_preview_and_change_role(): void
    {
        $this->actingAs($this->lowPrivAdmin, 'api')
            ->getJson("/api/admin/auth/web-user/{$this->student->id}/role-change-preview?target_role=teacher")
            ->assertStatus(403);

        $this->actingAs($this->lowPrivAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Unauthorized attempt',
            ])
            ->assertStatus(403);

        $this->assertSame('student', $this->student->fresh()->role);
    }

    public function test_arbitrary_and_privileged_roles_are_rejected(): void
    {
        foreach (['admin', 'super_admin', 'moderator', 'invalid'] as $badRole) {
            $this->actingAs($this->roleChangeAdmin, 'api')
                ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                    'target_role' => $badRole,
                    'reason' => 'Trying invalid role assignment',
                ])
                ->assertStatus(422);
        }
    }

    public function test_blocked_user_cannot_change_role(): void
    {
        $this->student->update(['status' => 'blocked']);

        $preview = $this->actingAs($this->roleChangeAdmin, 'api')
            ->getJson("/api/admin/auth/web-user/{$this->student->id}/role-change-preview?target_role=teacher")
            ->assertOk();

        $this->assertFalse($preview->json('data.eligible'));
        $this->assertSame('USER_ACCOUNT_INACTIVE', $preview->json('data.blockers.0.code'));

        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Attempting to convert blocked user',
            ])
            ->assertStatus(409);
    }

    public function test_preview_is_read_only_and_reports_active_student_subscription_warning(): void
    {
        UserSubscription::create([
            'user_id' => $this->student->id,
            'offered_program_id' => 1,
            'status' => 'active',
            'access_type' => 'free_specimen',
            'price_paid' => 0,
            'expires_at' => now()->addMonths(3),
        ]);

        $response = $this->actingAs($this->roleChangeAdmin, 'api')
            ->getJson("/api/admin/auth/web-user/{$this->student->id}/role-change-preview?target_role=teacher")
            ->assertOk();

        $this->assertTrue($response->json('data.eligible'));
        $this->assertSame('student', $response->json('data.current_role'));
        $this->assertSame('teacher', $response->json('data.target_role'));
        $this->assertFalse($response->json('data.requirements.phone_required'));
        $warningCodes = collect($response->json('data.warnings'))->pluck('code')->all();
        $this->assertContains('ACTIVE_STUDENT_SUBSCRIPTIONS', $warningCodes);

        // Verify zero mutations occurred during preview
        $this->assertSame('student', $this->student->fresh()->role);
        $this->assertNotNull($this->student->fresh()->last_token);
        $this->assertSame(0, TeacherProfile::count());
        $this->assertSame(0, WebUserRoleChange::count());
    }

    public function test_student_to_teacher_full_transactional_provisioning_and_idempotency(): void
    {
        $sub = UserSubscription::create([
            'user_id' => $this->student->id,
            'offered_program_id' => 1,
            'status' => 'active',
            'access_type' => 'free_specimen',
            'price_paid' => 0,
            'expires_at' => now()->addMonths(3),
        ]);

        $originalPassword = $this->student->password;
        $originalEmail = $this->student->email;

        $response = $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Upgrading specimen student account to active teacher',
                'teacher_status' => 'active',
            ])
            ->assertOk();

        $user = $this->student->fresh();
        $this->assertSame('teacher', $user->role);
        $this->assertSame($originalEmail, $user->email);
        $this->assertSame($originalPassword, $user->password);
        $this->assertNull($user->last_token);

        // Teacher profile provisioned with codes, active status, collection permission disabled
        $profile = TeacherProfile::where('web_user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('active', $profile->status);
        $this->assertMatchesRegularExpression('/^TCH-[A-Z0-9]{12}$/', $profile->teacher_code);
        $this->assertMatchesRegularExpression('/^COL-[A-Z0-9]{10}$/', $profile->collection_code);
        $this->assertFalse($profile->can_collect_student_subscriptions);
        $this->assertSame($this->roleChangeAdmin->id, (int) $profile->approved_by);
        $this->assertNotNull($profile->approved_at);
        $this->assertSame(1, (int) $profile->city_id);
        $this->assertSame(1, (int) $profile->institute_id);

        // Default Free Teacher Plan provisioned
        $teacherSub = TeacherSubscription::where('teacher_profile_id', $profile->id)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($teacherSub);
        $this->assertSame('automatic_free', $teacherSub->assignment_type);

        // Existing student subscription preserved intact
        $this->assertSame('active', $sub->fresh()->status);

        // Audit records created
        $this->assertSame(1, WebUserRoleChange::where('web_user_id', $user->id)->count());
        $audit = WebUserRoleChange::where('web_user_id', $user->id)->first();
        $this->assertSame('student', $audit->from_role);
        $this->assertSame('teacher', $audit->to_role);
        $this->assertSame($this->roleChangeAdmin->id, (int) $audit->changed_by_admin_id);
        $this->assertTrue(DB::table('teacher_profile_events')
            ->where('teacher_profile_id', $profile->id)
            ->where('action', 'role_changed_student_to_teacher')
            ->exists());

        // Duplicate / retry conversion prevented (same role -> 422, no duplicates)
        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Duplicate retry attempt',
            ])
            ->assertStatus(422);

        $this->assertSame(1, TeacherProfile::where('web_user_id', $user->id)->count());
        $this->assertSame(1, TeacherSubscription::where('teacher_profile_id', $profile->id)->count());
        $this->assertSame(1, WebUserRoleChange::where('web_user_id', $user->id)->count());
    }

    public function test_student_to_teacher_missing_and_duplicate_phone_validation(): void
    {
        $other = WebUser::create([
            'name' => 'Other User',
            'email' => 'other@test.local',
            'password' => Hash::make('password123'),
            'phone' => '+923009998877',
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->student->update(['phone' => null]);

        // Missing phone fails with 422
        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Missing phone test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // Duplicate phone fails with 422
        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Duplicate phone test',
                'phone' => $other->phone,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // Supplying unique phone succeeds
        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Valid phone supplied',
                'phone' => '+923005556677',
            ])
            ->assertOk();

        $this->assertSame('+923005556677', $this->student->fresh()->phone);
        $this->assertSame('teacher', $this->student->fresh()->role);
    }

    public function test_student_to_teacher_rolls_back_atomically_when_default_plan_missing(): void
    {
        TeacherSubscriptionPlan::query()->update(['is_default' => false]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Testing rollback when default plan is absent',
                'teacher_status' => 'active',
            ]);

        $this->assertGreaterThanOrEqual(500, $response->status());

        // Everything rolled back cleanly
        $fresh = $this->student->fresh();
        $this->assertSame('student', $fresh->role);
        $this->assertNotNull($fresh->last_token);
        $this->assertSame(0, TeacherProfile::where('web_user_id', $this->student->id)->count());
        $this->assertSame(0, WebUserRoleChange::where('web_user_id', $this->student->id)->count());
    }

    public function test_teacher_to_student_clean_transition_and_safe_re_upgrade_reuses_profile(): void
    {
        // Step 1: Student -> Teacher
        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Initial upgrade to teacher',
            ])
            ->assertOk();

        $profile = TeacherProfile::where('web_user_id', $this->student->id)->firstOrFail();
        $originalTeacherCode = $profile->teacher_code;
        $originalCollectionCode = $profile->collection_code;
        $profile->forceFill(['can_collect_student_subscriptions' => true])->save();
        $this->student->update(['last_token' => hash('sha256', 'active-teacher-jwt')]);

        // Step 2: Teacher -> Student
        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'Returning account to student role',
            ])
            ->assertOk();

        $user = $this->student->fresh();
        $profile = $profile->fresh();

        $this->assertSame('student', $user->role);
        $this->assertNull($user->last_token);
        $this->assertSame('suspended', $profile->status);
        $this->assertFalse($profile->can_collect_student_subscriptions);
        $this->assertSame($originalTeacherCode, $profile->teacher_code);
        $this->assertSame($originalCollectionCode, $profile->collection_code);

        // Teacher subscription suspended with history
        $this->assertSame(0, TeacherSubscription::where('teacher_profile_id', $profile->id)->where('status', 'active')->count());
        $this->assertSame(1, TeacherSubscription::where('teacher_profile_id', $profile->id)->where('status', 'suspended')->count());
        $this->assertTrue(TeacherSubscriptionHistory::where('teacher_profile_id', $profile->id)->where('event_type', 'subscription_suspended')->exists());

        // Step 3: Re-upgrade Student -> Teacher reuses same TeacherProfile and codes
        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Re-upgrading historical teacher profile',
            ])
            ->assertOk();

        $this->assertSame(1, TeacherProfile::where('web_user_id', $this->student->id)->count());
        $reused = TeacherProfile::where('web_user_id', $this->student->id)->firstOrFail();
        $this->assertSame($profile->id, $reused->id);
        $this->assertSame($originalTeacherCode, $reused->teacher_code);
        $this->assertSame($originalCollectionCode, $reused->collection_code);
        $this->assertSame('active', $reused->status);
        $this->assertFalse($reused->can_collect_student_subscriptions);
    }

    public function test_teacher_to_student_enforces_all_mandatory_blockers(): void
    {
        // Upgrade student to teacher first
        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Setup teacher for blocker tests',
            ])
            ->assertOk();

        $profile = TeacherProfile::where('web_user_id', $this->student->id)->firstOrFail();

        // Blocker 1: Active class
        $classId = DB::table('teacher_classes')->insertGetId([
            'teacher_profile_id' => $profile->id,
            'class_id' => 1,
            'subject_id' => 1,
            'curriculum_board_id' => 1,
            'name' => '9th Physics Section A',
            'section' => 'A',
            'class_code' => 'CLS-TEST-001',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'Blocked by active class',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'TEACHER_HAS_ACTIVE_CLASSES');

        // Deactivate class -> next blocker
        DB::table('teacher_classes')->where('id', $classId)->update(['is_active' => false]);

        // Blocker 2: Pending teacher payment request
        $pendingPayment = SubscriptionPaymentRequest::create([
            'user_id' => $this->student->id,
            'offered_program_id' => 1,
            'payment_method' => 'teacher',
            'teacher_profile_id' => $profile->id,
            'status' => 'pending',
            'final_amount' => 1500.00,
        ]);

        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'Blocked by pending payment request',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'TEACHER_HAS_PENDING_PAYMENT_REQUESTS');

        // Approve & confirm payment -> now triggers unsettled collection balance blocker
        $pendingPayment->update([
            'status' => 'approved',
            'confirmed_at' => now(),
            'confirmed_by_web_user_id' => $this->student->id,
        ]);

        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'Blocked by unsettled collection balance',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'TEACHER_HAS_UNSETTLED_COLLECTIONS');

        // Blocker 3: Pending settlement
        $settlement = TeacherSettlement::create([
            'teacher_profile_id' => $profile->id,
            'submission_key' => 'sub-key-1',
            'amount' => '1500.00',
            'currency' => 'PKR',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'status' => 'pending',
        ]);

        $res = $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'Blocked by pending settlement',
            ])
            ->assertStatus(409);

        $blockerCodes = collect($res->json('blockers'))->pluck('code')->all();
        $this->assertContains('TEACHER_HAS_PENDING_SETTLEMENTS', $blockerCodes);
        $this->assertContains('TEACHER_HAS_UNSETTLED_COLLECTIONS', $blockerCodes);

        // Confirm settlement -> balance becomes 0.00 -> conversion now succeeds!
        $settlement->update(['status' => 'confirmed', 'reviewed_by' => $this->superAdmin->id, 'reviewed_at' => now()]);

        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'All blockers resolved',
            ])
            ->assertOk();

        $this->assertSame('student', $this->student->fresh()->role);
    }

    public function test_general_update_and_legacy_endpoints_cannot_bypass_role_change_or_permissions(): void
    {
        // Modern update endpoint rejects role mutation with 422
        $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/admin/auth/web-user/update', [
                'user_id' => $this->student->id,
                'name' => $this->student->name,
                'email' => $this->student->email,
                'role' => 'teacher',
                'login_provider' => 'email',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ROLE_CHANGE_REQUIRES_DEDICATED_ENDPOINT');

        $this->assertSame('student', $this->student->fresh()->role);

        // Legacy endpoints enforce permissions (low-privilege admin gets 403)
        $this->actingAs($this->lowPrivAdmin, 'api')
            ->postJson('/api/update-web-user', [
                'user_id' => $this->student->id,
                'name' => 'Hacked',
                'email' => $this->student->email,
                'role' => 'teacher',
                'login_provider' => 'email',
            ])
            ->assertStatus(403);

        $this->actingAs($this->lowPrivAdmin, 'api')
            ->postJson('/api/create-web-user', [
                'name' => 'Unauthorized',
                'email' => 'unauth@test.local',
                'password' => 'password123',
                'role' => 'student',
                'login_provider' => 'email',
            ])
            ->assertStatus(403);

        $this->actingAs($this->lowPrivAdmin, 'api')
            ->getJson('/api/get-web-user-data?email=' . $this->student->email)
            ->assertStatus(403);

        $this->actingAs($this->lowPrivAdmin, 'api')
            ->getJson('/api/get-unverified-web-users')
            ->assertStatus(403);

        $this->actingAs($this->lowPrivAdmin, 'api')
            ->postJson('/api/verified-web-user', [
                'user_id' => $this->student->id,
                'email' => $this->student->email,
            ])
            ->assertStatus(403);

        // Even super admin cannot assign arbitrary role via create-web-user
        $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/admin/auth/web-user/create', [
                'name' => 'Invalid Role User',
                'email' => 'badrole@test.local',
                'password' => 'password123',
                'role' => 'super_admin',
                'login_provider' => 'email',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_student_to_teacher_pending_status_does_not_activate_or_provision_subscription(): void
    {
        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Converting student to pending teacher awaiting review',
                'teacher_status' => 'pending',
            ])
            ->assertOk();

        $user = $this->student->fresh();
        $profile = TeacherProfile::where('web_user_id', $user->id)->firstOrFail();

        $this->assertSame('teacher', $user->role);
        $this->assertNull($user->last_token);
        $this->assertSame('pending', $profile->status);
        $this->assertNull($profile->approved_at);
        $this->assertNull($profile->approved_by);
        $this->assertNull($profile->collection_code);
        $this->assertFalse($profile->can_collect_student_subscriptions);
        $this->assertSame(0, TeacherSubscription::where('teacher_profile_id', $profile->id)->count());
    }

    public function test_teacher_to_student_expires_only_target_teachers_expired_subscription(): void
    {
        // Upgrade target student to teacher
        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Setup target teacher with expired active subscription',
            ])
            ->assertOk();

        $targetProfile = TeacherProfile::where('web_user_id', $this->student->id)->firstOrFail();
        $targetSub = TeacherSubscription::where('teacher_profile_id', $targetProfile->id)->firstOrFail();
        $targetSub->update(['expires_at' => now()->subDay()]);

        // Create a second unrelated teacher who also has an expired active subscription
        $unrelatedUser = WebUser::create([
            'name' => 'Unrelated Teacher',
            'email' => 'unrelated.teacher@test.local',
            'password' => Hash::make('password123'),
            'phone' => '+923007776655',
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $unrelatedProfile = new TeacherProfile();
        $unrelatedProfile->web_user_id = $unrelatedUser->id;
        $unrelatedProfile->status = 'active';
        $unrelatedProfile->save();

        $plan = TeacherSubscriptionPlan::where('is_default', true)->firstOrFail();
        $unrelatedSub = TeacherSubscription::create([
            'teacher_profile_id' => $unrelatedProfile->id,
            'teacher_subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
            'assignment_type' => 'automatic_free',
        ]);

        // Convert target teacher -> student
        $this->actingAs($this->superAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'student',
                'reason' => 'Converting teacher with expired subscription back to student',
            ])
            ->assertOk();

        // Target teacher's expired subscription was transitioned to expired with history
        $this->assertSame('expired', $targetSub->fresh()->status);
        $this->assertTrue(
            TeacherSubscriptionHistory::where('teacher_subscription_id', $targetSub->id)
                ->where('event_type', 'subscription_expired')
                ->exists()
        );

        // Unrelated teacher's subscription was NOT touched!
        $this->assertSame('active', $unrelatedSub->fresh()->status);
    }

    public function test_student_to_teacher_rejects_phone_belonging_to_soft_deleted_user_with_422(): void
    {
        $deletedUser = WebUser::create([
            'name' => 'Soft Deleted User',
            'email' => 'deleted.user@test.local',
            'password' => Hash::make('password123'),
            'phone' => '+923008887766',
            'role' => 'student',
            'status' => 'active',
        ]);
        $deletedUser->delete();

        $this->student->update(['phone' => null]);

        $this->actingAs($this->roleChangeAdmin, 'api')
            ->postJson("/api/admin/auth/web-user/{$this->student->id}/change-role", [
                'target_role' => 'teacher',
                'reason' => 'Testing soft-deleted phone collision returns 422',
                'phone' => '+923008887766',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertSame('student', $this->student->fresh()->role);
    }
}
