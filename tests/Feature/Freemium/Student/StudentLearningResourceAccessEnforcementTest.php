<?php

namespace Tests\Feature\Freemium\Student;

use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\ExamQuestion;
use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\OfferedClass;
use App\Models\OfferedProgram;
use App\Models\ProgramSubject;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentLearningResourceAccessEnforcementTest extends TestCase
{
    private WebUser $student;
    private WebUser $otherStudent;
    private User $admin;
    private Carbon $now;

    private int $classId;
    private int $boardId;
    private int $subjectId;
    private int $altSubjectId;

    private OfferedClass $offeredClass;
    private OfferedProgram $programA;
    private OfferedProgram $programB;
    private ProgramSubject $progSubA;
    private ProgramSubject $progSubB;

    private Book $bookA;
    private Book $bookB;
    private BookUnit $unitA;
    private BookUnit $unitB;
    private BookUnitTopic $topicA;
    private BookUnitTopic $topicB;
    private ExamQuestion $questionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-18 12:00:00', 'UTC');

        // Admin fixture
        $adminId = DB::table('users')->value('id');
        $this->admin = $adminId ? User::find($adminId) : User::create([
            'name'     => 'Resource Admin ' . uniqid(),
            'email'    => 'admin_res_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Student fixtures
        $studentId = DB::table('web_users')->insertGetId([
            'name'       => 'Resource Student One',
            'email'      => 'student_res_1_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->student = WebUser::find($studentId);

        $otherStudentId = DB::table('web_users')->insertGetId([
            'name'       => 'Resource Student Two',
            'email'      => 'student_res_2_' . uniqid() . '@example.com',
            'password'   => bcrypt('secret'),
            'role'       => 'student',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->otherStudent = WebUser::find($otherStudentId);

        // Academic core IDs
        $this->classId   = (int) (DB::table('class_tbl')->value('id') ?? 1);
        $this->boardId   = (int) (DB::table('curriculum_board_tbl')->value('id') ?? 1);
        $this->subjectId = (int) (DB::table('subject_tbl')->value('id') ?? 1);

        $altSub = DB::table('subject_tbl')->where('id', '!=', $this->subjectId)->value('id');
        $this->altSubjectId = $altSub ? (int) $altSub : (int) DB::table('subject_tbl')->insertGetId([
            'subject_name' => 'Alt Subject ' . uniqid(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Offered Class
        $this->offeredClass = OfferedClass::create([
            'class_id'            => $this->classId,
            'curriculum_board_id' => $this->boardId,
            'price'               => 1500,
            'discount_price'      => 1200,
            'is_free'             => 0,
            'is_active'           => 1,
        ]);

        // Program A (Covering subject A)
        $this->programA = OfferedProgram::create([
            'offered_class_id' => $this->offeredClass->id,
            'title'            => 'Program Alpha ' . uniqid(),
            'slug'             => 'program-alpha-' . uniqid(),
            'display_order'    => 1,
            'is_active'        => 1,
        ]);
        $this->progSubA = ProgramSubject::create([
            'offered_program_id' => $this->programA->id,
            'subject_id'         => $this->subjectId,
            'display_order'      => 1,
            'is_free'            => 0,
            'is_active'          => 1,
        ]);

        // Program B (Covering alternative subject B)
        $this->programB = OfferedProgram::create([
            'offered_class_id' => $this->offeredClass->id,
            'title'            => 'Program Beta ' . uniqid(),
            'slug'             => 'program-beta-' . uniqid(),
            'display_order'    => 2,
            'is_active'        => 1,
        ]);
        $this->progSubB = ProgramSubject::create([
            'offered_program_id' => $this->programB->id,
            'subject_id'         => $this->altSubjectId,
            'display_order'      => 1,
            'is_free'            => 0,
            'is_active'          => 1,
        ]);

        // Books
        $this->bookA = Book::create([
            'book_name'           => 'Physics 101 Book',
            'class_id'            => $this->classId,
            'subject_id'          => $this->subjectId,
            'curriculum_board_id' => $this->boardId,
            'activate'            => 1,
        ]);
        $this->bookB = Book::create([
            'book_name'           => 'Chemistry 101 Book',
            'class_id'            => $this->classId,
            'subject_id'          => $this->altSubjectId,
            'curriculum_board_id' => $this->boardId,
            'activate'            => 1,
        ]);

        // Units
        $this->unitA = BookUnit::create([
            'unit_name' => 'Kinematics',
            'unit_no'   => 1,
            'book_id'   => $this->bookA->id,
            'activate'  => 1,
        ]);
        $this->unitB = BookUnit::create([
            'unit_name' => 'Organic Chemistry',
            'unit_no'   => 1,
            'book_id'   => $this->bookB->id,
            'activate'  => 1,
        ]);

        // Topics
        $this->topicA = BookUnitTopic::create([
            'topic_name' => 'Velocity and Acceleration',
            'topic_no'   => 1,
            'slo_no'     => 'SLO-1.1',
            'unit_id'    => $this->unitA->id,
            'activate'   => 1,
        ]);
        $this->topicB = BookUnitTopic::create([
            'topic_name' => 'Hydrocarbons',
            'topic_no'   => 1,
            'slo_no'     => 'SLO-2.1',
            'unit_id'    => $this->unitB->id,
            'activate'   => 1,
        ]);

        // Exam Question with answer and options (Topic A)
        $qId = DB::table('exam_question_tbl')->insertGetId([
            'question'         => 'SECRET STATEMENT: What is the velocity of light in vacuum?',
            'topic_id'         => $this->topicA->id,
            'question_type'    => 1,
            'activate'         => 1,
            'marks'            => 1.00,
            'difficulty'       => 3,
            'status'           => 'published',
            'source'           => 'manual',
            'explanation'      => 'SECRET EXPLANATION: Light travels at approximately 3x10^8 m/s in vacuum.',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        $this->questionA = ExamQuestion::find($qId);

        DB::table('exam_question_options_tbl')->insert([
            [
                'question_id' => $this->questionA->id,
                'option'      => 'SECRET OPTION A: 3x10^8 m/s',
                'is_answer'   => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'question_id' => $this->questionA->id,
                'option'      => 'SECRET OPTION B: 1.5x10^8 m/s',
                'is_answer'   => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        DB::table('exam_answer_tbl')->insert([
            'question_id' => $this->questionA->id,
            'answer'      => 'SECRET ANSWER KEY: Option A is correct.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('exam_answer_tbl')->where('question_id', $this->questionA->id)->delete();
        DB::table('exam_question_options_tbl')->where('question_id', $this->questionA->id)->delete();
        DB::table('exam_question_tbl')->where('id', $this->questionA->id)->delete();
        DB::table('student_activities')->whereIn('user_id', [$this->student->id, $this->otherStudent->id])->delete();
        DB::table('book_unit_topic_tbl')->whereIn('id', [$this->topicA->id, $this->topicB->id])->delete();
        DB::table('book_unit_tbl')->whereIn('id', [$this->unitA->id, $this->unitB->id])->delete();
        DB::table('book_tbl')->whereIn('id', [$this->bookA->id, $this->bookB->id])->delete();
        DB::table('freemium_access_rules')->delete();
        DB::table('freemium_student_grants')->delete();
        DB::table('freemium_quota_consumptions')->delete();
        DB::table('freemium_campaigns')->delete();
        DB::table('user_subscriptions')->whereIn('user_id', [$this->student->id, $this->otherStudent->id])->delete();
        DB::table('program_subjects')->whereIn('id', [$this->progSubA->id, $this->progSubB->id])->delete();
        DB::table('offered_programs')->whereIn('id', [$this->programA->id, $this->programB->id])->delete();
        DB::table('offered_classes')->where('id', $this->offeredClass->id)->delete();
        DB::table('web_users')->whereIn('id', [$this->student->id, $this->otherStudent->id])->delete();

        parent::tearDown();
    }

    private function studentHeaders(WebUser $student): array
    {
        $token = auth('web_api')->login($student);
        $student->update(['last_token' => hash('sha256', $token)]);
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    // =========================================================================
    // SECTION A: QUESTIONS ACCESS ENFORCEMENT
    // =========================================================================

    /**
     * Paid student with active subscription can access entitled topic questions.
     */
    public function test_paid_student_can_access_entitled_questions(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonths(2),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame($this->questionA->id, $data[0]['id']);
        $this->assertStringContainsString('velocity of light', $data[0]['question']);
    }

    /**
     * Freemium student without paid subscription can access questions allowed by explicit rule.
     */
    public function test_freemium_student_can_access_questions_allowed_by_explicit_rule(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Open Physics Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'topic_id'                 => $this->topicA->id,
            'target_scope'             => 'topic',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['topic_id' => $this->topicA->id],
            'perm_mcqs'                => 'allow',
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json());
    }

    /**
     * Denied student cannot retrieve questions (403 Access Denied).
     */
    public function test_denied_student_cannot_retrieve_questions(): void
    {
        // Unsubscribed, no campaigns
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * Denied response does NOT leak question text, answer keys, options, or explanations.
     */
    public function test_denied_response_does_not_leak_answers_options_or_explanations(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions");

        $response->assertStatus(403);
        $content = $response->getContent();

        // Must NOT leak protected question content
        $this->assertStringNotContainsString('SECRET STATEMENT', $content);
        $this->assertStringNotContainsString('SECRET EXPLANATION', $content);
        $this->assertStringNotContainsString('SECRET OPTION', $content);
        $this->assertStringNotContainsString('SECRET ANSWER KEY', $content);
        $this->assertStringNotContainsString('3x10^8', $content);
    }

    /**
     * Student cannot bypass access by manipulating hierarchy identifiers in query string.
     */
    public function test_student_cannot_bypass_question_access_by_manipulating_hierarchy(): void
    {
        // Subscribe to Program B (Chemistry), try to access Topic A (Physics) by passing Program B ID
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programB->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonth(),
        ]);

        // Attempt 1: Passing wrong program_id in query parameter
        $response1 = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions?offered_program_id={$this->programB->id}");

        $response1->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Program does not cover the requested topic subject/class/board.',
            ]);

        // Attempt 2: Passing wrong unit_id
        $response2 = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions?unit_id={$this->unitB->id}");

        $response2->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Topic does not belong to the requested unit.',
            ]);

        // Attempt 3: Passing wrong subject_id
        $response3 = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions?subject_id={$this->altSubjectId}");

        $response3->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Topic does not belong to the requested subject.',
            ]);
    }

    /**
     * Non-student or inactive account rejected with 403.
     */
    public function test_inactive_or_non_student_cannot_access_questions(): void
    {
        $this->student->update(['status' => 'blocked']);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-questions-by-topic/{$this->topicA->id}/questions");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Active student account required.']);
    }

    // =========================================================================
    // SECTION B: TOPICS LISTING ENFORCEMENT
    // =========================================================================

    /**
     * Entitled student can list topics for a unit.
     */
    public function test_entitled_student_can_list_topics(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonths(2),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-topics-by-units/{$this->unitA->id}/topics");

        $response->assertStatus(200);
        $topics = $response->json();
        $this->assertIsArray($topics);
        $this->assertNotEmpty($topics);
        $this->assertSame($this->topicA->id, $topics[0]['id']);
        $this->assertSame('Velocity and Acceleration', $topics[0]['topic_name']);
    }

    /**
     * Denied student cannot enumerate protected topics (returns 403).
     */
    public function test_denied_student_cannot_enumerate_protected_topics(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-topics-by-units/{$this->unitA->id}/topics");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * Topic listing with mismatched hierarchy parameters fails closed (422).
     */
    public function test_invalid_or_mismatched_unit_hierarchy_fails_closed(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonth(),
        ]);

        // Mismatched book_id for Unit A
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-topics-by-units/{$this->unitA->id}/topics?book_id={$this->bookB->id}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => 0,
                'message' => 'Hierarchy mismatch: Unit does not belong to the requested book.',
            ]);

        // Non-existent unit returns 404
        $this->withHeaders($this->studentHeaders($this->student))
            ->getJson('/web_api/auth/my-classes/get-topics-by-units/9999999/topics')
            ->assertStatus(404);
    }

    // =========================================================================
    // SECTION C: UNITS LISTING ENFORCEMENT
    // =========================================================================

    /**
     * Valid paid subscription grants unit access.
     */
    public function test_valid_paid_subscription_grants_unit_access(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonths(2),
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-units/{$this->programA->id}/{$this->subjectId}");

        $response->assertStatus(200)
            ->assertJson(['success' => 1]);

        $units = $response->json('units');
        $this->assertIsArray($units);
        $this->assertNotEmpty($units);
        $unit = collect($units)->firstWhere('id', $this->unitA->id);
        $this->assertNotNull($unit);
        $this->assertSame('Kinematics', $unit['unit_name']);
    }

    /**
     * Expired paid subscription is denied unit access (replaces weak exists() check).
     */
    public function test_expired_subscription_is_denied_unit_access(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->subDay(), // Expired yesterday
        ]);

        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-units/{$this->programA->id}/{$this->subjectId}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * Eligible Freemium student without paid subscription can access units.
     */
    public function test_eligible_freemium_student_without_paid_subscription_can_access_units(): void
    {
        $campaign = FreemiumCampaign::create([
            'created_by'   => $this->admin->id,
            'name'         => 'Open Subject Campaign',
            'status'       => 'active',
            'priority'     => 10,
            'start_at'     => now()->subDay(),
            'end_at'       => now()->addMonth(),
            'is_stackable' => true,
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $campaign->id,
            'subject_id'               => $this->subjectId,
            'target_scope'             => 'subject',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'academic_target_snapshot' => ['subject_id' => $this->subjectId],
            'perm_mcqs'                => 'allow',
        ]);

        // Student has ZERO paid subscriptions
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-units/{$this->programA->id}/{$this->subjectId}");

        $response->assertStatus(200)
            ->assertJson(['success' => 1]);

        $this->assertNotEmpty($response->json('units'));
    }

    /**
     * Default-deny behavior for unentitled student on unit listing.
     */
    public function test_default_deny_when_unentitled_to_units(): void
    {
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-units/{$this->programA->id}/{$this->subjectId}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => 0,
                'code'    => 'ACCESS_DENIED',
            ]);
    }

    /**
     * Legacy free fallback allows unit access when program_subjects.is_free = 1.
     */
    public function test_legacy_free_fallback_allows_unit_access(): void
    {
        $this->progSubA->update(['is_free' => 1]);

        // Student has no paid subscription and no active freemium campaign
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-units/{$this->programA->id}/{$this->subjectId}");

        $response->assertStatus(200)
            ->assertJson(['success' => 1]);

        $this->assertNotEmpty($response->json('units'));
    }

    /**
     * Mismatched subject or program returns 404.
     */
    public function test_mismatched_subject_or_program_returns_404(): void
    {
        UserSubscription::create([
            'user_id'            => $this->student->id,
            'offered_program_id' => $this->programA->id,
            'status'             => 'active',
            'expires_at'         => now()->addMonth(),
        ]);

        // Subject B does not belong to Program A
        $response = $this->withHeaders($this->studentHeaders($this->student))
            ->getJson("/web_api/auth/my-classes/get-units/{$this->programA->id}/{$this->altSubjectId}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => 0,
                'message' => 'Subject is not available in this program.',
            ]);
    }
}
