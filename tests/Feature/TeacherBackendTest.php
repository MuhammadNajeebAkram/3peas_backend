<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeacherBackendTest extends TeacherPaymentTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('web_users', function (Blueprint $t) {
            foreach (['email', 'password', 'phone', 'google_id', 'avatar', 'login_provider'] as $field) {
                if (!Schema::hasColumn('web_users', $field)) $t->string($field)->nullable();
            }
            $t->timestamp('email_verified_at')->nullable();
        });
        Schema::create('user_profile_tbl', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->boolean('profile_completed')->default(false); $t->timestamps();
        });
        foreach (['city_tbl', 'institute_tbl'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->string('name')->nullable());
        }
        Schema::table('payment_accounts', function (Blueprint $t) {
            if (!Schema::hasColumn('payment_accounts', 'method')) $t->string('method')->nullable();
            if (!Schema::hasColumn('payment_accounts', 'is_active')) $t->boolean('is_active')->default(true);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('name'); $t->unsignedBigInteger('role_id'); $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        foreach (['2026_04_01_191541_create_permissions_table.php', '2026_04_01_191631_create_role_permissions_table.php', '2026_09_17_000003_create_teacher_settlements.php', '2026_11_10_000001_create_teacher_classes_table.php', '2026_11_10_000002_create_teacher_subscription_tables.php', '2026_11_10_000003_create_teacher_subscription_plan_history_table.php', '2026_11_11_000001_create_teacher_class_enrollments_and_notifications_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->seed(\Database\Seeders\TeacherSubscriptionPlanSeeder::class);
        $this->seed(\Database\Seeders\TeacherPermissionsSeeder::class);
        $this->seed(\Database\Seeders\LmsPermissionsSeeder::class);
        User::create(['name' => 'Admin', 'role_id' => Role::where('name', 'super_admin')->value('id')]);
        WebUser::where('id', 2)->update(['phone' => '03000000002']);
        app('router')->aliasMiddleware('permission', \App\Http\Middleware\CheckPermission::class);
        $this->withoutMiddleware([
            \App\Http\Middleware\AttachJwtFromCookie::class,
            \App\Http\Middleware\AuthenticateJwtCookieGuard::class,
        ]);
        Storage::fake('local');

        if (!Schema::hasColumn('offered_classes', 'curriculum_board_id')) {
            Schema::table('offered_classes', function (Blueprint $t) {
                $t->unsignedBigInteger('curriculum_board_id')->nullable()->default(1);
            });
        }
        if (!Schema::hasTable('program_subjects')) {
            Schema::create('program_subjects', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offered_program_id');
                $t->unsignedBigInteger('subject_id');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        DB::table('offered_classes')->where('id', 1)->update([
            'class_id' => 1,
            'curriculum_board_id' => 1,
        ]);
        DB::table('program_subjects')->updateOrInsert(['id' => 1], [
            'offered_program_id' => 1,
            'subject_id' => 1,
            'is_active' => 1,
        ]);
    }

    private function collect(): int
    {
        $this->actingAs(WebUser::find(1), 'web_api');
        $id = $this->postJson('/web_api/auth/save-payment-request', ['payment_method' => 'teacher',
            'offered_program_id' => 1, 'collection_code' => 'COL-000002'])->assertCreated()->json('payment_request.id');
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/approve', ['received_full_payment' => true])->assertOk();
        return $id;
    }

    private function settlement(int $payment, string $amount = '300.00'): array
    {
        return ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'paid_at' => today()->toDateString(),
            'allocations' => [['payment_request_id' => $payment, 'amount' => $amount]]];
    }

    public function test_phase_five_student_payment_uses_server_price_and_admin_review_activates_teacher_attribution(): void
    {
        Storage::fake('s3');
        DB::table('payment_accounts')->insert(['id' => 1, 'title' => 'Bank', 'method' => 'bank_deposit', 'is_active' => true]);
        $this->getJson('/web_api/auth/subscription-offerings')->assertOk()->assertJsonPath('data.0.price', 800);
        $payload = ['offered_program_id' => 1, 'payment_account_id' => 1, 'transaction_id' => 'BANK-PHASE5-001',
            'payer_name' => 'Student', 'payer_phone' => '03000000001', 'proof_file' => UploadedFile::fake()->image('slip.png')];
        $this->postJson('/web_api/auth/save-payment-request', $payload + ['amount' => 1])->assertUnprocessable();
        $pending = $this->postJson('/web_api/auth/save-payment-request', $payload)->assertCreated()
            ->assertJsonPath('payment_request.price', '1000.00')->assertJsonPath('payment_request.final_amount', '800.00')->json('payment_request.id');
        $this->assertDatabaseCount('user_subscriptions', 0);
        $this->assertDatabaseHas('subscription_payment_request_events', ['subscription_payment_request_id' => $pending, 'event_type' => 'submitted', 'reviewer_type' => 'student']);
        $this->postJson('/web_api/auth/save-payment-request', $payload)->assertUnprocessable();
        $this->getJson('/web_api/auth/subscription-payment-requests')->assertOk()->assertJsonPath('data.0.id', $pending);

        TeacherProfile::where('web_user_id', 2)->update(['can_collect_student_subscriptions' => false]);
        $this->actingAs(User::first(), 'api');
        $this->postJson('/api/admin/auth/student-payment-requests/'.$pending.'/approve', ['access_type' => 'complimentary', 'expires_at' => '2030-01-01'])->assertUnprocessable();
        $this->postJson('/api/admin/auth/student-payment-requests/'.$pending.'/approve')->assertOk()->assertJsonPath('payment_request.status', 'approved');
        $this->postJson('/api/admin/auth/student-payment-requests/'.$pending.'/approve')->assertOk();
        $this->assertDatabaseCount('user_subscriptions', 1);
        $this->assertDatabaseHas('subscription_payment_request_events', ['subscription_payment_request_id' => $pending, 'event_type' => 'approved', 'reviewer_type' => 'admin', 'new_status' => 'approved']);

        TeacherProfile::where('web_user_id', 2)->update(['can_collect_student_subscriptions' => true]);
        $teacherStudent = WebUser::create(['name' => 'Teacher Payment Student', 'role' => 'student', 'status' => 'active']);
        $this->actingAs($teacherStudent, 'web_api');
        $teacherPaymentId = $this->postJson('/web_api/auth/save-payment-request', ['payment_method' => 'teacher', 'offered_program_id' => 1, 'collection_code' => 'COL-000002'])
            ->assertCreated()->json('payment_request.id');
        $teacherProfileId = TeacherProfile::where('web_user_id', 2)->value('id');
        TeacherProfile::whereKey($teacherProfileId)->update(['can_collect_student_subscriptions' => false]);
        $this->actingAs(User::first(), 'api');
        $this->postJson('/api/admin/auth/student-payment-requests/'.$teacherPaymentId.'/approve')->assertOk();
        $this->assertDatabaseHas('subscription_payment_requests', ['id' => $teacherPaymentId, 'teacher_profile_id' => $teacherProfileId, 'collection_code_snapshot' => 'COL-000002']);
        $this->assertNotNull(\App\Models\SubscriptionPaymentRequest::find($teacherPaymentId)->confirmed_at);
        $this->assertCount(1, app(\App\Http\Services\TeacherSettlementService::class)->collections($teacherProfileId)->get());
    }

    public function test_phase_five_teacher_review_is_centralized_scoped_and_audited(): void
    {
        $id = $this->collect();
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/payment-requests/'.$id)->assertOk();
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/reject', ['rejection_reason' => 'Not received'])->assertConflict();

        $student = WebUser::create(['name' => 'Second Student', 'role' => 'student', 'status' => 'active']);
        $teacherProfileId = TeacherProfile::where('web_user_id', 2)->value('id');
        $payment = \App\Models\SubscriptionPaymentRequest::create(['user_id' => $student->id, 'offered_program_id' => 1,
            'payment_method' => 'teacher', 'teacher_profile_id' => $teacherProfileId, 'collection_code_snapshot' => 'COL-000002',
            'price' => 800, 'final_amount' => 800, 'status' => 'pending']);
        app(\App\Http\Services\StudentSubscriptionPaymentService::class)->event($payment, 'submitted', 'student', $student->id, null, 'pending');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$payment->id.'/reject', ['rejection_reason' => 'Student payment not received'])->assertOk();
        $this->assertDatabaseHas('subscription_payment_request_events', ['subscription_payment_request_id' => $payment->id, 'event_type' => 'rejected', 'reviewer_type' => 'teacher', 'previous_status' => 'pending']);
        TeacherProfile::where('web_user_id', 2)->update(['can_collect_student_subscriptions' => false]);
        $this->getJson('/web_api/auth/teacher/payment-requests')->assertOk();
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/approve', ['received_full_payment' => true])->assertForbidden();
    }

    public function test_phase_five_admin_view_permission_cannot_review(): void
    {
        $id = $this->collect();
        $role = Role::create(['name' => 'payment_viewer']);
        $permission = \App\Models\Permission::firstOrCreate(['name' => 'student-payment-requests.view']);
        $role->permissions()->attach($permission->id);
        $viewer = User::create(['name' => 'Payment Viewer', 'role_id' => $role->id]);
        $this->actingAs($viewer, 'api');
        $this->getJson('/api/admin/auth/student-payment-requests')->assertOk();
        $this->postJson('/api/admin/auth/student-payment-requests/'.$id.'/approve')->assertForbidden();
        $this->postJson('/api/admin/auth/student-payment-requests/'.$id.'/reject', ['rejection_reason' => 'Not received'])->assertForbidden();
    }

    public function test_phase_five_admin_rejection_requires_reason_and_never_activates_subscription(): void
    {
        Storage::fake('s3');
        DB::table('payment_accounts')->insert(['id' => 1, 'title' => 'Bank', 'method' => 'bank_deposit', 'is_active' => true]);
        $student = WebUser::create(['name' => 'Rejected Student', 'role' => 'student', 'status' => 'active']);
        $this->actingAs($student, 'web_api');
        $id = $this->postJson('/web_api/auth/save-payment-request', ['offered_program_id' => 1, 'payment_account_id' => 1,
            'transaction_id' => 'BANK-PHASE5-REJECT', 'payer_name' => 'Rejected', 'payer_phone' => '03000000001',
            'proof_file' => UploadedFile::fake()->image('slip.png')])->assertCreated()->json('payment_request.id');
        $this->actingAs(User::first(), 'api');
        $this->postJson('/api/admin/auth/student-payment-requests/'.$id.'/reject')->assertUnprocessable();
        $this->postJson('/api/admin/auth/student-payment-requests/'.$id.'/reject', ['rejection_reason' => 'Payment evidence could not be verified'])->assertOk();
        $this->assertDatabaseHas('subscription_payment_requests', ['id' => $id, 'status' => 'rejected', 'rejection_reason' => 'Payment evidence could not be verified']);
        $this->assertDatabaseCount('user_subscriptions', 0);
    }

    public function test_form_signup_creates_pending_teacher_and_no_student_profile(): void
    {
        $response = $this->postJson('/web_api/auth/teacher/register', ['name' => 'New Teacher', 'email' => 'new@example.test',
            'phone' => '03001234567', 'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'admin', 'status' => 'active', 'collection_code' => 'UNTRUSTED']);
        $response->assertOk()->assertJsonPath('user.role', 'teacher')->assertJsonPath('user.teacher_profile.status', 'pending')
            ->assertJsonPath('user.teacher_profile.collection_code', null);
        $this->assertDatabaseCount('user_profile_tbl', 0);
        $this->assertDatabaseHas('teacher_profiles', ['web_user_id' => $response->json('user.id'), 'status' => 'pending']);
        $this->postJson('/web_api/auth/register_user', ['name' => 'Bad', 'email' => 'bad@example.test', 'phone' => '123',
            'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin'])->assertUnprocessable();
    }

    public function test_profile_update_cannot_self_activate(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'pending', 'collection_code' => null]);
        $this->postJson('/web_api/auth/teacher/profile', ['name' => 'Updated', 'phone' => '03000000002', 'status' => 'active'])->assertUnprocessable();
        $this->postJson('/web_api/auth/complete-profile', ['name' => 'Updated', 'phone' => '03000000002'])->assertOk();
        $this->assertDatabaseCount('user_profile_tbl', 0);
        $this->getJson('/web_api/auth/teacher/dashboard')->assertOk()->assertJsonPath('can_collect', false);
    }

    public function test_admin_activation_rotation_and_suspension_are_audited(): void
    {
        $profile = TeacherProfile::where('web_user_id', 2)->first();
        $profile->forceFill(['status' => 'pending', 'collection_code' => null])->save();
        $this->actingAs(User::first(), 'api');
        $base = '/api/admin/auth/teachers/'.$profile->id;
        $this->postJson($base.'/status', ['status' => 'active', 'collection_code' => 'COL-FIRST'])->assertOk();
        $this->assertNotNull($profile->fresh()->approved_at);
        $this->postJson($base.'/collection-code', ['collection_code' => 'COL-SECOND'])->assertOk();
        $this->postJson($base.'/collection-code', ['collection_code' => 'COL-FIRST'])->assertUnprocessable();
        $this->postJson($base.'/status', ['status' => 'suspended', 'admin_note' => 'Review required'])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('teacher.admin_note', 'Review required');
        $this->assertDatabaseHas('teacher_profile_events', ['teacher_profile_id' => $profile->id, 'collection_code' => 'COL-FIRST']);
        $this->assertDatabaseHas('teacher_profile_events', ['teacher_profile_id' => $profile->id, 'action' => 'status_changed']);
    }

    public function test_admin_activate_endpoint_supports_both_route_formats(): void
    {
        $profile = TeacherProfile::where('web_user_id', 2)->first();
        $profile->forceFill(['status' => 'pending', 'collection_code' => null, 'approved_at' => null])->save();
        $this->actingAs(User::first(), 'api');

        // Test POST /teachers/{id}/activate with is_active flag
        $this->postJson('/api/admin/auth/teachers/'.$profile->id.'/activate', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('teacher.status', 'active');
        $this->assertNotNull($profile->fresh()->approved_at);
        $this->assertNotNull($profile->fresh()->collection_code);

        // Test POST /teachers/activate with id in body
        $this->postJson('/api/admin/auth/teachers/activate', [
            'id' => $profile->id,
            'status' => 'suspended',
            'admin_note' => 'Temporarily suspended by admin',
        ])->assertOk()->assertJsonPath('teacher.status', 'suspended');
        $this->assertEquals('suspended', $profile->fresh()->status);
    }

    public function test_admin_can_create_teacher_manually(): void
    {
        $this->actingAs(User::first(), 'api');

        // Test validation failure
        $this->postJson('/api/admin/auth/teachers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'password']);

        // Test successful creation of active teacher
        $res = $this->postJson('/api/admin/auth/teachers', [
            'name' => 'Sir Ahmed Raza',
            'email' => 'ahmed.raza@testschool.edu',
            'phone' => '03009876543',
            'password' => 'SecurePass123!',
            'status' => 'active',
            'admin_note' => 'Created directly by head admin',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('message', 'Teacher account created successfully.')
            ->assertJsonPath('teacher.web_user.name', 'Sir Ahmed Raza')
            ->assertJsonPath('teacher.web_user.email', 'ahmed.raza@testschool.edu')
            ->assertJsonPath('teacher.status', 'active');

        $createdTeacher = TeacherProfile::with('webUser')->latest('id')->first();
        $this->assertNotNull($createdTeacher);
        $this->assertEquals('Sir Ahmed Raza', $createdTeacher->webUser->name);
        $this->assertEquals('teacher', $createdTeacher->webUser->role);
        $this->assertNotNull($createdTeacher->collection_code);
        $this->assertNotNull($createdTeacher->approved_at);
        $this->assertNotNull($createdTeacher->approved_by);

        // Verify default free subscription was assigned
        $this->assertDatabaseHas('teacher_subscriptions', [
            'teacher_profile_id' => $createdTeacher->id,
            'status' => 'active',
        ]);

        // Test creating a pending teacher
        $resPending = $this->postJson('/api/admin/auth/teachers', [
            'name' => 'Miss Fatima Khan',
            'email' => 'fatima.khan@testschool.edu',
            'phone' => '03009876544',
            'password' => 'SecurePass123!',
            'status' => 'pending',
        ]);

        $resPending->assertStatus(201)
            ->assertJsonPath('teacher.status', 'pending');
        $pendingTeacher = TeacherProfile::where('web_user_id', $resPending->json('teacher.web_user.id'))->first();
        $this->assertNull($pendingTeacher->collection_code);
        $this->assertNull($pendingTeacher->approved_at);
    }

    public function test_admin_permissions_are_required(): void
    {
        $role = Role::create(['name' => 'unprivileged']);
        $user = User::create(['name' => 'No permission', 'role_id' => $role->id]);
        $this->actingAs($user, 'api');
        $this->getJson('/api/admin/auth/teachers')->assertForbidden();
        $this->postJson('/api/admin/auth/teachers/1/status', ['status' => 'active'])->assertForbidden();
        $this->postJson('/api/admin/auth/teacher-settlements/1/confirm', ['received_funds' => true])->assertForbidden();
    }

    public function test_partial_settlements_reserve_balance_and_confirmation_is_idempotent(): void
    {
        $payment = $this->collect();
        $data = $this->settlement($payment);
        $id = $this->postJson('/web_api/auth/teacher/settlements', $data)->assertCreated()->json('settlement.id');
        $this->postJson('/web_api/auth/teacher/settlements', $data)->assertCreated()->assertJsonPath('settlement.id', $id);
        $this->assertDatabaseCount('teacher_settlements', 1);
        $this->getJson('/web_api/auth/teacher/dashboard')->assertOk()->assertJsonPath('summary.outstanding', '800.00')
            ->assertJsonPath('summary.available_to_settle', '500.00');
        $this->getJson('/web_api/auth/teacher/collections')->assertOk()->assertJsonPath('data.0.id', $payment);
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment, '501.00'))->assertUnprocessable();
        $this->actingAs(User::first(), 'api');
        $url = '/api/admin/auth/teacher-settlements/'.$id.'/confirm';
        $this->postJson($url, ['received_funds' => true])->assertOk();
        $this->postJson($url, ['received_funds' => true])->assertOk();
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/dashboard')->assertOk()->assertJsonPath('summary.outstanding', '500.00')
            ->assertJsonPath('summary.settled', '300.00');
    }

    public function test_rejection_releases_reservation_and_other_teacher_cannot_allocate(): void
    {
        $payment = $this->collect();
        $data = $this->settlement($payment, '800.00');
        $id = $this->postJson('/web_api/auth/teacher/settlements', $data)->assertCreated()->json('settlement.id');
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertUnprocessable();
        $this->getJson('/web_api/auth/teacher/settlements')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs(User::first(), 'api');
        $this->postJson('/api/admin/auth/teacher-settlements/'.$id.'/reject', ['rejection_reason' => 'Not received'])->assertOk();
        $this->postJson('/api/admin/auth/teacher-settlements/'.$id.'/confirm', ['received_funds' => true])->assertStatus(409);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment, '800.00'))->assertCreated();
    }

    public function test_bank_proof_is_private_and_uses_existing_bank_deposit_accounts(): void
    {
        $payment = $this->collect();
        DB::table('payment_accounts')->insert(['id' => 1, 'method' => 'bank_deposit', 'is_active' => true]);
        $data = array_merge($this->settlement($payment), ['payment_method' => 'bank', 'payment_account_id' => 1,
            'transaction_reference' => 'BANK-001', 'proof_file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')]);
        $response = $this->postJson('/web_api/auth/teacher/settlements', $data)->assertCreated()->assertJsonMissingPath('settlement.proof_path');
        $url = '/web_api/auth/teacher/settlements/'.$response->json('settlement.id').'/proof';
        $this->get($url)->assertOk();
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->getJson($url)->assertNotFound();
        $this->actingAs(User::first(), 'api');
        $this->get('/api/admin/auth/teacher-settlements/'.$response->json('settlement.id').'/proof')->assertOk();
    }

    public function test_suspended_teacher_can_settle_but_blocked_login_cannot(): void
    {
        $payment = $this->collect();
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'suspended', 'can_collect_student_subscriptions' => false]);
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertCreated();
        WebUser::whereKey(2)->update(['status' => 'blocked']);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertForbidden();
    }

    public function test_collection_permission_is_explicit_audited_and_idempotent(): void
    {
        $profile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $profile->forceFill(['can_collect_student_subscriptions' => false])->save();
        $existingEvents = DB::table('teacher_profile_events')->count();
        $this->actingAs(User::first(), 'api');
        $url = '/api/admin/auth/teacher-collection-permissions/'.$profile->id;

        $this->getJson($url)->assertOk()->assertJsonPath('data.permission_enabled', false)
            ->assertJsonPath('data.currently_authorized', false)->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.collection_code', 'COL-000002');
        $this->postJson($url.'/enable', [])->assertUnprocessable();
        $this->assertSame($existingEvents, DB::table('teacher_profile_events')->count());

        $this->postJson($url.'/enable', ['reason' => 'Approved collection agent'])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('data.permission_enabled', true)
            ->assertJsonPath('data.currently_authorized', true);
        $this->assertDatabaseHas('teacher_profile_events', [
            'teacher_profile_id' => $profile->id,
            'admin_id' => User::first()->id,
            'action' => 'collection_permission_enabled',
        ]);
        $event = DB::table('teacher_profile_events')->where('action', 'collection_permission_enabled')->first();
        $this->assertSame('Approved collection agent', json_decode($event->details, true)['reason']);
        $this->assertSame($existingEvents + 1, DB::table('teacher_profile_events')->count());

        $this->postJson($url.'/enable', ['reason' => 'Repeat request'])
            ->assertOk()->assertJsonPath('changed', false);
        $this->assertSame($existingEvents + 1, DB::table('teacher_profile_events')->count());

        $this->postJson($url.'/disable', ['reason' => 'Collection access revoked'])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('data.permission_enabled', false);
        $this->assertSame($existingEvents + 2, DB::table('teacher_profile_events')->count());
        $this->assertDatabaseHas('teacher_profile_events', ['teacher_profile_id' => $profile->id, 'action' => 'collection_permission_disabled']);
        $this->assertSame('COL-000002', $profile->fresh()->collection_code);
    }

    public function test_collection_permission_management_checks_eligibility_and_admin_permissions(): void
    {
        $profile = TeacherProfile::where('web_user_id', 2)->firstOrFail();
        $profile->forceFill(['can_collect_student_subscriptions' => false, 'status' => 'pending'])->save();
        $this->actingAs(User::first(), 'api');
        $url = '/api/admin/auth/teacher-collection-permissions/'.$profile->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.eligibility_reason', 'Teacher profile must be approved and active.');
        $this->postJson($url.'/enable', ['reason' => 'Should be blocked'])->assertUnprocessable();
        $this->assertFalse((bool) $profile->fresh()->can_collect_student_subscriptions);

        $profile->forceFill(['status' => 'active', 'collection_code' => null])->save();
        $this->postJson($url.'/enable', ['reason' => 'Missing collection code'])->assertUnprocessable();
        $profile->forceFill(['collection_code' => 'COL-000002'])->save();
        WebUser::whereKey(2)->update(['phone' => null]);
        $this->postJson($url.'/enable', ['reason' => 'Missing phone'])->assertUnprocessable();
        WebUser::whereKey(2)->update(['phone' => '03000000002']);

        $viewRole = Role::create(['name' => 'collection_viewer']);
        $viewUser = User::create(['name' => 'Collection viewer', 'role_id' => $viewRole->id]);
        $viewRole->permissions()->attach(\App\Models\Permission::where('name', 'teacher-collection-permissions.view')->value('id'));
        $this->actingAs($viewUser, 'api');
        $this->getJson($url)->assertOk();
        $this->postJson($url.'/enable', ['reason' => 'Not allowed'])->assertForbidden();

        $noAccessRole = Role::create(['name' => 'collection_no_access']);
        $noAccessUser = User::create(['name' => 'No collection access', 'role_id' => $noAccessRole->id]);
        $this->actingAs($noAccessUser, 'api');
        $this->getJson($url)->assertForbidden();
        $this->postJson($url.'/disable', ['reason' => 'Not allowed'])->assertForbidden();
        $this->assertDatabaseHas('role_permissions', ['role_id' => Role::where('name', 'super_admin')->value('id'), 'permission_id' => \App\Models\Permission::where('name', 'teacher-collection-permissions.manage')->value('id')]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $viewRole->id, 'permission_id' => \App\Models\Permission::where('name', 'teacher-collection-permissions.manage')->value('id')]);
    }

    public function test_revoked_collection_permission_keeps_history_and_remittance_but_blocks_actions(): void
    {
        $payment = $this->collect();
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$payment.'/approve', ['received_full_payment' => true])->assertOk();

        DB::table('offered_classes')->insert(['id' => 2, 'Price' => 900, 'discount_price' => 900]);
        DB::table('offered_programs')->insert(['id' => 2, 'offered_class_id' => 2, 'title' => 'Chemistry']);
        $this->actingAs(WebUser::find(1), 'web_api');
        $pending = $this->postJson('/web_api/auth/save-payment-request', [
            'payment_method' => 'teacher', 'offered_program_id' => 2, 'collection_code' => 'COL-000002',
        ])->assertCreated()->json('payment_request.id');

        TeacherProfile::where('web_user_id', 2)->update(['can_collect_student_subscriptions' => false]);
        $this->postJson('/web_api/auth/payments/teacher/lookup', ['collection_code' => 'COL-000002'])->assertUnprocessable();
        $this->postJson('/web_api/auth/save-payment-request', [
            'payment_method' => 'teacher', 'offered_program_id' => 2, 'collection_code' => 'COL-000002',
        ])->assertUnprocessable();

        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/payment-requests')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/web_api/auth/teacher/collections')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$pending.'/approve', ['received_full_payment' => true])->assertForbidden();
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$pending.'/reject', ['rejection_reason' => 'Not received'])->assertForbidden();
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertCreated();
    }

    public function test_teacher_cannot_change_collection_permission_or_admin_code_visibility_leaks(): void
    {
        TeacherProfile::where('web_user_id', 2)->update(['can_collect_student_subscriptions' => false]);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/profile', [
            'name' => 'Teacher', 'phone' => '03000000002', 'can_collect_student_subscriptions' => true,
        ])->assertUnprocessable();
        $this->assertFalse((bool) TeacherProfile::where('web_user_id', 2)->value('can_collect_student_subscriptions'));

        $viewerRole = Role::create(['name' => 'teacher_view_only']);
        $viewerRole->permissions()->attach(\App\Models\Permission::where('name', 'teachers.view')->value('id'));
        $viewer = User::create(['name' => 'Teacher viewer', 'role_id' => $viewerRole->id]);
        $this->actingAs($viewer, 'api');
        $detail = $this->getJson('/api/admin/auth/teachers/'.$this->profileId(2))->assertOk()
            ->assertJsonMissingPath('teacher.collection_code');
        $this->assertStringNotContainsString('COL-000002', $detail->getContent());
        $list = $this->getJson('/api/admin/auth/teachers')->assertOk();
        $this->assertStringNotContainsString('COL-000002', $list->getContent());

        $approverRole = Role::create(['name' => 'teacher_approver_only']);
        $approverRole->permissions()->attach(\App\Models\Permission::where('name', 'teachers.approve')->value('id'));
        $approver = User::create(['name' => 'Teacher approver', 'role_id' => $approverRole->id]);
        $this->actingAs($approver, 'api');
        $this->postJson('/api/admin/auth/teachers/'.$this->profileId(2).'/collection-code', ['collection_code' => 'COL-NEW123'])->assertForbidden();
        $this->postJson('/api/admin/auth/teachers/'.$this->profileId(2).'/status', ['status' => 'active', 'collection_code' => 'COL-NEW123'])->assertForbidden();
    }

    private function profileId(int $webUserId): int
    {
        return (int) TeacherProfile::where('web_user_id', $webUserId)->value('id');
    }

    public function test_submission_key_cannot_be_reused_with_different_amounts(): void
    {
        $payment = $this->collect();
        $data = $this->settlement($payment);
        $this->postJson('/web_api/auth/teacher/settlements', $data)->assertCreated();
        $data['allocations'][0]['amount'] = '301.00';
        $this->postJson('/web_api/auth/teacher/settlements', $data)->assertStatus(409);
        $data['submission_key'] = (string) Str::uuid();
        $data['allocations'][0]['amount'] = '1.001';
        $this->postJson('/web_api/auth/teacher/settlements', $data)->assertUnprocessable();
        $this->assertDatabaseCount('teacher_settlements', 1);
    }

    public function test_unconfirmed_collections_and_mismatched_accounts_cannot_be_settled(): void
    {
        $payment = $this->collect();
        DB::table('payment_accounts')->insert(['id' => 1, 'method' => 'jazzcash', 'is_active' => true]);
        $data = array_merge($this->settlement($payment), ['payment_method' => 'bank', 'payment_account_id' => 1,
            'transaction_reference' => 'WRONG-ACCOUNT']);
        $this->postJson('/web_api/auth/teacher/settlements', $data)->assertUnprocessable();
        DB::table('subscription_payment_requests')->where('id', $payment)->update(['status' => 'pending', 'confirmed_at' => null]);
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertUnprocessable();
        $this->assertDatabaseCount('teacher_settlements', 0);
    }

    public function test_teacher_password_login_uses_existing_lms_authentication(): void
    {
        WebUser::whereKey(2)->update(['email' => 'teacher@example.test', 'password' => \Illuminate\Support\Facades\Hash::make('password123')]);
        $this->postJson('/web_api/auth/lms-login', ['login' => 'teacher@example.test', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.role', 'teacher')->assertJsonPath('user.teacher_profile.status', 'active');
        $this->postJson('/web_api/auth/lms-login', ['login' => 'teacher@example.test', 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/web_api/auth/lms-login', [])->assertUnprocessable();
    }

    public function test_new_private_routes_require_authentication(): void
    {
        $this->withMiddleware([\App\Http\Middleware\AttachJwtFromCookie::class, \App\Http\Middleware\AuthenticateJwtCookieGuard::class]);
        $this->getJson('/web_api/auth/teacher/dashboard')->assertUnauthorized();
        $this->getJson('/web_api/auth/teacher/settlements')->assertUnauthorized();
        $this->getJson('/api/admin/auth/teachers')->assertUnauthorized();
    }

    public function test_student_registration_keeps_student_profile(): void
    {
        $this->postJson('/web_api/auth/register_user', ['name' => 'Student Two', 'email' => 'student2@example.test',
            'phone' => '03000000099', 'role' => 'student', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertOk()->assertJsonPath('user.role', 'student');
        $this->assertDatabaseCount('user_profile_tbl', 1);
        $this->assertDatabaseCount('teacher_profiles', 2);
    }

    public function test_phase5_cross_reviewer_conflict_renewal_and_input_normalization(): void
    {
        Storage::fake('s3');
        DB::table('payment_accounts')->insert(['id' => 1, 'title' => 'Bank', 'method' => 'bank', 'is_active' => true]);

        // 1. Teacher approves first; subsequent Admin approval gets 409 Conflict; another Teacher gets 404
        $paymentId = $this->collect();
        TeacherProfile::where('web_user_id', 3)->update(['can_collect_student_subscriptions' => true]);
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$paymentId.'/approve', ['received_full_payment' => true])->assertNotFound();

        $this->actingAs(User::first(), 'api');
        $this->postJson('/api/admin/auth/student-payment-requests/'.$paymentId.'/approve')->assertStatus(409);
        $this->assertDatabaseCount('user_subscriptions', 1);
        $this->assertSame(1, DB::table('subscription_payment_request_events')
            ->where('subscription_payment_request_id', $paymentId)->where('event_type', 'approved')->count());

        // 2. Expired subscription renewal reuses existing UserSubscription row in-place without duplicates
        $subId = (int) DB::table('user_subscriptions')->where('user_id', 1)->where('offered_program_id', 1)->value('id');
        DB::table('user_subscriptions')->where('id', $subId)->update([
            'status' => 'expired',
            'expires_at' => now()->subDays(5)->toDateString(),
        ]);

        $this->actingAs(WebUser::find(1), 'web_api');
        foreach (['discount_amount', 'discount_percentage', 'final_amount', 'access_type', 'expires_at'] as $forbiddenField) {
            $this->post('/web_api/auth/save-payment-request', [
                'offered_program_id' => 1, 'payment_account_id' => 1, 'transaction_id' => 'TX-FORBID-'.$forbiddenField,
                'payer_name' => 'Student', 'payer_phone' => '03000000001',
                'proof_file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
                $forbiddenField => '1',
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        }

        $renewalId = $this->post('/web_api/auth/save-payment-request', [
            'offered_program_id' => 1, 'payment_account_id' => 1, 'transaction_id' => '  TX-RENEW-001  ',
            'payer_name' => '  Student  ', 'payer_phone' => '  03000000001  ',
            'proof_file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('payment_request.id');

        $this->assertDatabaseHas('subscription_payment_requests', [
            'id' => $renewalId, 'transaction_id' => 'TX-RENEW-001', 'payer_name' => 'Student', 'payer_phone' => '03000000001',
        ]);

        // Duplicate transaction_id with whitespace is rejected at validation
        $this->post('/web_api/auth/save-payment-request', [
            'offered_program_id' => 1, 'payment_account_id' => 1, 'transaction_id' => 'TX-RENEW-001',
            'payer_name' => 'Student', 'payer_phone' => '03000000001',
            'proof_file' => UploadedFile::fake()->create('proof2.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs(User::first(), 'api');
        $this->postJson('/api/admin/auth/student-payment-requests/'.$renewalId.'/approve')
            ->assertOk()->assertJsonPath('payment_request.subscription_id', $subId);
        $this->assertDatabaseCount('user_subscriptions', 1);
        $this->assertDatabaseHas('user_subscriptions', ['id' => $subId, 'status' => 'active', 'price_paid' => 800.00]);
    }

    public function test_phase5_true_concurrent_approvals_across_parallel_connections_and_renewal(): void
    {
        $prefix = 'conc_'.strtolower(Str::random(6)).'_';
        $mysqlConfig = array_merge(config('database.connections.mysql'), ['prefix' => $prefix]);
        config(['database.connections.conc_mysql' => $mysqlConfig]);
        DB::purge('conc_mysql');
        $schema = Schema::connection('conc_mysql');
        $db = DB::connection('conc_mysql');

        $tables = [
            'subscription_payment_request_events',
            'subscription_payment_requests',
            'user_subscriptions',
            'teacher_profiles',
            'offered_programs',
            'offered_classes',
            'class_tbl',
            'web_users',
        ];
        $barrierDir = storage_path('framework/testing/'.$prefix.'barriers');
        if (!is_dir($barrierDir)) {
            mkdir($barrierDir, 0777, true);
        }

        try {
            $schema->create('web_users', function (Blueprint $t) {
                $t->id(); $t->string('name'); $t->string('role'); $t->string('status');
                $t->string('phone')->nullable(); $t->softDeletes(); $t->timestamps();
            });
            $schema->create('class_tbl', function (Blueprint $t) {
                $t->id(); $t->string('class_name')->nullable(); $t->timestamps();
            });
            $schema->create('offered_classes', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('class_id')->nullable();
                $t->decimal('Price', 10, 2); $t->decimal('discount_price', 10, 2)->nullable();
                $t->boolean('is_active')->default(true); $t->boolean('is_free')->default(false);
                $t->date('session_end')->nullable(); $t->date('session_start')->nullable();
                $t->integer('display_order')->default(0);
            });
            $schema->create('offered_programs', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('offered_class_id'); $t->string('title');
                $t->boolean('is_active')->default(true); $t->string('slug')->nullable();
                $t->integer('display_order')->default(0);
            });
            $schema->create('teacher_profiles', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('web_user_id'); $t->string('teacher_code')->nullable();
                $t->string('collection_code')->nullable(); $t->string('status')->default('active');
                $t->boolean('can_collect_student_subscriptions')->default(true); $t->timestamps();
            });
            $schema->create('user_subscriptions', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('offered_program_id');
                $t->string('status')->default('pending'); $t->string('access_type')->default('paid');
                $t->decimal('price_paid', 10, 2)->default(0); $t->date('started_at')->nullable();
                $t->date('expires_at')->nullable(); $t->dateTime('approved_at')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable(); $t->timestamps();
                $t->index(['user_id', 'offered_program_id']);
            });
            $schema->create('subscription_payment_requests', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('offered_program_id');
                $t->unsignedBigInteger('subscription_id')->nullable();
                $t->unsignedBigInteger('payment_account_id')->nullable();
                $t->decimal('price', 10, 2)->default(0); $t->decimal('discount_amount', 10, 2)->default(0);
                $t->decimal('discount_percentage', 5, 2)->default(0); $t->decimal('final_amount', 10, 2)->default(0);
                $t->string('transaction_id')->nullable()->unique();
                $t->string('payer_name')->nullable(); $t->string('payer_phone')->nullable();
                $t->string('proof_file_path')->nullable(); $t->string('status')->default('pending');
                $t->date('approved_at')->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
                $t->unsignedBigInteger('rejected_by')->nullable(); $t->string('rejection_reason')->nullable();
                $t->string('admin_remarks')->nullable(); $t->string('payment_method')->default('account');
                $t->unsignedBigInteger('teacher_profile_id')->nullable();
                $t->string('collection_code_snapshot')->nullable();
                $t->unsignedBigInteger('confirmed_by_web_user_id')->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->unsignedBigInteger('rejected_by_web_user_id')->nullable();
                $t->string('receipt_number')->nullable()->unique();
                $t->timestamps();
            });
            $schema->create('subscription_payment_request_events', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('subscription_payment_request_id');
                $t->string('event_type', 24); $t->string('reviewer_type', 16)->nullable();
                $t->unsignedBigInteger('reviewer_id')->nullable();
                $t->string('previous_status', 16)->nullable(); $t->string('new_status', 16)->nullable();
                $t->string('reason', 1000)->nullable(); $t->json('details')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });

            $db->table('web_users')->insert([
                ['id' => 1, 'name' => 'Student One', 'role' => 'student', 'status' => 'active', 'phone' => '03000000001'],
                ['id' => 2, 'name' => 'Teacher One', 'role' => 'teacher', 'status' => 'active', 'phone' => '03000000002'],
                ['id' => 4, 'name' => 'Student Two', 'role' => 'student', 'status' => 'active', 'phone' => '03000000004'],
            ]);
            $db->table('teacher_profiles')->insert([
                'id' => 1, 'web_user_id' => 2, 'teacher_code' => 'TCH-000002',
                'collection_code' => 'COL-000002', 'status' => 'active', 'can_collect_student_subscriptions' => 1,
            ]);
            $db->table('offered_classes')->insert([
                'id' => 1, 'Price' => 1000, 'discount_price' => 800, 'is_active' => 1, 'is_free' => 0,
                'session_end' => now()->addDays(60)->toDateString(),
            ]);
            $db->table('offered_programs')->insert([
                'id' => 1, 'offered_class_id' => 1, 'title' => 'Physics', 'is_active' => 1,
            ]);

            // Two DIFFERENT pending payment requests for Student 1 + Offering 1 when NO subscription exists yet
            $db->table('subscription_payment_requests')->insert([
                [
                    'id' => 1, 'user_id' => 1, 'offered_program_id' => 1, 'payment_method' => 'teacher',
                    'teacher_profile_id' => 1, 'collection_code_snapshot' => 'COL-000002',
                    'price' => 1000, 'discount_amount' => 200, 'discount_percentage' => 20, 'final_amount' => 800,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'id' => 2, 'user_id' => 1, 'offered_program_id' => 1, 'payment_method' => 'account',
                    'teacher_profile_id' => null, 'collection_code_snapshot' => null,
                    'price' => 1000, 'discount_amount' => 200, 'discount_percentage' => 20, 'final_amount' => 800,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'id' => 3, 'user_id' => 4, 'offered_program_id' => 1, 'payment_method' => 'teacher',
                    'teacher_profile_id' => 1, 'collection_code_snapshot' => 'COL-000002',
                    'price' => 1000, 'discount_amount' => 200, 'discount_percentage' => 20, 'final_amount' => 800,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            $this->assertSame(0, $db->table('user_subscriptions')->count());

            // 1. Run two parallel PHP worker processes approving DIFFERENT requests (#1 and #2) for Student 1
            [$resA, $resB] = $this->runConcurrentApprovalWorkers($prefix, $barrierDir, 'diff_req', [
                ['request_id' => 1, 'reviewer_type' => 'teacher', 'reviewer_id' => 2, 'teacher_id' => 1, 'barrier_mode' => 'after_request_lock'],
                ['request_id' => 2, 'reviewer_type' => 'admin', 'reviewer_id' => 1, 'teacher_id' => null, 'barrier_mode' => 'after_request_lock'],
            ]);

            $this->assertTrue($resA['barrier_synced'] && $resB['barrier_synced'], 'Both workers must overlap inside DB::transaction after locking their respective payment requests.');
            $statuses = [$resA['status'], $resB['status']];
            sort($statuses);
            $this->assertSame([200, 409], $statuses, 'Exactly one concurrent approval must succeed (200) and the other must fail with 409 Conflict.');

            $this->assertSame(1, $db->table('user_subscriptions')->where('user_id', 1)->where('offered_program_id', 1)->count());
            $this->assertSame(1, $db->table('user_subscriptions')->where('user_id', 1)->where('status', 'active')->count());
            $this->assertSame(1, $db->table('subscription_payment_requests')->whereIn('id', [1, 2])->where('status', 'approved')->count());
            $this->assertSame(1, $db->table('subscription_payment_requests')->whereIn('id', [1, 2])->where('status', 'pending')->count());
            $this->assertSame(1, $db->table('subscription_payment_request_events')->whereIn('subscription_payment_request_id', [1, 2])->where('event_type', 'approved')->count());

            // 2. Run two parallel PHP worker processes approving the SAME request (#3) as Teacher and Admin
            [$sameA, $sameB] = $this->runConcurrentApprovalWorkers($prefix, $barrierDir, 'same_req', [
                ['request_id' => 3, 'reviewer_type' => 'teacher', 'reviewer_id' => 2, 'teacher_id' => 1, 'barrier_mode' => 'before_approve'],
                ['request_id' => 3, 'reviewer_type' => 'admin', 'reviewer_id' => 1, 'teacher_id' => null, 'barrier_mode' => 'before_approve'],
            ]);

            $this->assertTrue($sameA['barrier_synced'] && $sameB['barrier_synced']);
            $sameStatuses = [$sameA['status'], $sameB['status']];
            sort($sameStatuses);
            $this->assertSame([200, 409], $sameStatuses, 'Same-request concurrent Teacher/Admin approval must allow only one successful reviewer.');
            $this->assertSame(1, $db->table('user_subscriptions')->where('user_id', 4)->where('offered_program_id', 1)->count());
            $this->assertSame(1, $db->table('subscription_payment_request_events')->where('subscription_payment_request_id', 3)->where('event_type', 'approved')->count());

            // 3. Verify renewal on existing expired subscription updates the row in-place without creating duplicates
            $existingSubId = (int) $db->table('user_subscriptions')->where('user_id', 4)->where('offered_program_id', 1)->value('id');
            $db->table('user_subscriptions')->where('id', $existingSubId)->update([
                'status' => 'expired',
                'expires_at' => now()->subDays(3)->toDateString(),
            ]);
            $db->table('subscription_payment_requests')->insert([
                'id' => 4, 'user_id' => 4, 'offered_program_id' => 1, 'payment_method' => 'account',
                'price' => 1000, 'discount_amount' => 200, 'discount_percentage' => 20, 'final_amount' => 800,
                'status' => 'pending', 'transaction_id' => 'TX-RENEW-CONC', 'created_at' => now(), 'updated_at' => now(),
            ]);
            [$renewRes] = $this->runConcurrentApprovalWorkers($prefix, $barrierDir, 'renew_single', [
                ['request_id' => 4, 'reviewer_type' => 'admin', 'reviewer_id' => 1, 'teacher_id' => null, 'barrier_mode' => 'none'],
            ]);
            $this->assertSame(200, $renewRes['status']);
            $this->assertSame(1, $db->table('user_subscriptions')->where('user_id', 4)->where('offered_program_id', 1)->count());
            $this->assertSame('active', $db->table('user_subscriptions')->where('id', $existingSubId)->value('status'));
            $this->assertSame($existingSubId, (int) $db->table('subscription_payment_requests')->where('id', 4)->value('subscription_id'));
        } finally {
            foreach ($tables as $table) {
                $schema->dropIfExists($table);
            }
            foreach (glob($barrierDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($barrierDir);
            DB::purge('conc_mysql');
        }
    }

    private function runConcurrentApprovalWorkers(string $prefix, string $barrierDir, string $group, array $workers): array
    {
        $script = <<<'PHP'
$autoload = $argv[1];
$basePath = $argv[2];
$payload = json_decode(base64_decode($argv[3]), true);
require $autoload;
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mysql = array_merge(config('database.connections.mysql'), ['prefix' => $payload['prefix']]);
config(['database.default' => 'conc_worker', 'database.connections.conc_worker' => $mysql]);
Illuminate\Support\Facades\DB::purge('conc_worker');

$barrierSynced = false;
$syncBarrier = function () use ($payload, &$barrierSynced) {
    if ($barrierSynced) return;
    file_put_contents($payload['self_file'], '1');
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        if (file_exists($payload['peer_file'])) {
            $barrierSynced = true;
            break;
        }
        usleep(10000);
    }
};

if ($payload['barrier_mode'] === 'before_approve') {
    $syncBarrier();
} elseif ($payload['barrier_mode'] === 'after_request_lock') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($syncBarrier, &$barrierSynced) {
        if (!$barrierSynced && str_contains($query->sql, 'subscription_payment_requests') && str_contains(strtolower($query->sql), 'for update')) {
            $syncBarrier();
        }
    });
} else {
    $barrierSynced = true;
}

try {
    $teacher = $payload['teacher_id'] ? App\Models\TeacherProfile::find($payload['teacher_id']) : null;
    $payment = app(App\Http\Services\StudentSubscriptionPaymentService::class)->approve(
        (int) $payload['request_id'],
        (string) $payload['reviewer_type'],
        (int) $payload['reviewer_id'],
        $teacher
    );
    echo json_encode(['status' => 200, 'subscription_id' => $payment->subscription_id, 'barrier_synced' => $barrierSynced]);
} catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
    echo json_encode([
        'status' => $e->getResponse()->getStatusCode(),
        'body' => json_decode($e->getResponse()->getContent(), true),
        'barrier_synced' => $barrierSynced,
    ]);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo json_encode([
        'status' => $e->getStatusCode(),
        'message' => $e->getMessage(),
        'barrier_synced' => $barrierSynced,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 500,
        'error' => get_class($e).': '.$e->getMessage(),
        'barrier_synced' => $barrierSynced,
    ]);
}
PHP;

        $procs = [];
        foreach ($workers as $idx => $worker) {
            $peerIdx = $idx === 0 ? 1 : 0;
            $payload = array_merge($worker, [
                'prefix' => $prefix,
                'self_file' => $barrierDir."/{$group}_{$idx}.ready",
                'peer_file' => $barrierDir."/{$group}_{$peerIdx}.ready",
            ]);
            $cmd = [
                PHP_BINARY,
                '-r',
                $script,
                base_path('vendor/autoload.php'),
                base_path(),
                base64_encode(json_encode($payload)),
            ];
            $pipes = [];
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            fclose($pipes[0]);
            $procs[$idx] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($procs as $idx => $item) {
            $stdout = stream_get_contents($item['pipes'][1]);
            $stderr = stream_get_contents($item['pipes'][2]);
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            proc_close($item['proc']);
            $decoded = json_decode(trim($stdout), true);
            $this->assertIsArray($decoded, "Worker {$idx} output invalid: {$stdout} STDERR: {$stderr}");
            $results[$idx] = $decoded;
        }

        return $results;
    }
}
