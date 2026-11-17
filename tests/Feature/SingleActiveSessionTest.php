<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SingleActiveSessionTest extends TestCase
{
    private string $password = 'Secret123!';

    private function createStudent(string $email = 'student.session@example.com'): WebUser
    {
        WebUser::where('email', $email)->forceDelete();

        return WebUser::create([
            'name' => 'Session Student',
            'email' => $email,
            'password' => Hash::make($this->password),
            'role' => 'student',
            'phone' => '03009990001',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createTeacher(string $email = 'teacher.session@example.com'): WebUser
    {
        WebUser::where('email', $email)->forceDelete();

        $teacher = WebUser::create([
            'name' => 'Session Teacher',
            'email' => $email,
            'password' => Hash::make($this->password),
            'role' => 'teacher',
            'phone' => '03009990002',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        TeacherProfile::firstOrCreate(['web_user_id' => $teacher->id]);

        return $teacher;
    }

    /**
     * Scenario 1, 2, 3, 4, 5, 6, 8:
     * Student single active device session lifecycle.
     */
    public function test_student_single_active_session_enforcement(): void
    {
        $student = $this->createStudent();

        // 1. Device A login
        $responseA = $this->postJson('/web_api/auth/lms-login', [
            'login' => $student->email,
            'password' => $this->password,
        ]);
        $responseA->assertStatus(200);
        $tokenA = $responseA->json('token');
        $this->assertNotEmpty($tokenA);

        // Verify Device A can access protected route /auth/me
        $meA = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/web_api/auth/me');
        $meA->assertStatus(200);
        $meA->assertJsonPath('user.email', $student->email);

        // 2. Device B login with same account
        $responseB = $this->postJson('/web_api/auth/lms-login', [
            'login' => $student->email,
            'password' => $this->password,
        ]);
        $responseB->assertStatus(200);
        $tokenB = $responseB->json('token');
        $this->assertNotEmpty($tokenB);
        $this->assertNotEquals($tokenA, $tokenB);

        // 3. Verify Device A loses access immediately
        $meAAfterB = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/web_api/auth/me');
        $meAAfterB->assertStatus(401);
        $meAAfterB->assertJson([
            'message' => 'Your account was signed in on another device. Please log in again.',
            'code' => 'SESSION_REVOKED',
        ]);

        // 4. Verify Device B remains active and authenticated
        $meB = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/web_api/auth/me');
        $meB->assertStatus(200);
        $meB->assertJsonPath('user.email', $student->email);

        // 5. Verify page refresh/navigation on Device B preserves session
        for ($i = 0; $i < 3; $i++) {
            $refreshB = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
                ->getJson('/web_api/auth/me');
            $refreshB->assertStatus(200);
        }

        // 6. Device A attempt again is still rejected
        $meAAttempt2 = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/web_api/auth/me');
        $meAAttempt2->assertStatus(401);
        $meAAttempt2->assertJsonPath('code', 'SESSION_REVOKED');

        // 8. Device B logs out
        $logoutB = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson('/web_api/auth/lms-logout');
        $logoutB->assertStatus(200);

        // Verify Device B token is now also rejected
        $meBAfterLogout = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/web_api/auth/me');
        $meBAfterLogout->assertStatus(401);

        // Clean up
        $student->forceDelete();
    }

    /**
     * Scenario 9:
     * Teacher single active device session lifecycle.
     */
    public function test_teacher_single_active_session_enforcement(): void
    {
        $teacher = $this->createTeacher();

        // 1. Device A login for teacher
        $responseA = $this->postJson('/web_api/auth/lms-login', [
            'login' => $teacher->email,
            'password' => $this->password,
        ]);
        $responseA->assertStatus(200);
        $tokenA = $responseA->json('token');
        $this->assertNotEmpty($tokenA);

        // Device A access protected teacher profile/me
        $meA = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/web_api/auth/me');
        $meA->assertStatus(200);
        $meA->assertJsonPath('user.role', 'teacher');

        // 2. Device B login for same teacher
        $responseB = $this->postJson('/web_api/auth/lms-login', [
            'login' => $teacher->email,
            'password' => $this->password,
        ]);
        $responseB->assertStatus(200);
        $tokenB = $responseB->json('token');
        $this->assertNotEmpty($tokenB);

        // 3. Device A revoked
        $meARevoked = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/web_api/auth/me');
        $meARevoked->assertStatus(401);
        $meARevoked->assertJson([
            'message' => 'Your account was signed in on another device. Please log in again.',
            'code' => 'SESSION_REVOKED',
        ]);

        // 4. Device B active
        $meB = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/web_api/auth/me');
        $meB->assertStatus(200);
        $meB->assertJsonPath('user.role', 'teacher');

        // Teacher logout
        $logout = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson('/web_api/auth/lms-logout');
        $logout->assertStatus(200);

        // Device B revoked after logout
        $meBAfterLogout = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/web_api/auth/me');
        $meBAfterLogout->assertStatus(401);

        // Clean up
        $teacher->forceDelete();
    }

    /**
     * Scenario 7:
     * Unauthenticated request without token returns standard 401 unauthenticated.
     */
    public function test_unauthenticated_request_rejected_without_token(): void
    {
        $response = $this->getJson('/web_api/auth/me');
        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Scenario 10:
     * Simulates nearly simultaneous logins and verifies only the latest active token is accepted.
     */
    public function test_simultaneous_logins_preserve_single_active_session(): void
    {
        $student = $this->createStudent('simultaneous.test@example.com');

        // Simulate 2 logins in quick succession
        $res1 = $this->postJson('/web_api/auth/lms-login', [
            'login' => $student->email,
            'password' => $this->password,
        ]);
        $token1 = $res1->json('token');

        $res2 = $this->postJson('/web_api/auth/lms-login', [
            'login' => $student->email,
            'password' => $this->password,
        ]);
        $token2 = $res2->json('token');

        // Only token2 must be active
        $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->getJson('/web_api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');

        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->getJson('/web_api/auth/me')
            ->assertStatus(200);

        $student->forceDelete();
    }
}
