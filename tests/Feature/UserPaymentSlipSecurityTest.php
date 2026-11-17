<?php

namespace Tests\Feature;

use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserPaymentSlipSecurityTest extends TestCase
{
    private WebUser $student;
    private string $password = 'SensitiveStudentPass123!';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'payment_slip_test',
            'database.connections.payment_slip_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('payment_slip_test');

        $this->createTestTables();
        $this->setupStudent();
    }

    private function createTestTables(): void
    {
        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('student');
            $t->string('status')->default('active');
            $t->string('phone')->nullable();
            $t->string('last_token')->nullable();
            $t->unsignedBigInteger('study_session_id')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('user_profile_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('curriculum_board_id')->nullable();
            $t->unsignedBigInteger('study_plan_id')->nullable();
            $t->unsignedBigInteger('study_group_id')->nullable();
            $t->timestamps();
        });

        Schema::create('user_payment_slip_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->unsignedBigInteger('bank_account_id')->default(1);
            $t->string('transaction_id')->nullable();
            $t->decimal('amount', 10, 2)->nullable();
            $t->boolean('activate')->default(false);
            $t->timestamps();
        });

        Schema::create('study_plan_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('session_id')->nullable();
            $t->string('name')->nullable();
            $t->decimal('price', 10, 2)->default(0.00);
            $t->timestamps();
        });

        Schema::create('user_study_plan_tbl', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('study_plan_id');
            $t->integer('qty')->default(1);
            $t->decimal('price', 10, 2)->nullable();
            $t->timestamps();
        });
    }

    private function setupStudent(): void
    {
        $this->student = WebUser::create([
            'name' => 'Alice Slip Student',
            'email' => 'alice.slip@example.com',
            'password' => Hash::make($this->password),
            'role' => 'student',
            'status' => 'active',
            'phone' => '03001234567',
        ]);

        DB::table('user_profile_tbl')->insert([
            'user_id' => $this->student->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('study_plan_tbl')->insert([
            'id' => 1,
            'session_id' => 10,
            'name' => 'Annual Matric Plan',
            'price' => 5000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Requirement: Successful payment slip upload behaves correctly.
     */
    public function test_successful_payment_slip_upload(): void
    {
        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/upload_deposit_slip', [
                'class_id' => 1,
                'curriculum_board_id' => 1,
                'study_plan' => 1,
                'study_group' => 1,
                'name' => 'Alice Deposit Slip',
                'bank_account_id' => 1,
                'transaction_id' => 'TXN-998877',
                'price' => 5000,
                'is_trial' => false,
            ]);

        $response->assertOk();
        $response->assertJson([
            'success' => 1,
            'message' => 'Payment slip uploaded successfully.',
        ]);

        $this->assertDatabaseHas('user_payment_slip_tbl', [
            'user_id' => $this->student->id,
            'transaction_id' => 'TXN-998877',
        ]);
    }

    /**
     * Requirement: Deliberate failure during upload MUST NEVER expose User model, password hashes, or PII.
     */
    public function test_failure_response_does_not_leak_user_object_password_hash_or_pii(): void
    {
        // Deliberately trigger a database exception during transaction (e.g. drop user_study_plan_tbl)
        Schema::drop('user_study_plan_tbl');

        $response = $this->actingAs($this->student, 'web_api')
            ->postJson('/web_api/upload_deposit_slip', [
                'class_id' => 1,
                'curriculum_board_id' => 1,
                'study_plan' => 1,
                'study_group' => 1,
                'name' => 'Alice Deposit Slip Fail',
                'bank_account_id' => 1,
                'transaction_id' => 'TXN-FAIL-001',
                'price' => 5000,
                'is_trial' => false,
            ]);

        $response->assertStatus(500);

        $json = $response->json();
        $rawContent = $response->getContent();

        // 1. Must NOT contain the 'user' key
        $this->assertArrayNotHasKey('user', $json, 'Error response must NOT contain the user object.');

        // 2. Must NOT contain bcrypt password hash ($2y$)
        $this->assertStringNotContainsString('$2y$', $rawContent, 'Error response must not expose bcrypt password hashes.');
        $this->assertStringNotContainsString('$2a$', $rawContent);

        // 3. Must NOT expose sensitive student personal information
        $this->assertStringNotContainsString($this->student->email, $rawContent, 'Error response must not leak student email.');
        $this->assertStringNotContainsString('03001234567', $rawContent, 'Error response must not leak student phone.');

        // 4. Must NOT expose raw database table names or SQL syntax in error message
        $this->assertStringNotContainsString('user_study_plan_tbl', $rawContent, 'Raw database table names must not be leaked.');
        $this->assertStringNotContainsString('SQLSTATE', $rawContent, 'SQL exception states must not be returned to clients.');

        // 5. Must return safe user-friendly message
        $this->assertSame(-1, $json['success']);
        $this->assertSame('Payment slip upload failed. Please try again later.', $json['message']);
    }

    /**
     * Requirement: Unauthenticated payment slip upload returns 401 without processing.
     */
    public function test_unauthenticated_payment_slip_upload_returns_401(): void
    {
        $response = $this->postJson('/web_api/upload_deposit_slip', [
            'name' => 'Anonymous User',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Requirement: Deposit slip image upload failure handles errors safely without leaking internal paths.
     */
    public function test_deposit_slip_image_upload_failure_does_not_leak_internal_paths_or_credentials(): void
    {
        Storage::shouldReceive('disk->putFileAs')
            ->andThrow(new \Exception('S3 Connection Timeout to pakistancdn-internal.s3.amazonaws.com with key AKIAIOSFODNN7EXAMPLE'));

        $file = UploadedFile::fake()->image('slip.jpg', 400, 400);

        $response = $this->actingAs($this->student, 'web_api')
            ->post('/web_api/upload-image-to-s3', [
                'deposit_slip' => $file,
            ]);

        $response->assertStatus(500);
        $raw = $response->getContent();

        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $raw);
        $this->assertStringNotContainsString('s3.amazonaws.com', $raw);
        $this->assertStringNotContainsString('Exception', $raw);

        $response->assertJson([
            'success' => 0,
            'message' => 'Failed to upload deposit slip to storage.',
        ]);
    }
}
