<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Authentication\WebUserAuthController;
use App\Http\Services\Authentication\WebUserAuthService;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class TeacherGoogleLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'google_test', 'database.connections.google_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('google_test');
        Schema::create('web_users', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'email', 'password', 'phone', 'role', 'google_id', 'avatar', 'login_provider', 'status'] as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_profile_tbl', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('preferred_language')->nullable();
            $table->boolean('profile_completed')->default(false);
            $table->timestamps();
        });
        foreach (['users', 'institute_tbl', 'city_tbl'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
        (require database_path('migrations/2026_09_17_000001_create_teacher_profiles_table.php'))->up();
    }

    private function service(bool $verified = true): WebUserAuthService
    {
        return new class($verified) extends WebUserAuthService {
            public function __construct(private bool $verified) {}
            protected function verifyGoogleIdToken(string $idToken): array
            {
                return ['sub' => 'google-test-id', 'email' => 'teacher@example.test',
                    'email_verified' => $this->verified, 'name' => 'Test Teacher'];
            }
        };
    }

    private function expectLogin(): void
    {
        $guard = Mockery::mock();
        $guard->shouldReceive('user')->andReturn(null);
        $guard->shouldReceive('login')->once()->with(Mockery::type(WebUser::class))->andReturn('test-token');
        Auth::shouldReceive('guard')->with('web_api')->andReturn($guard);
        Auth::shouldReceive('guard')->with('api')->andReturn($guard);
        Auth::shouldReceive('user')->andReturn(null);
    }

    private function account(string $role, string $status = 'active'): WebUser
    {
        return WebUser::create(['name' => 'Existing User', 'email' => 'teacher@example.test',
            'password' => 'unused', 'role' => $role, 'status' => $status, 'login_provider' => 'email']);
    }

    public function test_new_teacher_google_account_has_teacher_role(): void
    {
        $this->expectLogin();
        $response = $this->service()->googleLogin('verified-test-token', 'teacher');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('teacher', $response->getData(true)['user']['role']);
        $this->assertSame('teacher', WebUser::first()->role);
        $this->assertSame('pending', WebUser::first()->teacherProfile->status);
        $this->assertNull(WebUser::first()->teacherProfile->collection_code);
        $this->assertDatabaseCount('user_profile_tbl', 0);
        $this->assertNotNull(WebUser::first()->email_verified_at);
        $this->assertCount(1, $response->headers->getCookies());
    }

    public function test_default_google_signup_remains_student(): void
    {
        $this->expectLogin();
        $response = $this->service()->googleLogin('verified-test-token');
        $this->assertSame('student', $response->getData(true)['user']['role']);
    }

    public function test_existing_teacher_is_linked_without_duplicate_account(): void
    {
        $user = $this->account('teacher');
        $this->expectLogin();
        $response = $this->service()->googleLogin('verified-test-token', 'teacher');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('google-test-id', $user->fresh()->google_id);
        $this->assertSame(1, WebUser::count());
    }

    public function test_existing_teacher_keeps_role_on_student_google_entry(): void
    {
        $this->account('teacher');
        $this->expectLogin();
        $response = $this->service()->googleLogin('verified-test-token');
        $this->assertSame('teacher', $response->getData(true)['user']['role']);
    }

    public function test_student_cannot_switch_role_via_teacher_google_login(): void
    {
        $user = $this->account('student');
        Auth::shouldReceive('guard')->never();
        $response = $this->service()->googleLogin('verified-test-token', 'teacher');
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('student', $user->fresh()->role);
        $this->assertNull($user->fresh()->google_id);
        $this->assertCount(0, $response->headers->getCookies());
    }

    public function test_blocked_teacher_is_not_reactivated_by_google(): void
    {
        $user = $this->account('teacher', 'blocked');
        Auth::shouldReceive('guard')->never();
        $response = $this->service()->googleLogin('verified-test-token', 'teacher');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $user->fresh()->status);
    }

    public function test_unverified_google_email_cannot_create_teacher(): void
    {
        Auth::shouldReceive('guard')->never();
        $response = $this->service(false)->googleLogin('unverified-test-token', 'teacher');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, WebUser::count());
    }

    public function test_public_google_login_rejects_admin_role(): void
    {
        $this->expectException(ValidationException::class);
        (new WebUserAuthController())->googleLogin(Request::create('/auth/google-login', 'POST', [
            'idToken' => 'test-token', 'role' => 'admin',
        ]));
    }
}
