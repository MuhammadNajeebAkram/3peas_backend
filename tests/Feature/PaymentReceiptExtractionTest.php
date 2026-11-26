<?php

namespace Tests\Feature;

use App\Exceptions\ReceiptExtractionException;
use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Services\PaymentReceiptOcrService;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PaymentReceiptExtractionTest extends TestCase
{
    private WebUser $student;

    private WebUser $inactiveStudent;

    private WebUser $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'receipt_extraction_test',
            'database.connections.receipt_extraction_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'app.env' => 'local',
            'services.receipt_extraction_ai.default_provider' => 'openai',
            'services.receipt_extraction_ai.default_model' => 'gpt-6-luna',
            'services.receipt_extraction_ai.default_enabled' => true,
            'services.receipt_extraction_ai.default_timeout' => 15,
            'services.receipt_extraction_ai.default_max_output_tokens' => 400,
            'services.ai_credentials' => [
                'AI_OPENAI_DEV_KEY' => 'test-openai-dev-key',
            ],
        ]);

        DB::purge('receipt_extraction_test');

        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('role');
            $t->string('status');
            $t->string('phone')->nullable();
            $t->string('last_token')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('subscription_payment_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('financial_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->nullable();
            $t->timestamps();
        });

        $this->student = WebUser::create([
            'name' => 'Test Student',
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->inactiveStudent = WebUser::create([
            'name' => 'Blocked Student',
            'role' => 'student',
            'status' => 'inactive',
        ]);

        $this->teacher = WebUser::create([
            'name' => 'Test Teacher',
            'role' => 'teacher',
            'status' => 'active',
        ]);

        RateLimiter::clear('receipt-test');
    }

    private function actingAsStudent(?WebUser $user = null): void
    {
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs($user ?? $this->student, 'web_api');
    }

    private function fakeJpegUpload(string $name = 'receipt.jpg'): UploadedFile
    {
        // Minimal valid JPEG SOI + APP0 header + EOI
        $jpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

        return UploadedFile::fake()->createWithContent($name, $jpegBytes);
    }

    private function fakePdfUpload(string $name = 'receipt.pdf'): UploadedFile
    {
        $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $pdfBytes);
    }

    private function mockOcrReturns(string $text): void
    {
        $mock = Mockery::mock(PaymentReceiptOcrService::class);
        $mock->shouldReceive('extractText')->andReturn($text);
        $this->app->instance(PaymentReceiptOcrService::class, $mock);
    }

    private function mockOcrThrows(ReceiptExtractionException $exception): void
    {
        $mock = Mockery::mock(PaymentReceiptOcrService::class);
        $mock->shouldReceive('extractText')->andThrow($exception);
        $this->app->instance(PaymentReceiptOcrService::class, $mock);
    }

    private function openAiSuccessBody(array $extractedJson): array
    {
        return [
            'id' => 'resp_test_123',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => json_encode($extractedJson, JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json']);

        $response->assertUnauthorized();
    }

    public function test_teacher_and_inactive_student_cannot_access_receipt_extraction(): void
    {
        $this->actingAsStudent($this->teacher);
        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsStudent($this->inactiveStudent);
        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_authenticated_student_extracts_structured_receipt_data_without_modifying_financial_tables(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns("Meezan Bank Fund Transfer\nFT26084572901\nFrom: Muhammad Ahmed\nAmount: PKR 1,300.00\nDate: 26-11-2026");

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiSuccessBody([
                'transaction_id' => 'FT26084572901',
                'sender_name' => 'Muhammad Ahmed',
                'amount' => 1300,
                'transaction_date' => '2026-11-26',
                'payment_method' => 'Bank Transfer',
                'confidence' => [
                    'transaction_id' => 0.95,
                    'sender_name' => 0.89,
                    'amount' => 0.99,
                    'transaction_date' => 0.96,
                    'payment_method' => 0.90,
                ],
            ]), 200),
        ]);

        $response = $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.transaction_id', 'FT26084572901')
            ->assertJsonPath('data.sender_name', 'Muhammad Ahmed')
            ->assertJsonPath('data.amount', 1300)
            ->assertJsonPath('data.transaction_date', '2026-11-26')
            ->assertJsonPath('data.payment_method', 'Bank Transfer')
            ->assertJsonPath('data.confidence.transaction_id', 0.95)
            ->assertJsonMissingPath('data.ocr_text')
            ->assertJsonMissingPath('ocr_text');

        Http::assertSentCount(1);
        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();
            $userText = $body['input'][0]['content'][0]['text'] ?? '';

            return $request->url() === 'https://api.openai.com/v1/responses'
                && ($body['model'] ?? null) === 'gpt-6-luna'
                && ($body['store'] ?? true) === false
                && str_contains($userText, '<untrusted_receipt_ocr_text>');
        });

        // Confirm zero financial/payment side effects
        $this->assertDatabaseCount('subscription_payment_requests', 0);
        $this->assertDatabaseCount('user_subscriptions', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_pdf_receipt_is_accepted_and_extracted(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns("JazzCash Receipt\nTID: 019283746512\nSent By: Ali Raza\nRs. 800\n2026-11-25");

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiSuccessBody([
                'transaction_id' => '019283746512',
                'sender_name' => 'Ali Raza',
                'amount' => 800,
                'transaction_date' => '2026-11-25',
                'payment_method' => 'JazzCash',
                'confidence' => [
                    'transaction_id' => 0.94,
                    'sender_name' => 0.91,
                    'amount' => 0.98,
                    'transaction_date' => 0.92,
                    'payment_method' => 0.97,
                ],
            ]), 200),
        ]);

        $response = $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakePdfUpload(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.transaction_id', '019283746512')
            ->assertJsonPath('data.payment_method', 'JazzCash');
    }

    public function test_file_validation_and_unsupported_or_disguised_files_are_rejected(): void
    {
        $this->actingAsStudent();

        // Missing file
        $this->post('/web_api/auth/payments/extract-receipt', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proof_file']);

        // Unsupported file extension (.php / .txt)
        $txtFile = UploadedFile::fake()->createWithContent('shell.txt', '<?php echo 1; ?>');
        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $txtFile,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // Disguised file with .jpg extension but invalid magic bytes
        $disguised = UploadedFile::fake()->createWithContent('fake.jpg', 'MZexecutablepayload');
        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $disguised,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // Oversized (> 5MB)
        $oversized = UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg');
        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $oversized,
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_ocr_failure_is_handled_gracefully(): void
    {
        $this->actingAsStudent();
        $this->mockOcrThrows(new ReceiptExtractionException(
            'OCR_UNAVAILABLE',
            'Automatic receipt reading is temporarily unavailable. Please enter the payment details manually.',
            503
        ));

        Http::fake();

        $response = $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'OCR_UNAVAILABLE');

        Http::assertNothingSent();
    }

    public function test_ai_api_failure_is_handled_safely_without_leaking_provider_errors(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns('Easypaisa TID 5544332211 Rs 600');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Internal provider secret detail']], 500),
        ]);

        $response = $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'AI_UNAVAILABLE');
        $this->assertStringNotContainsString('secret detail', (string) $response->json('message'));
    }

    public function test_ai_provider_rate_limit_and_missing_api_key_are_handled_safely(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns('Easypaisa TID 5544332211 Rs 600');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Rate limited']], 429),
        ]);

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'AI_RATE_LIMITED');

        config(['services.ai_credentials.AI_OPENAI_DEV_KEY' => '']);

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'PROVIDER_CREDENTIAL_MISSING');
    }

    public function test_malformed_ai_json_response_is_rejected_safely(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns('Bank Receipt FT99887766');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => '{not-valid-json'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'AI_INVALID_RESPONSE');
    }

    public function test_partial_extraction_handles_missing_sender_name_and_amount(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns('Receipt showing partial text only');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiSuccessBody([
                'transaction_id' => 'TXN-889900',
                'sender_name' => null,
                'amount' => null,
                'transaction_date' => '2026-11-20',
                'payment_method' => 'Easypaisa',
                'confidence' => [
                    'transaction_id' => 0.88,
                    'sender_name' => null,
                    'amount' => null,
                    'transaction_date' => 0.77,
                    'payment_method' => 0.91,
                ],
            ]), 200),
        ]);

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.transaction_id', 'TXN-889900')
            ->assertJsonPath('data.sender_name', null)
            ->assertJsonPath('data.amount', null)
            ->assertJsonPath('data.confidence.sender_name', null)
            ->assertJsonPath('data.confidence.amount', null);
    }

    public function test_missing_or_masked_transaction_id_and_invalid_date_are_sanitized_to_null(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns('Receipt showing masked account number');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiSuccessBody([
                'transaction_id' => '****5678',
                'sender_name' => 'Zainab Khan',
                'amount' => 1500,
                'transaction_date' => 'not-a-date',
                'payment_method' => 'Bank Transfer',
                'confidence' => [
                    'transaction_id' => 0.4,
                    'sender_name' => 0.92,
                    'amount' => 0.95,
                    'transaction_date' => 0.3,
                    'payment_method' => 0.85,
                ],
            ]), 200),
        ]);

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.transaction_id', null)
            ->assertJsonPath('data.confidence.transaction_id', null)
            ->assertJsonPath('data.sender_name', 'Zainab Khan')
            ->assertJsonPath('data.amount', 1500)
            ->assertJsonPath('data.transaction_date', null);
    }

    public function test_rate_limiting_enforces_throttle_limit(): void
    {
        $this->actingAsStudent();
        $this->mockOcrReturns('Meezan Bank FT11223344 Rs 1000');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiSuccessBody([
                'transaction_id' => 'FT11223344',
                'sender_name' => 'Bilal',
                'amount' => 1000,
                'transaction_date' => '2026-11-26',
                'payment_method' => 'Bank Transfer',
                'confidence' => [
                    'transaction_id' => 0.9,
                    'sender_name' => 0.9,
                    'amount' => 0.9,
                    'transaction_date' => 0.9,
                    'payment_method' => 0.9,
                ],
            ]), 200),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/web_api/auth/payments/extract-receipt', [
                'proof_file' => $this->fakeJpegUpload("receipt-{$i}.jpg"),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $this->post('/web_api/auth/payments/extract-receipt', [
            'proof_file' => $this->fakeJpegUpload('receipt-11.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(429);
    }
}
