<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'teacher_payment_test', 'database.connections.teacher_payment_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('teacher_payment_test');
        foreach (['users', 'institute_tbl', 'city_tbl', 'payment_accounts'] as $name) {
            Schema::create($name, fn (Blueprint $t) => $t->id());
        }
        Schema::create('web_users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('role'); $t->string('status'); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('offered_classes', function (Blueprint $t) {
            $t->id(); $t->decimal('Price', 10, 2); $t->decimal('discount_price', 10, 2)->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_free')->default(false); $t->date('session_end')->nullable();
        });
        Schema::create('offered_programs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('offered_class_id'); $t->string('title'); $t->boolean('is_active')->default(true);
        });
        foreach (['2026_03_24_175741_create_user_subscriptions_table.php', '2026_03_24_200045_create_subscription_payment_requests_table.php',
            '2026_09_17_000001_create_teacher_profiles_table.php', '2026_09_17_000002_add_teacher_payments.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        WebUser::create(['name' => 'Student', 'role' => 'student', 'status' => 'active']);
        WebUser::create(['name' => 'Teacher', 'role' => 'teacher', 'status' => 'active']);
        WebUser::create(['name' => 'Other teacher', 'role' => 'teacher', 'status' => 'active']);
        foreach ([2, 3] as $id) {
            $profile = TeacherProfile::create(['web_user_id' => $id]);
            $profile->forceFill(['status' => 'active', 'collection_code' => 'COL-'.$id])->save();
        }
        DB::table('offered_classes')->insert(['id' => 1, 'Price' => 1000, 'discount_price' => 800]);
        DB::table('offered_programs')->insert(['id' => 1, 'offered_class_id' => 1, 'title' => 'Biology']);
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs(WebUser::find(1), 'web_api');
    }

    private function submit()
    {
        return $this->postJson('/web_api/auth/save-payment-request', [
            'payment_method' => 'teacher', 'offered_program_id' => 1, 'collection_code' => 'COL-2', 'amount' => 1,
        ]);
    }

    public function test_server_price_and_idempotent_approval(): void
    {
        $id = $this->submit()->assertCreated()->assertJsonPath('payment_request.final_amount', '800.00')->json('payment_request.id');
        $this->assertDatabaseCount('user_subscriptions', 0);
        $this->submit()->assertStatus(409);
        $this->actingAs(WebUser::find(2), 'web_api');
        $url = '/web_api/auth/teacher/payment-requests/'.$id.'/approve';
        $this->postJson($url, [])->assertUnprocessable();
        $receipt = $this->postJson($url, ['received_full_payment' => true])->assertOk()->json('payment_request.receipt_number');
        $this->postJson($url, ['received_full_payment' => true])->assertOk()->assertJsonPath('payment_request.receipt_number', $receipt);
        $this->assertDatabaseCount('user_subscriptions', 1);
        $this->assertDatabaseHas('user_subscriptions', ['user_id' => 1, 'status' => 'active', 'price_paid' => 800, 'approved_by' => null]);
        $this->assertDatabaseHas('subscription_payment_requests', ['id' => $id, 'confirmed_by_web_user_id' => 2]);
    }

    public function test_other_teacher_and_students_cannot_confirm_or_view_requests(): void
    {
        $id = $this->submit()->assertCreated()->json('payment_request.id');
        $url = '/web_api/auth/teacher/payment-requests/'.$id.'/approve';
        $this->postJson($url, ['received_full_payment' => true])->assertForbidden();
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->getJson('/web_api/auth/teacher/payment-requests')->assertOk()->assertJsonPath('total', 0);
        $this->postJson($url, ['received_full_payment' => true])->assertNotFound();
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/payment-requests')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.user.email');
    }

    public function test_suspension_blocks_submission_and_approval(): void
    {
        $id = $this->submit()->assertCreated()->json('payment_request.id');
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'suspended']);
        $this->submit()->assertUnprocessable();
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/approve', ['received_full_payment' => true])->assertForbidden();
        $this->assertDatabaseCount('user_subscriptions', 0);
    }

    public function test_rejected_request_cannot_activate_subscription(): void
    {
        $id = $this->submit()->assertCreated()->json('payment_request.id');
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/reject', ['rejection_reason' => 'No payment received'])->assertOk();
        $this->postJson('/web_api/auth/teacher/payment-requests/'.$id.'/approve', ['received_full_payment' => true])->assertStatus(409);
        $this->assertDatabaseCount('user_subscriptions', 0);
        $this->actingAs(WebUser::find(1), 'web_api');
        $this->getJson('/web_api/auth/payments/teacher/requests')->assertOk()->assertJsonPath('data.0.status', 'rejected');
    }
}
