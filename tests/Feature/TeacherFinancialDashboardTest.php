<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Services\TeacherSettlementService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class TeacherFinancialDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'teacher_fin_test', 'database.connections.teacher_fin_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('teacher_fin_test');
        Storage::fake('s3');
        Storage::fake('local');

        foreach (['users', 'institute_tbl', 'city_tbl'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->string('name')->nullable();
            });
        }
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('class_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('class_name')->nullable();
            $t->string('class_name_um')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('payment_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('title')->nullable();
            $t->string('method')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('display_order')->default(0);
            $t->string('account_title')->nullable();
            $t->string('account_number')->nullable();
            $t->string('iban')->nullable();
            $t->string('bank_name')->nullable();
            $t->text('instructions')->nullable();
        });
        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('role');
            $t->string('status');
            $t->string('phone')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('offered_classes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->decimal('Price', 10, 2);
            $t->decimal('discount_price', 10, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_free')->default(false);
            $t->date('session_end')->nullable();
            $t->date('session_start')->nullable();
            $t->integer('display_order')->default(0);
        });
        Schema::create('offered_programs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offered_class_id');
            $t->string('title');
            $t->boolean('is_active')->default(true);
            $t->string('slug')->nullable();
            $t->integer('display_order')->default(0);
        });

        foreach ([
            '2026_03_24_175741_create_user_subscriptions_table.php',
            '2026_03_24_200045_create_subscription_payment_requests_table.php',
            '2026_04_01_191541_create_permissions_table.php',
            '2026_04_01_191631_create_role_permissions_table.php',
            '2026_09_17_000001_create_teacher_profiles_table.php',
            '2026_09_17_000002_add_teacher_payments.php',
            '2026_09_17_000003_create_teacher_settlements.php',
            '2026_11_10_000004_add_teacher_collection_permission_to_profiles.php',
            '2026_11_10_000005_create_subscription_payment_request_events_table.php',
            '2026_11_25_102727_create_financial_ledger_and_receipts_tables.php',
            '2026_11_25_112000_add_unique_constraints_to_financial_tables.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $this->seed(\Database\Seeders\TeacherPermissionsSeeder::class);
        $this->seed(\Database\Seeders\LmsPermissionsSeeder::class);
        User::create(['name' => 'Admin', 'role_id' => Role::where('name', 'super_admin')->value('id')]);

        WebUser::create(['name' => 'Student One', 'email' => 's1@example.com', 'role' => 'student', 'status' => 'active', 'phone' => '03000000001']);
        WebUser::create(['name' => 'Teacher Primary', 'email' => 't1@example.com', 'role' => 'teacher', 'status' => 'active', 'phone' => '03000000002']);
        WebUser::create(['name' => 'Teacher Other', 'email' => 't2@example.com', 'role' => 'teacher', 'status' => 'active', 'phone' => '03000000003']);
        WebUser::create(['name' => 'Student Two', 'email' => 's2@example.com', 'role' => 'student', 'status' => 'active', 'phone' => '03000000004']);
        WebUser::create(['name' => 'Student Three', 'email' => 's3@example.com', 'role' => 'student', 'status' => 'active', 'phone' => '03000000005']);
        WebUser::create(['name' => 'Teacher Unauthorized', 'email' => 't3@example.com', 'role' => 'teacher', 'status' => 'active', 'phone' => '03000000006']);

        foreach ([2, 3] as $id) {
            $profile = TeacherProfile::create(['web_user_id' => $id]);
            $profile->forceFill([
                'status' => 'active',
                'teacher_code' => sprintf('TCH-%06d', $id),
                'collection_code' => sprintf('COL-%06d', $id),
                'can_collect_student_subscriptions' => true,
            ])->save();
        }

        $unauthProfile = TeacherProfile::create(['web_user_id' => 6]);
        $unauthProfile->forceFill([
            'status' => 'active',
            'teacher_code' => 'TCH-000006',
            'collection_code' => 'COL-000006',
            'can_collect_student_subscriptions' => false,
        ])->save();

        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => '9th Class']);
        DB::table('offered_classes')->insert([
            ['id' => 1, 'class_id' => 1, 'Price' => 1000, 'discount_price' => 800, 'session_start' => '2026-01-01', 'session_end' => '2026-12-31'],
            ['id' => 2, 'class_id' => 1, 'Price' => 1500, 'discount_price' => 1200, 'session_start' => '2026-01-01', 'session_end' => '2026-12-31'],
        ]);
        DB::table('offered_programs')->insert([
            ['id' => 1, 'offered_class_id' => 1, 'title' => 'Biology'],
            ['id' => 2, 'offered_class_id' => 2, 'title' => 'Chemistry'],
        ]);

        app('router')->aliasMiddleware('permission', \App\Http\Middleware\CheckPermission::class);
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        Storage::fake('local');
    }

    private function createTeacherPayment(int $studentId, int $programId, string $collectionCode = 'COL-000002'): int
    {
        $this->actingAs(WebUser::find($studentId), 'web_api');
        return $this->postJson('/web_api/auth/save-payment-request', [
            'payment_method' => 'teacher',
            'offered_program_id' => $programId,
            'collection_code' => $collectionCode,
        ])->assertCreated()->json('payment_request.id');
    }

    public function test_financial_calculations_confirmed_pending_rejected_admin_approved_and_multiple_events_do_not_multiply_totals(): void
    {
        // 1. Teacher-approved payment (800.00)
        $p1 = $this->createTeacherPayment(1, 1);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])->assertOk();

        // Insert extra audit events for p1 to ensure multiple events never multiply SUM/COUNT
        DB::table('subscription_payment_request_events')->insert([
            'subscription_payment_request_id' => $p1,
            'event_type' => 'approved',
            'reviewer_type' => 'teacher',
            'reviewer_id' => 2,
            'previous_status' => 'approved',
            'new_status' => 'approved',
            'created_at' => now(),
        ]);

        // 2. Admin-approved teacher-attributed payment (1200.00)
        $p2 = $this->createTeacherPayment(1, 2);
        $this->actingAs(User::first(), 'api');
        $this->postJson("/api/admin/auth/student-payment-requests/{$p2}/approve")->assertOk();

        // 3. Pending teacher-attributed payment (800.00)
        $p3 = $this->createTeacherPayment(4, 1);

        // 4. Rejected teacher-attributed payment (1200.00)
        $p4 = $this->createTeacherPayment(5, 2);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p4}/reject", ['rejection_reason' => 'Unverified cash'])->assertOk();

        // 5. Another teacher's confirmed payment (800.00) — must be excluded
        $pOther = $this->createTeacherPayment(5, 1, 'COL-000003');
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$pOther}/approve", ['received_full_payment' => true])->assertOk();

        // 6. Direct bank payment (not teacher-attributed) — must be excluded
        DB::table('subscription_payment_requests')->insert([
            'user_id' => 4,
            'offered_program_id' => 2,
            'price' => '1500.00',
            'discount_amount' => '300.00',
            'discount_percentage' => '20.00',
            'final_amount' => '1200.00',
            'payment_method' => 'bank_deposit',
            'transaction_id' => 'DIRECT-BANK-1',
            'status' => 'approved',
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify summary for Teacher 1 (WebUser 2, TeacherProfile 1)
        $this->actingAs(WebUser::find(2), 'web_api');
        $res = $this->getJson('/web_api/auth/teacher/finances/summary')->assertOk();

        $res->assertJsonPath('access.can_access', true)
            ->assertJsonPath('access.can_collect', true)
            ->assertJsonPath('access.read_only', false)
            ->assertJsonPath('summary.currency', 'PKR')
            ->assertJsonPath('summary.confirmed_collections.count', 2)
            ->assertJsonPath('summary.confirmed_collections.amount', '2000.00')
            ->assertJsonPath('summary.confirmed_collections.teacher_approved_count', 1)
            ->assertJsonPath('summary.confirmed_collections.teacher_approved_amount', '800.00')
            ->assertJsonPath('summary.confirmed_collections.admin_approved_count', 1)
            ->assertJsonPath('summary.confirmed_collections.admin_approved_amount', '1200.00')
            ->assertJsonPath('summary.pending_payments.count', 1)
            ->assertJsonPath('summary.pending_payments.amount', '800.00')
            ->assertJsonPath('summary.approved_payments.count', 2)
            ->assertJsonPath('summary.approved_payments.amount', '2000.00')
            ->assertJsonPath('summary.rejected_payments.count', 1)
            ->assertJsonPath('summary.rejected_payments.amount', '1200.00')
            ->assertJsonPath('summary.total_remitted.amount', '0.00')
            ->assertJsonPath('summary.outstanding_balance.amount', '2000.00')
            ->assertJsonPath('summary.available_to_remit.amount', '2000.00');
    }

    public function test_settlement_calculations_partial_full_multiple_allocations_and_reconciliation(): void
    {
        $p1 = $this->createTeacherPayment(1, 1); // 800.00
        $p2 = $this->createTeacherPayment(1, 2); // 1200.00
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])->assertOk();
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p2}/approve", ['received_full_payment' => true])->assertOk();

        // Partial settlement 1: 300 on p1, 500 on p2 (total 800) -> confirmed by Admin
        $s1 = $this->postJson('/web_api/auth/teacher/settlements', [
            'submission_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
            'allocations' => [
                ['payment_request_id' => $p1, 'amount' => '300.00'],
                ['payment_request_id' => $p2, 'amount' => '500.00'],
            ],
        ])->assertCreated()->json('settlement.id');

        $this->actingAs(User::first(), 'api');
        $this->postJson("/api/admin/auth/teacher-settlements/{$s1}/confirm", ['received_funds' => true])->assertOk();

        // Partial settlement 2: 200 on p1 (pending)
        $this->actingAs(WebUser::find(2), 'web_api');
        $s2 = $this->postJson('/web_api/auth/teacher/settlements', [
            'submission_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
            'allocations' => [
                ['payment_request_id' => $p1, 'amount' => '200.00'],
            ],
        ])->assertCreated()->json('settlement.id');

        // Settlement 3: 300 on p1 -> rejected by Admin (must not reduce outstanding or available)
        $s3 = $this->postJson('/web_api/auth/teacher/settlements', [
            'submission_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
            'allocations' => [
                ['payment_request_id' => $p1, 'amount' => '300.00'],
            ],
        ])->assertCreated()->json('settlement.id');

        $this->actingAs(User::first(), 'api');
        $this->postJson("/api/admin/auth/teacher-settlements/{$s3}/reject", ['rejection_reason' => 'Receipt mismatch'])->assertOk();

        // Check Teacher settlement summary & reconciliation with TeacherSettlementService and AdminTeacherController
        $this->actingAs(WebUser::find(2), 'web_api');
        $finSummary = $this->getJson('/web_api/auth/teacher/finances/settlements/summary')->assertOk()
            ->assertJsonPath('settlement_summary.confirmed_collections.amount', '2000.00')
            ->assertJsonPath('settlement_summary.confirmed_remittances.count', 1)
            ->assertJsonPath('settlement_summary.confirmed_remittances.amount', '800.00')
            ->assertJsonPath('settlement_summary.pending_remittances.count', 1)
            ->assertJsonPath('settlement_summary.pending_remittances.amount', '200.00')
            ->assertJsonPath('settlement_summary.rejected_remittances.count', 1)
            ->assertJsonPath('settlement_summary.rejected_remittances.amount', '300.00')
            ->assertJsonPath('settlement_summary.outstanding_balance.amount', '1200.00')
            ->assertJsonPath('settlement_summary.available_to_remit.amount', '1000.00')
            ->json('settlement_summary');

        $serviceSummary = app(TeacherSettlementService::class)->summary(1);
        $this->assertSame($serviceSummary['collected'], $finSummary['collected']);
        $this->assertSame($serviceSummary['settled'], $finSummary['settled']);
        $this->assertSame($serviceSummary['outstanding'], $finSummary['outstanding']);
        $this->assertSame($serviceSummary['pending_settlement'], $finSummary['pending_settlement']);
        $this->assertSame($serviceSummary['available_to_settle'], $finSummary['available_to_settle']);

        // Check Admin teacher show endpoint reconciles with TeacherFinancialReportService
        $this->actingAs(User::first(), 'api');
        $this->getJson('/api/admin/auth/teachers/1')->assertOk()
            ->assertJsonPath('summary.collected', '2000.00')
            ->assertJsonPath('summary.settled', '800.00')
            ->assertJsonPath('summary.outstanding', '1200.00')
            ->assertJsonPath('financial_summary.available_to_remit.amount', '1000.00');

        // Check per-payment allocation breakdown in /teacher/finances/payments/{id}
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson("/web_api/auth/teacher/finances/payments/{$p1}")->assertOk()
            ->assertJsonPath('payment_request.final_amount', '800.00')
            ->assertJsonPath('payment_request.settled_amount', '300.00')
            ->assertJsonPath('payment_request.pending_settlement_amount', '200.00')
            ->assertJsonPath('payment_request.available_to_remit_amount', '300.00');
    }

    public function test_authorization_active_revoked_historical_unauthorized_and_cross_teacher_isolation(): void
    {
        // Teacher 1 collects 1 payment
        $p1 = $this->createTeacherPayment(1, 1);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])->assertOk();

        // Teacher 2 collects 1 payment
        $p2 = $this->createTeacherPayment(4, 1, 'COL-000003');
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p2}/approve", ['received_full_payment' => true])->assertOk();

        // Revoke Teacher 1's collection permission -> still has historical read-only access
        TeacherProfile::where('web_user_id', 2)->update(['can_collect_student_subscriptions' => false]);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/finances/summary')->assertOk()
            ->assertJsonPath('access.can_access', true)
            ->assertJsonPath('access.can_collect', false)
            ->assertJsonPath('access.read_only', true)
            ->assertJsonPath('access.has_historical_records', true)
            ->assertJsonPath('summary.confirmed_collections.amount', '800.00');

        // Teacher 1 cannot view Teacher 2's payment detail
        $this->getJson("/web_api/auth/teacher/finances/payments/{$p2}")->assertNotFound();

        // Teacher 1 cannot pass teacher_id or teacher_profile_id to spoof ownership
        $this->getJson('/web_api/auth/teacher/finances/summary?teacher_id=2')->assertStatus(422);
        $this->getJson('/web_api/auth/teacher/finances/export?teacher_profile_id=2')->assertStatus(422);

        // Teacher 3 (WebUser 6) who never had collection permission and has no historical records is blocked (403)
        // Even if code_assigned / status_changed events exist, that alone does not grant collection financial access
        DB::table('teacher_profile_events')->insert([
            'teacher_profile_id' => 3,
            'admin_id' => 1,
            'action' => 'code_assigned',
            'collection_code' => 'COL-000006',
            'created_at' => now(),
        ]);
        $this->actingAs(WebUser::find(6), 'web_api');
        $this->getJson('/web_api/auth/teacher/finances/summary')->assertForbidden();
        $this->getJson('/web_api/auth/teacher/finances/payments')->assertForbidden();
        $this->getJson('/web_api/auth/teacher/finances/export')->assertForbidden();

        // If Teacher 3 previously had collection permission enabled and then disabled, historical read-only access is granted
        DB::table('teacher_profile_events')->insert([
            'teacher_profile_id' => 3,
            'admin_id' => 1,
            'action' => 'collection_permission_disabled',
            'details' => json_encode(['reason' => 'Revoked after audit']),
            'created_at' => now(),
        ]);
        $this->getJson('/web_api/auth/teacher/finances/summary')->assertOk()
            ->assertJsonPath('access.can_access', true)
            ->assertJsonPath('access.can_collect', false)
            ->assertJsonPath('access.read_only', true)
            ->assertJsonPath('access.has_historical_records', true);

        // Student (WebUser 1) is blocked (403)
        $this->actingAs(WebUser::find(1), 'web_api');
        $this->getJson('/web_api/auth/teacher/finances/summary')->assertForbidden();
    }

    public function test_date_wise_report_and_chart_grouping_by_day_week_month_and_reconciliation(): void
    {
        $p1 = $this->createTeacherPayment(1, 1); // 800.00
        $p2 = $this->createTeacherPayment(1, 2); // 1200.00
        $p3 = $this->createTeacherPayment(4, 1); // 800.00 (pending)

        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])->assertOk();
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p2}/approve", ['received_full_payment' => true])->assertOk();

        // Set deterministic dates across two different months
        SubscriptionPaymentRequest::whereKey($p1)->update([
            'created_at' => Carbon::parse('2026-09-10 10:00:00'),
            'confirmed_at' => Carbon::parse('2026-09-10 12:00:00'),
        ]);
        SubscriptionPaymentRequest::whereKey($p2)->update([
            'created_at' => Carbon::parse('2026-10-15 10:00:00'),
            'confirmed_at' => Carbon::parse('2026-10-15 14:00:00'),
        ]);
        SubscriptionPaymentRequest::whereKey($p3)->update([
            'created_at' => Carbon::parse('2026-10-15 16:00:00'),
        ]);

        // Daily chart
        $chartDay = $this->getJson('/web_api/auth/teacher/finances/chart?group_by=day')->assertOk()
            ->assertJsonPath('chart.total_confirmed_count', 2)
            ->assertJsonPath('chart.total_confirmed_amount', '2000.00')
            ->assertJsonCount(2, 'chart.series')
            ->assertJsonPath('chart.series.0.period', '2026-09-10')
            ->assertJsonPath('chart.series.0.confirmed_amount', '800.00')
            ->assertJsonPath('chart.series.1.period', '2026-10-15')
            ->assertJsonPath('chart.series.1.confirmed_amount', '1200.00');

        // Weekly chart
        $this->getJson('/web_api/auth/teacher/finances/chart?group_by=week')->assertOk()
            ->assertJsonPath('chart.total_confirmed_amount', '2000.00')
            ->assertJsonCount(2, 'chart.series');

        // Monthly report
        $this->getJson('/web_api/auth/teacher/finances/report?group_by=month')->assertOk()
            ->assertJsonPath('report.totals.total_payment_requests', 3)
            ->assertJsonPath('report.totals.confirmed_payment_count', 2)
            ->assertJsonPath('report.totals.confirmed_collection_amount', '2000.00')
            ->assertJsonPath('report.totals.pending_request_count', 1)
            ->assertJsonPath('report.totals.pending_request_amount', '800.00')
            ->assertJsonPath('report.rows.0.period', '2026-09')
            ->assertJsonPath('report.rows.0.confirmed_collection_amount', '800.00')
            ->assertJsonPath('report.rows.1.period', '2026-10')
            ->assertJsonPath('report.rows.1.confirmed_collection_amount', '1200.00');

        // Date-range filtered report (October only)
        $this->getJson('/web_api/auth/teacher/finances/report?from=2026-10-01&to=2026-10-31&group_by=day')->assertOk()
            ->assertJsonPath('report.totals.confirmed_collection_amount', '1200.00')
            ->assertJsonPath('report.totals.pending_request_count', 1);
    }

    public function test_filtering_and_pagination_on_payments_and_settlements(): void
    {
        $p1 = $this->createTeacherPayment(1, 1); // Biology, 800
        $p2 = $this->createTeacherPayment(1, 2); // Chemistry, 1200
        $p3 = $this->createTeacherPayment(4, 1); // Biology, 800 (pending)

        $this->actingAs(WebUser::find(2), 'web_api');
        $r1 = $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])->assertOk()->json('payment_request.receipt_number');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p2}/approve", ['received_full_payment' => true])->assertOk();

        // Filter by status=approved
        $this->getJson('/web_api/auth/teacher/finances/payments?status=approved')->assertOk()
            ->assertJsonPath('payments.total', 2);

        // Filter by status=pending
        $this->getJson('/web_api/auth/teacher/finances/payments?status=pending')->assertOk()
            ->assertJsonPath('payments.total', 1)
            ->assertJsonPath('payments.data.0.id', $p3);

        // Filter by offered_program_id=2
        $this->getJson('/web_api/auth/teacher/finances/payments?offered_program_id=2')->assertOk()
            ->assertJsonPath('payments.total', 1)
            ->assertJsonPath('payments.data.0.id', $p2);

        // Filter by receipt_number
        $this->getJson('/web_api/auth/teacher/finances/payments?receipt_number='.$r1)->assertOk()
            ->assertJsonPath('payments.total', 1)
            ->assertJsonPath('payments.data.0.id', $p1);

        // Pagination (per_page=1)
        $this->getJson('/web_api/auth/teacher/finances/payments?per_page=1&page=1')->assertOk()
            ->assertJsonPath('payments.per_page', 1)
            ->assertJsonPath('payments.total', 3)
            ->assertJsonCount(1, 'payments.data');
    }

    public function test_csv_xlsx_and_pdf_exports_succeed_honor_filters_and_never_leak_storage_paths(): void
    {
        $p1 = $this->createTeacherPayment(1, 1); // 800.00
        $this->actingAs(WebUser::find(2), 'web_api');
        $receipt = $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])
            ->assertOk()
            ->json('payment_request.receipt_number');

        SubscriptionPaymentRequest::whereKey($p1)->update(['proof_file_path' => 'secret-s3-bucket/private-slip-123.png']);

        $this->postJson('/web_api/auth/teacher/settlements', [
            'submission_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
            'allocations' => [['payment_request_id' => $p1, 'amount' => '300.00']],
        ])->assertCreated();

        // 1. CSV Export
        $csvRes = $this->get('/web_api/auth/teacher/finances/export?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csvRes->headers->get('Content-Type'));
        $csvContent = $csvRes->getContent();
        $this->assertStringContainsString('THE STUDENTS TIMES - TEACHER FINANCIAL STATEMENT', $csvContent);
        $this->assertStringContainsString('Teacher Primary', $csvContent);
        $this->assertStringContainsString('TCH-000002', $csvContent);
        $this->assertStringContainsString($receipt, $csvContent);
        $this->assertStringContainsString('800.00', $csvContent);
        $this->assertStringNotContainsString('secret-s3-bucket', $csvContent);

        // 2. XLSX Export
        $xlsxRes = $this->get('/web_api/auth/teacher/finances/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $xlsxRes->headers->get('Content-Type'));
        $tmpXlsx = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        file_put_contents($tmpXlsx, $xlsxRes->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmpXlsx) === true);
        $sheet1Xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet2Xml = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $sheet3Xml = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
        $zip->close();
        @unlink($tmpXlsx);

        $this->assertStringContainsString('Total Confirmed Collections', $sheet1Xml);
        $this->assertStringContainsString('800.00', $sheet1Xml);
        $this->assertStringContainsString($receipt, $sheet2Xml);
        $this->assertStringContainsString('SET-000001', $sheet3Xml);
        $this->assertStringNotContainsString('secret-s3-bucket', $sheet2Xml);

        // 3. PDF Export
        $pdfRes = $this->get('/web_api/auth/teacher/finances/export?format=pdf')->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfRes->headers->get('Content-Type'));
        $pdfContent = $pdfRes->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfContent);
        $this->assertStringContainsString('THE STUDENTS TIMES - TEACHER FINANCIAL STATEMENT', $pdfContent);
        $this->assertStringContainsString('TCH-000002', $pdfContent);
        $this->assertStringContainsString('800.00', $pdfContent);
        $this->assertStringNotContainsString('secret-s3-bucket', $pdfContent);
    }

    public function test_stage_d_admin_teacher_scoped_payments_settlements_proofs_permissions_and_cross_teacher_isolation(): void
    {
        Storage::fake('s3');
        Storage::fake('local');

        // Create two restricted admin roles:
        // 1. teacherViewRole -> has ONLY teachers.view (no teacher-settlements.view or student-payment-requests.view)
        // 2. noPermRole -> has no teacher permissions
        $teacherViewRole = Role::create(['name' => 'teacher_auditor']);
        $teachersViewPermId = Permission::where('name', 'teachers.view')->value('id');
        DB::table('role_permissions')->insert([
            'role_id' => $teacherViewRole->id,
            'permission_id' => $teachersViewPermId,
        ]);
        $teacherAuditor = User::create(['name' => 'Teacher Auditor', 'role_id' => $teacherViewRole->id]);

        $noPermRole = Role::create(['name' => 'restricted_staff']);
        $restrictedAdmin = User::create(['name' => 'Restricted Admin', 'role_id' => $noPermRole->id]);

        // Teacher 1 (Profile ID 1, WebUser 2) payments: approved (p1), pending (p2), rejected (p3)
        $p1 = $this->createTeacherPayment(1, 1, 'COL-000002'); // 800.00
        $p2 = $this->createTeacherPayment(4, 1, 'COL-000002'); // 800.00 pending
        $p3 = $this->createTeacherPayment(5, 2, 'COL-000002'); // 1200.00 rejected

        $this->actingAs(WebUser::find(2), 'web_api');
        $receipt1 = $this->postJson("/web_api/auth/teacher/payment-requests/{$p1}/approve", ['received_full_payment' => true])
            ->assertOk()
            ->json('payment_request.receipt_number');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$p3}/reject", ['rejection_reason' => 'Incomplete cash'])
            ->assertOk();

        // Attach S3 proof to p1
        Storage::disk('s3')->put('student-proofs/t1-p1.png', 'teacher-1-payment-proof-bytes');
        SubscriptionPaymentRequest::whereKey($p1)->update(['proof_file_path' => 'student-proofs/t1-p1.png']);

        // Teacher 1 submits settlement with proof
        DB::table('payment_accounts')->insert(['id' => 1, 'title' => 'HBL', 'method' => 'bank_deposit', 'is_active' => true]);
        $s1 = $this->postJson('/web_api/auth/teacher/settlements', [
            'submission_key' => (string) Str::uuid(),
            'payment_method' => 'bank',
            'payment_account_id' => 1,
            'transaction_reference' => 'HBL-STAGE-D-1',
            'paid_at' => '2026-10-15',
            'notes' => 'Stage D test remittance',
            'proof_file' => UploadedFile::fake()->create('settlement1.pdf', 12, 'application/pdf'),
            'allocations' => [['payment_request_id' => $p1, 'amount' => '300.00']],
        ])->assertCreated()->json('settlement.id');

        // Teacher 2 (Profile ID 2, WebUser 3) payment & settlement (for cross-teacher isolation test)
        $pOther = $this->createTeacherPayment(5, 1, 'COL-000003');
        $this->actingAs(WebUser::find(3), 'web_api');
        $this->postJson("/web_api/auth/teacher/payment-requests/{$pOther}/approve", ['received_full_payment' => true])->assertOk();
        Storage::disk('s3')->put('student-proofs/t2-other.png', 'teacher-2-payment-proof-bytes');
        SubscriptionPaymentRequest::whereKey($pOther)->update(['proof_file_path' => 'student-proofs/t2-other.png']);

        $sOther = $this->postJson('/web_api/auth/teacher/settlements', [
            'submission_key' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'paid_at' => '2026-10-16',
            'proof_file' => UploadedFile::fake()->create('settlement2.pdf', 12, 'application/pdf'),
            'allocations' => [['payment_request_id' => $pOther, 'amount' => '200.00']],
        ])->assertCreated()->json('settlement.id');

        // 1. Admin WITH teachers.view can access teacher-scoped payments, detail, settlements, and proofs
        $this->actingAs($teacherAuditor, 'api');

        $paymentsRes = $this->getJson('/api/admin/auth/teachers/1/payments')->assertOk()
            ->assertJsonPath('summary.confirmed_collections.amount', '800.00')
            ->assertJsonPath('summary.pending_payments.count', 1)
            ->assertJsonPath('summary.rejected_payments.count', 1)
            ->assertJsonPath('payments.total', 3);

        // Filter teacher-scoped payments by status, receipt_number, and pagination
        $this->getJson('/api/admin/auth/teachers/1/payments?status=approved')->assertOk()
            ->assertJsonPath('payments.total', 1)
            ->assertJsonPath('payments.data.0.id', $p1)
            ->assertJsonPath('payments.data.0.price', '1000.00')
            ->assertJsonPath('payments.data.0.discount_amount', '200.00')
            ->assertJsonPath('payments.data.0.final_amount', '800.00')
            ->assertJsonPath('payments.data.0.reviewer_type', 'teacher')
            ->assertJsonMissingPath('payments.data.0.proof_file_path');

        $this->getJson('/api/admin/auth/teachers/1/payments?reference='.$receipt1)->assertOk()
            ->assertJsonPath('payments.total', 1)
            ->assertJsonPath('payments.data.0.id', $p1);

        // Payment detail for Teacher 1
        $detailRes = $this->getJson("/api/admin/auth/teachers/1/payments/{$p1}")->assertOk()
            ->assertJsonPath('payment_request.id', $p1)
            ->assertJsonPath('payment_request.receipt_number', $receipt1)
            ->assertJsonPath('payment_request.price', '1000.00')
            ->assertJsonPath('payment_request.discount_amount', '200.00')
            ->assertJsonPath('payment_request.final_amount', '800.00')
            ->assertJsonPath('payment_request.has_proof', true)
            ->assertJsonMissingPath('payment_request.proof_file_path');
        $this->assertStringEndsWith("/api/admin/auth/teachers/1/payments/{$p1}/proof", (string) $detailRes->json('payment_request.proof_endpoint'));

        // Download Teacher 1's payment evidence via teacher-scoped endpoint
        $proofResp = $this->get("/api/admin/auth/teachers/1/payments/{$p1}/proof")->assertOk();
        $this->assertSame('teacher-1-payment-proof-bytes', $proofResp->getContent());

        // Settlements for Teacher 1 + date/status filtering
        $this->getJson('/api/admin/auth/teachers/1/settlements?status=pending&from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertJsonPath('settlement_summary.pending_remittances.amount', '300.00')
            ->assertJsonPath('settlements.total', 1)
            ->assertJsonPath('settlements.data.0.id', $s1)
            ->assertJsonPath('settlements.data.0.transaction_reference', 'HBL-STAGE-D-1')
            ->assertJsonPath('settlements.data.0.notes', 'Stage D test remittance')
            ->assertJsonMissingPath('settlements.data.0.proof_path');

        // Download Teacher 1's settlement evidence via teacher-scoped endpoint
        $this->get("/api/admin/auth/teachers/1/settlements/{$s1}/proof")->assertOk();

        // 2. Cross-teacher isolation: Teacher 1 endpoints MUST return 404 for Teacher 2's payment/settlement IDs
        $this->getJson("/api/admin/auth/teachers/1/payments/{$pOther}")->assertNotFound();
        $this->get("/api/admin/auth/teachers/1/payments/{$pOther}/proof")->assertNotFound();
        $this->get("/api/admin/auth/teachers/1/settlements/{$sOther}/proof")->assertNotFound();

        // 3. Global proof endpoints remain strictly protected (teacherAuditor without global permissions gets 403)
        $this->get("/api/admin/auth/student-payment-requests/{$p1}/proof")->assertForbidden();
        $this->get("/api/admin/auth/teacher-settlements/{$s1}/proof")->assertForbidden();

        // 4. Admin WITHOUT teachers.view receives 403 on all teacher-scoped endpoints
        $this->actingAs($restrictedAdmin, 'api');
        $this->getJson('/api/admin/auth/teachers/1/payments')->assertForbidden();
        $this->getJson("/api/admin/auth/teachers/1/payments/{$p1}")->assertForbidden();
        $this->get("/api/admin/auth/teachers/1/payments/{$p1}/proof")->assertForbidden();
        $this->getJson('/api/admin/auth/teachers/1/settlements')->assertForbidden();
        $this->get("/api/admin/auth/teachers/1/settlements/{$s1}/proof")->assertForbidden();

        // 5. Super Admin can use exact teacher_profile_id on student-payment-requests and date filters on teacher-settlements
        $this->actingAs(User::first(), 'api');
        $this->getJson('/api/admin/auth/student-payment-requests?teacher_profile_id=1')->assertOk()
            ->assertJsonPath('total', 3);
        $this->getJson('/api/admin/auth/teacher-settlements?teacher_profile_id=1&from=2026-10-15&to=2026-10-15')->assertOk()
            ->assertJsonPath('total', 1);
    }
}
