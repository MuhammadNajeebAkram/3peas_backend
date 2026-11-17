<?php

namespace Tests\Feature;

use App\Http\Controllers\QuestionsController;
use App\Http\Middleware\CheckPermission;
use App\Models\Role;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GetTestSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'get_test_security_test',
            'database.connections.get_test_security_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('get_test_security_test');

        Schema::create('exam_question_tbl', function (Blueprint $table) {
            $table->id();
            $table->text('question')->nullable();
            $table->text('question_um')->nullable();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('web_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('user_type')->default('student');
            $table->timestamps();
        });

        DB::table('exam_question_tbl')->insert([
            'id' => 26,
            'question' => 'What is velocity?',
            'question_um' => 'رفتار کیا ہے؟',
        ]);
    }

    public function test_successful_retrieval_returns_exact_response_structure(): void
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/get_test', 'GET');

        $response = $controller->getTest($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertIsArray($data['question']);
        $this->assertCount(1, $data['question']);
        $this->assertSame('رفتار کیا ہے؟', $data['question'][0]['question_um']);
    }

    public function test_database_exception_returns_500_and_masks_sensitive_details(): void
    {
        // Drop the table to force a PDO/database exception
        Schema::dropIfExists('exam_question_tbl');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'QuestionsController@getTest failed:')
                    && isset($context['exception'])
                    && $context['exception'] instanceof \Throwable;
            });

        $controller = new QuestionsController();
        $request = Request::create('/api/get_test', 'GET');

        $response = $controller->getTest($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertSame('An error occurred while retrieving test question.', $data['message']);

        // Must not expose raw error key or internal database details
        $this->assertArrayNotHasKey('error', $data);

        $rawBody = (string) $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $rawBody);
        $this->assertStringNotContainsStringIgnoringCase('SELECT', $rawBody);
        $this->assertStringNotContainsStringIgnoringCase('exam_question_tbl', $rawBody);
        $this->assertStringNotContainsStringIgnoringCase('sqlite', $rawBody);
        $this->assertStringNotContainsStringIgnoringCase('table', $rawBody);
    }

    public function test_route_level_protection_requires_questions_view_permission(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/get_test', 'GET'));
        $this->assertContains('permission:questions.view', $route->gatherMiddleware());

        $middleware = new CheckPermission();

        // 1. Unauthenticated request -> 401
        $unauthRequest = Request::create('/api/get_test', 'GET');
        $unauthResponse = $middleware->handle($unauthRequest, fn () => response()->json(['success' => true]), 'questions.view');
        $this->assertSame(401, $unauthResponse->getStatusCode());

        // 2. Student (WebUser) -> 403
        $student = new WebUser(['name' => 'Student']);
        $studentRequest = Request::create('/api/get_test', 'GET');
        $studentRequest->setUserResolver(fn ($guard = null) => $student);
        $studentResponse = $middleware->handle($studentRequest, fn () => response()->json(['success' => true]), 'questions.view');
        $this->assertSame(403, $studentResponse->getStatusCode());

        // 3. Admin without questions.view -> 403
        $roleWithoutPerm = Role::create(['name' => 'editor', 'display_name' => 'Editor']);
        $adminWithoutPerm = User::create([
            'name' => 'Editor Admin',
            'email' => 'editor@test.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleWithoutPerm->id,
            'is_active' => 1,
        ]);
        $noPermRequest = Request::create('/api/get_test', 'GET');
        $noPermRequest->setUserResolver(fn ($guard = null) => $adminWithoutPerm);
        $noPermResponse = $middleware->handle($noPermRequest, fn () => response()->json(['success' => true]), 'questions.view');
        $this->assertSame(403, $noPermResponse->getStatusCode());

        // 4. Admin with questions.view -> 200
        $perm = DB::table('permissions')->insertGetId(['name' => 'questions.view', 'created_at' => now(), 'updated_at' => now()]);
        $roleWithPerm = Role::create(['name' => 'viewer', 'display_name' => 'Viewer']);
        DB::table('role_permissions')->insert(['role_id' => $roleWithPerm->id, 'permission_id' => $perm]);
        $adminWithPerm = User::create([
            'name' => 'Viewer Admin',
            'email' => 'viewer@test.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleWithPerm->id,
            'is_active' => 1,
        ]);
        $withPermRequest = Request::create('/api/get_test', 'GET');
        $withPermRequest->setUserResolver(fn ($guard = null) => $adminWithPerm);
        $withPermResponse = $middleware->handle($withPermRequest, fn () => response()->json(['success' => true]), 'questions.view');
        $this->assertSame(200, $withPermResponse->getStatusCode());
    }
}
