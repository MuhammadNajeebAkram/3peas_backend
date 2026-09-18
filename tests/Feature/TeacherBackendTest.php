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
            foreach (['email', 'password', 'phone', 'google_id', 'avatar', 'login_provider'] as $field) $t->string($field)->nullable();
            $t->timestamp('email_verified_at')->nullable();
        });
        Schema::create('user_profile_tbl', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->boolean('profile_completed')->default(false); $t->timestamps();
        });
        foreach (['city_tbl', 'institute_tbl'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->string('name')->nullable());
        }
        Schema::table('payment_accounts', function (Blueprint $t) {
            $t->string('method'); $t->boolean('is_active')->default(true);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('name'); $t->unsignedBigInteger('role_id'); $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        foreach (['2026_04_01_191541_create_permissions_table.php', '2026_04_01_191631_create_role_permissions_table.php', '2026_09_17_000003_create_teacher_settlements.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->seed(\Database\Seeders\TeacherPermissionsSeeder::class);
        User::create(['name' => 'Admin', 'role_id' => Role::where('name', 'super_admin')->value('id')]);
        WebUser::where('id', 2)->update(['phone' => '03000000002']);
        Storage::fake('local');
    }

    private function collect(): int
    {
        $this->actingAs(WebUser::find(1), 'web_api');
        $id = $this->postJson('/web_api/auth/save-payment-request', ['payment_method' => 'teacher',
            'offered_program_id' => 1, 'collection_code' => 'COL-2'])->assertCreated()->json('payment_request.id');
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/approve', ['received_full_payment' => true])->assertOk();
        return $id;
    }

    private function settlement(int $payment, string $amount = '300.00'): array
    {
        return ['submission_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'paid_at' => today()->toDateString(),
            'allocations' => [['payment_request_id' => $payment, 'amount' => $amount]]];
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
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'suspended']);
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertCreated();
        WebUser::whereKey(2)->update(['status' => 'blocked']);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/settlements', $this->settlement($payment))->assertForbidden();
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
}
