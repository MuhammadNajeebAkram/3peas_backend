<?php

namespace Tests\Feature;

use App\Http\Controllers\QuestionsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionBulkImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite PDO extension is not loaded in this PHP runtime.');
        }

        config([
            'database.default' => 'question_import_test',
            'database.connections.question_import_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('question_import_test');

        $tables = [
            'exam_question_tbl' => 'question question_um topic_id unit_id book_id question_type exercise_question cognitive_domain topic_content difficulty marks status reviewed_by reviewed_at explanation explanation_um explanation_video_url activate is_mcq has_diagram question_presentation_type_id scenario_group_id scenario_question_order is_alp_question source created_by updated_by created_at updated_at question_lang question_um_lang',
            'book_unit_topic_tbl' => 'unit_id topic_name topic_name_um topic_no slo_no activate is_alp created_at updated_at',
            'book_unit_tbl' => 'book_id unit_name',
            'book_tbl' => 'book_name class_id subject_id curriculum_board_id',
            'curriculum_board_tbl' => 'name',
            'class_tbl' => 'class_name',
            'subject_tbl' => 'subject_name',
            'question_type_tbl' => 'type_name',
            'question_presentation_type_tbl' => 'type_name code allows_multiple_mcqs activate sort_order',
            'question_scenario_groups_tbl' => 'title scenario_text scenario_text_um scenario_image question_presentation_type_id topic_id unit_id book_id activate created_by updated_by created_at updated_at',
            'exam_question_board_tbl' => 'question_id board_id session_id year group_id activate created_by updated_by created_at updated_at',
            'exam_answer_tbl' => 'question_id answer answer_um answer_lang answer_um_lang created_by updated_by created_at updated_at',
            'exam_question_options_tbl' => 'question_id option option_um is_answer option_lang created_by updated_by created_at updated_at',
            'permissions' => 'name',
            'role_permission_scopes' => 'role_id permission_id scope_type scope_id',
        ];

        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->id();
                foreach (explode(' ', $columns) as $column) {
                    $table->string($column)->nullable();
                }
            });
        }

        DB::table('curriculum_board_tbl')->insert(['id' => 1, 'name' => 'National Curriculum']);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 9']);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Biology']);
        DB::table('book_tbl')->insert(['id' => 1, 'book_name' => 'Biology 9', 'curriculum_board_id' => 1, 'class_id' => 1, 'subject_id' => 1]);
        DB::table('book_unit_tbl')->insert(['id' => 1, 'book_id' => 1, 'unit_name' => 'Cell Biology']);
        DB::table('book_unit_topic_tbl')->insert(['id' => 1, 'unit_id' => 1, 'topic_name' => 'Cell Structure']);
        DB::table('question_presentation_type_tbl')->insert([
            'id' => 1,
            'type_name' => 'Stimulus Scenario',
            'code' => 'STIMULUS',
            'allows_multiple_mcqs' => 1,
            'activate' => 1,
            'sort_order' => 1,
        ]);
        DB::table('question_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'MCQ'],
            ['id' => 2, 'type_name' => 'Short Answer'],
        ]);
    }

    protected function createMockUser()
    {
        $user = new class {
            public $id = 1;
            public $role_id = 1;
            public $role;
            public function loadMissing($relations) {}
            public function hasRole($role) { return true; }
            public function can($permission) { return true; }
        };
        $user->role = (object) ['name' => 'super_admin'];
        return $user;
    }

    public function test_validate_import_package_detects_missing_image_references()
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/admin/auth/question/validate-import-package', 'POST', [
            'json_content' => json_encode([
                'questions' => [
                    [
                        'question' => 'Look at <img src="images/missing-diagram.png" /> and identify organelle A.',
                        'options' => [
                            ['text' => 'Mitochondria', 'is_correct' => true],
                            ['text' => 'Ribosome', 'is_correct' => false],
                        ],
                    ],
                ],
            ]),
        ]);
        $request->setUserResolver(fn () => $this->createMockUser());

        $response = $controller->validateImportPackage($request);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertFalse($data['valid']);
        $this->assertContains('images/missing-diagram.png', $data['missing_images']);
    }

    public function test_validate_import_package_rejects_empty_payload()
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/admin/auth/question/validate-import-package', 'POST', []);
        $request->setUserResolver(fn () => $this->createMockUser());

        $response = $controller->validateImportPackage($request);
        $this->assertEquals(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
    }

    public function test_bulk_import_questions_saves_mcqs_and_links_scenario_group()
    {
        $controller = new QuestionsController();
        $request = Request::create('/api/admin/auth/question/bulk-import', 'POST', [
            'book_id' => 1,
            'unit_id' => 1,
            'topic_id' => 1,
            'default_status' => 'published',
            'stimulus' => [
                'title' => 'Enzyme Activity Scenario',
                'passage' => '<p>An experiment was conducted measuring amylase breakdown time.</p>',
                'question_presentation_type_id' => 1,
            ],
            'questions' => [
                [
                    'client_id' => 'client-q-101',
                    'question' => 'What is the optimum temperature for amylase?',
                    'marks' => 1,
                    'options' => [
                        ['text' => '37°C', 'is_correct' => true],
                        ['text' => '100°C', 'is_correct' => false],
                    ],
                ],
                [
                    'client_id' => 'client-q-102',
                    'question' => 'What happens to the enzyme above 60°C?',
                    'marks' => 1,
                    'options' => [
                        ['text' => 'It denatures', 'is_correct' => true],
                        ['text' => 'It freezes', 'is_correct' => false],
                    ],
                ],
            ],
        ]);
        $request->setUserResolver(fn () => $this->createMockUser());

        $response = $controller->bulkImportQuestions($request);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['imported']);
        $this->assertNotNull($data['scenario_group_id']);

        // Verify scenario group was created
        $scenario = DB::table('question_scenario_groups_tbl')->where('id', $data['scenario_group_id'])->first();
        $this->assertNotNull($scenario);
        $this->assertEquals('Enzyme Activity Scenario', $scenario->title);

        // Verify sub-questions have scenario_group_id and order
        $questions = DB::table('exam_question_tbl')->where('scenario_group_id', $data['scenario_group_id'])->get();
        $this->assertCount(2, $questions);
        $this->assertEquals(1, $questions[0]->scenario_question_order);
        $this->assertEquals(2, $questions[1]->scenario_question_order);
        $this->assertEquals(0, $questions[0]->exercise_question);
        $this->assertEquals(1, $questions[0]->is_alp_question);
    }

    public function test_bulk_import_idempotency_prevents_duplicate_insertion_on_retry()
    {
        $controller = new QuestionsController();
        $payload = [
            'book_id' => 1,
            'unit_id' => 1,
            'topic_id' => 1,
            'questions' => [
                [
                    'client_id' => 'unique-retry-key-999',
                    'question' => 'Which cell organelle produces ATP?',
                    'options' => [
                        ['text' => 'Mitochondria', 'is_correct' => true],
                        ['text' => 'Golgi body', 'is_correct' => false],
                    ],
                ],
            ],
        ];

        // First attempt
        $request1 = Request::create('/api/admin/auth/question/bulk-import', 'POST', $payload);
        $request1->setUserResolver(fn () => $this->createMockUser());
        $res1 = $controller->bulkImportQuestions($request1)->getData(true);
        $this->assertEquals(1, $res1['imported']);
        $firstQuestionId = $res1['results'][0]['question_id'];

        // Retry attempt with same client_id
        $request2 = Request::create('/api/admin/auth/question/bulk-import', 'POST', $payload);
        $request2->setUserResolver(fn () => $this->createMockUser());
        $res2 = $controller->bulkImportQuestions($request2)->getData(true);
        $this->assertEquals(1, $res2['imported']);
        $this->assertEquals($firstQuestionId, $res2['results'][0]['question_id']);
        $this->assertTrue($res2['results'][0]['already_imported']);

        // Assert database only has 1 question record
        $count = DB::table('exam_question_tbl')->where('question', 'Which cell organelle produces ATP?')->count();
        $this->assertEquals(1, $count);
    }

    public function test_bulk_import_auto_creates_missing_topic_and_reuses_it()
    {
        $controller = new QuestionsController();
        $payload = [
            'book_id' => 1,
            'unit_id' => 1,
            'auto_create_topics' => true,
            'questions' => [
                [
                    'client_id' => 'q-new-topic-1',
                    'question' => 'First question about Organic Reactions',
                    'new_topic_name' => 'Organic Reactions',
                    'options' => [
                        ['text' => 'Opt A', 'is_correct' => true],
                        ['text' => 'Opt B', 'is_correct' => false],
                    ],
                ],
                [
                    'client_id' => 'q-new-topic-2',
                    'question' => 'Second question about Organic Reactions',
                    'topic_name' => 'Organic Reactions',
                    'options' => [
                        ['text' => 'Opt C', 'is_correct' => true],
                        ['text' => 'Opt D', 'is_correct' => false],
                    ],
                ],
            ],
        ];

        $request = Request::create('/api/admin/auth/question/bulk-import', 'POST', $payload);
        $request->setUserResolver(fn () => $this->createMockUser());

        $res = $controller->bulkImportQuestions($request)->getData(true);
        $this->assertTrue($res['success'], json_encode($res));
        $this->assertEquals(2, $res['imported']);
        $this->assertContains('Organic Reactions', $res['created_topics']);

        // Check topic table has exactly 1 new topic
        $createdTopic = DB::table('book_unit_topic_tbl')->where('unit_id', 1)->where('topic_name', 'Organic Reactions')->first();
        $this->assertNotNull($createdTopic);

        // Check both questions link to this same topic ID
        $q1 = DB::table('exam_question_tbl')->where('question', 'First question about Organic Reactions')->first();
        $q2 = DB::table('exam_question_tbl')->where('question', 'Second question about Organic Reactions')->first();
        $this->assertEquals($createdTopic->id, $q1->topic_id);
        $this->assertEquals($createdTopic->id, $q2->topic_id);
    }

    public function test_bulk_import_subjective_and_scenario_short_questions()
    {
        $controller = new QuestionsController();
        $payload = [
            'book_id' => 1,
            'unit_id' => 1,
            'topic_id' => 1,
            'stimulus' => [
                'title' => 'Ammonia Synthesis Case Study',
                'passage' => '<p>Reversible Haber process at 450C and 200atm.</p>',
            ],
            'questions' => [
                [
                    'client_id' => 'sq-1',
                    'question' => '<p>State Le Chatelier principle for high pressure.</p>',
                    'question_type' => 2,
                    'is_mcq' => false,
                    'marks' => 3,
                    'answer' => '<p>Equilibrium shifts toward fewer moles of gas.</p>',
                    'explanation' => '<p>Award full 3 marks for correct direction and explanation.</p>',
                ],
                [
                    'client_id' => 'sq-2',
                    'question' => '<p>Why is 450C used instead of 200C?</p>',
                    'question_type' => 2,
                    'is_mcq' => false,
                    'marks' => 3,
                    'answer' => '<p>To ensure practical rate of reaction.</p>',
                ],
            ],
        ];

        $request = Request::create('/api/admin/auth/question/bulk-import', 'POST', $payload);
        $request->setUserResolver(fn () => $this->createMockUser());

        $res = $controller->bulkImportQuestions($request)->getData(true);
        $this->assertTrue($res['success'], json_encode($res));
        $this->assertEquals(2, $res['imported']);

        // Check scenario group created
        $scenarioGroup = DB::table('question_scenario_groups_tbl')->where('title', 'Ammonia Synthesis Case Study')->first();
        $this->assertNotNull($scenarioGroup);

        // Check both questions are saved as non-MCQs with marks and answers
        $savedQ1 = DB::table('exam_question_tbl')->where('question', '<p>State Le Chatelier principle for high pressure.</p>')->first();
        $this->assertNotNull($savedQ1);
        $this->assertEquals(0, $savedQ1->is_mcq);
        $this->assertEquals(3, $savedQ1->marks);
        $this->assertEquals($scenarioGroup->id, $savedQ1->scenario_group_id);
        $this->assertEquals(1, $savedQ1->scenario_question_order);

        // Check answer in exam_answer_tbl
        $answer1 = DB::table('exam_answer_tbl')->where('question_id', $savedQ1->id)->first();
        $this->assertNotNull($answer1);
        $this->assertEquals('<p>Equilibrium shifts toward fewer moles of gas.</p>', $answer1->answer);

        // Check no options were created
        $optionsCount = DB::table('exam_question_options_tbl')->where('question_id', $savedQ1->id)->count();
        $this->assertEquals(0, $optionsCount);
    }

    public function test_bulk_import_multiple_scenario_groups()
    {
        $controller = new QuestionsController();
        $payload = [
            'book_id' => 1,
            'unit_id' => 1,
            'topic_id' => 1,
            'scenarios' => [
                [
                    'index' => 0,
                    'stimulus' => [
                        'title' => 'Scenario 1: Rust Formation',
                        'passage' => '<p>Rusting experiment with nails.</p>',
                    ],
                ],
                [
                    'index' => 1,
                    'stimulus' => [
                        'title' => 'Scenario 2: Water Purification',
                        'passage' => '<p>Water treatment stages.</p>',
                    ],
                ],
            ],
            'questions' => [
                [
                    'client_id' => 'multi-sc-1',
                    'question' => '<p>Question 1 for Rust</p>',
                    'scenario_group_index' => 0,
                    'scenario_question_order' => 1,
                    'options' => [
                        ['text' => 'Opt A', 'is_correct' => true],
                        ['text' => 'Opt B', 'is_correct' => false],
                    ],
                ],
                [
                    'client_id' => 'multi-sc-2',
                    'question' => '<p>Question 2 for Rust</p>',
                    'scenario_group_index' => 0,
                    'scenario_question_order' => 2,
                    'options' => [
                        ['text' => 'Opt C', 'is_correct' => true],
                        ['text' => 'Opt D', 'is_correct' => false],
                    ],
                ],
                [
                    'client_id' => 'multi-sc-3',
                    'question' => '<p>Question 1 for Water</p>',
                    'scenario_group_index' => 1,
                    'scenario_question_order' => 1,
                    'options' => [
                        ['text' => 'Opt E', 'is_correct' => true],
                        ['text' => 'Opt F', 'is_correct' => false],
                    ],
                ],
            ],
        ];

        $request = Request::create('/api/admin/auth/question/bulk-import', 'POST', $payload);
        $request->setUserResolver(fn () => $this->createMockUser());

        $res = $controller->bulkImportQuestions($request)->getData(true);
        $this->assertTrue($res['success'], json_encode($res));
        $this->assertEquals(3, $res['imported']);

        // Check both scenario groups were created
        $sc1 = DB::table('question_scenario_groups_tbl')->where('title', 'Scenario 1: Rust Formation')->first();
        $sc2 = DB::table('question_scenario_groups_tbl')->where('title', 'Scenario 2: Water Purification')->first();
        $this->assertNotNull($sc1);
        $this->assertNotNull($sc2);
        $this->assertNotEquals($sc1->id, $sc2->id);

        // Check question 1 and 2 link to sc1
        $q1 = DB::table('exam_question_tbl')->where('question', '<p>Question 1 for Rust</p>')->first();
        $q2 = DB::table('exam_question_tbl')->where('question', '<p>Question 2 for Rust</p>')->first();
        $this->assertEquals($sc1->id, $q1->scenario_group_id);
        $this->assertEquals(1, $q1->scenario_question_order);
        $this->assertEquals($sc1->id, $q2->scenario_group_id);
        $this->assertEquals(2, $q2->scenario_question_order);

        // Check question 3 links to sc2
        $q3 = DB::table('exam_question_tbl')->where('question', '<p>Question 1 for Water</p>')->first();
        $this->assertEquals($sc2->id, $q3->scenario_group_id);
        $this->assertEquals(1, $q3->scenario_question_order);
    }
}
