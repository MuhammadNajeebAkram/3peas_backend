<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiQuestionGeneration;
use App\Models\AiRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AiPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiQuestionGenerationTest extends TestCase
{
    private const BASE = '/api/admin/auth/question/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'generation_test', 'database.connections.generation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'services.openai.api_key' => 'test-openai', 'services.gemini.api_key' => 'test-gemini',
            'cache.default' => 'array', 'ai_questions.diagram_disk' => 'public']);
        DB::purge('generation_test');
        foreach ([
            'roles' => 'name', 'users' => 'name role_id', 'role_permission_scopes' => 'role_id permission_id scope_type scope_id',
            'subject_tbl' => 'subject_name', 'class_tbl' => 'class_name', 'book_tbl' => 'subject_id class_id curriculum_board_id',
            'book_unit_tbl' => 'book_id', 'book_unit_topic_tbl' => 'unit_id topic_name',
            'question_type_tbl' => 'type_name is_mcq activate', 'question_presentation_type_tbl' => 'type_name allows_multiple_mcqs activate',
            'exam_question_tbl' => 'question question_um topic_id question_type exercise_question marks difficulty question_lang question_um_lang cognitive_domain topic_content status reviewed_by reviewed_at explanation explanation_um explanation_video_url book_id unit_id activate is_mcq has_diagram question_presentation_type_id scenario_group_id scenario_question_order is_alp_question created_by updated_by',
            'exam_answer_tbl' => 'question_id answer answer_um answer_lang answer_um_lang',
            'exam_question_options_tbl' => 'question_id option option_um is_answer option_lang',
            'question_scenario_groups_tbl' => 'title scenario_text scenario_text_um scenario_image question_presentation_type_id topic_id unit_id book_id activate created_by updated_by',
        ] as $tableName => $columns) {
            Schema::create($tableName, function (Blueprint $table) use ($columns) {
                $table->id();
                foreach (explode(' ', $columns) as $column) {
                    $table->text($column)->nullable();
                }
                $table->timestamps();
            });
        }
        foreach (['2026_04_01_191541_create_permissions_table.php', '2026_04_01_191631_create_role_permissions_table.php',
            '2026_09_15_000001_create_ai_models_table.php', '2026_09_15_000002_create_ai_requests_table.php',
            '2026_09_16_000001_create_ai_providers_table.php', '2026_09_16_000002_add_ai_model_image_capability.php',
            '2026_09_16_000003_create_ai_question_generations_table.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->seed(AiPermissionsSeeder::class);
        Permission::firstOrCreate(['name' => 'questions.create']);
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs(User::create(['name' => 'Admin', 'role_id' => Role::where('name', 'super_admin')->value('id')]), 'api');
        AiModel::create(['ai_provider_id' => AiProvider::where('key', 'openai')->value('id'), 'name' => 'Test',
            'model_key' => 'test', 'is_active' => true, 'is_default' => true, 'supports_images' => true]);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics']);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 9']);
        DB::table('book_tbl')->insert(['id' => 1, 'subject_id' => 1, 'class_id' => 1, 'curriculum_board_id' => 1]);
        DB::table('book_unit_tbl')->insert(['id' => 1, 'book_id' => 1]);
        DB::table('book_unit_topic_tbl')->insert(['id' => 1, 'unit_id' => 1, 'topic_name' => 'Force']);
        DB::table('question_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'MCQ', 'is_mcq' => 1, 'activate' => 1],
            ['id' => 2, 'type_name' => 'Short Question', 'is_mcq' => 0, 'activate' => 1],
            ['id' => 3, 'type_name' => 'Long Question', 'is_mcq' => 0, 'activate' => 1],
        ]);
        DB::table('question_presentation_type_tbl')->insert(['id' => 1, 'type_name' => 'Stimulus', 'allows_multiple_mcqs' => 1, 'activate' => 1]);
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function input(array $extra = []): array
    {
        return array_replace(['topic_id' => 1, 'question_type' => 1, 'count' => 1, 'language' => 'en', 'source_text' => 'Force equals mass multiplied by acceleration.'], $extra);
    }

    private function draft(bool $mcq = true, int $count = 1): array
    {
        $questions = [];
        for ($i = 0; $i < $count; $i++) {
            $questions[] = ['question' => 'Find the force in case '.($i + 1).'.', 'question_um' => null,
                'answer' => $mcq ? null : 'Force equals mass multiplied by acceleration.', 'answer_um' => null,
                'explanation' => $mcq ? 'Use \\(F = ma\\).' : null, 'explanation_um' => null,
                'question_diagram' => null, 'answer_diagram' => null,
                'options' => $mcq ? array_map(fn ($n) => ['text' => $n.' N', 'text_um' => null, 'is_correct' => $n === 6, 'diagram' => null], [6, 3, 2, 1]) : [],
                'source_references' => [['source_id' => 'source-1', 'page' => null, 'note' => 'Force definition']],
                'needs_review' => false, 'review_reason' => null];
        }

        return ['scenario' => null, 'questions' => $questions, 'warnings' => []];
    }

    private function fake(array $draft): void
    {
        Http::swap((new \Illuminate\Http\Client\Factory)->preventStrayRequests());
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'response-test', 'model' => 'test', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($draft)]]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 200, 'input_tokens_details' => ['cached_tokens' => 0], 'total_tokens' => 300],
        ])]);
    }

    private function generate(array $extra = []): int
    {
        return $this->postJson(self::BASE.'generate', $this->input($extra))->assertCreated()->json('generation.id');
    }

    public function test_mcq_drafts_do_not_save_until_review_and_repeated_save_is_idempotent(): void
    {
        $this->fake($this->draft());
        $id = $this->generate(['prompt' => 'Use a familiar everyday situation.']);
        $this->assertSame(0, DB::table('exam_question_tbl')->count());
        $this->assertSame('question_generation', AiRequest::first()->purpose);
        $this->assertStringNotContainsString('Force equals mass', json_encode(AiRequest::first()->request_parameters));
        $this->getJson(self::BASE."generations/$id")->assertOk()->assertJsonMissingPath('generation.sources.0.path');
        $this->postJson(self::BASE."generations/$id/save", ['indices' => [0]])->assertUnprocessable();
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
        $this->assertSame(1, DB::table('exam_question_tbl')->count());
        $question = DB::table('exam_question_tbl')->first();
        $this->assertSame('draft', $question->status);
        $this->assertEquals(0, $question->activate);
        $this->assertSame(4, DB::table('exam_question_options_tbl')->count());
        $this->assertSame(1, DB::table('exam_question_options_tbl')->where('is_answer', 1)->count());
        $this->postJson(self::BASE."generations/$id/update", ['draft' => $this->draft()])->assertConflict();
    }

    public function test_short_and_long_answers_and_review_edits_save_through_existing_workflow(): void
    {
        foreach ([2, 3] as $type) {
            $draft = $this->draft(false);
            $draft['questions'][0]['needs_review'] = true;
            $draft['questions'][0]['review_reason'] = 'Check the answer.';
            $this->fake($draft);
            $id = $this->generate(['question_type' => $type]);
            $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertUnprocessable();
            $draft['questions'][0]['needs_review'] = false;
            $draft['questions'][0]['review_reason'] = null;
            $draft['questions'][0]['answer'] = 'Administrator verified answer.';
            $this->postJson(self::BASE."generations/$id/update", ['draft' => $draft])->assertOk();
            $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
        }
        $this->assertSame(2, DB::table('exam_answer_tbl')->where('answer', 'Administrator verified answer.')->count());
        $this->assertSame(0, DB::table('exam_question_options_tbl')->count());
    }

    public function test_shared_scenario_is_created_once_when_saving_subsets(): void
    {
        $draft = $this->draft(true, 2);
        $draft['scenario'] = ['text' => 'A cart accelerates along a track.', 'text_um' => null, 'diagram' => null,
            'source_references' => $draft['questions'][0]['source_references']];
        $this->fake($draft);
        $id = $this->generate(['scenario_based' => true, 'question_presentation_type_id' => 1, 'count' => 2]);
        foreach ([1, 0] as $index) {
            $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [$index]])->assertOk();
        }
        $this->assertSame(1, DB::table('question_scenario_groups_tbl')->count());
        $this->assertSame(2, DB::table('exam_question_tbl')->where('scenario_group_id', 1)->count());
        $this->assertEquals([2, 1], DB::table('exam_question_tbl')->orderBy('id')->pluck('scenario_question_order')->all());
    }

    public function test_private_image_and_pdf_sources_are_sent_inline_to_both_providers(): void
    {
        foreach (['openai', 'gemini'] as $key) {
            $provider = AiProvider::where('key', $key)->first();
            $provider->update(['is_active' => true]);
            $model = AiModel::first();
            $model->update(['ai_provider_id' => $provider->id]);
            $draft = $this->draft();
            $this->fake($draft);
            if ($key === 'gemini') {
                Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
                    'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]],
                ])]);
            }
            $files = [UploadedFile::fake()->image('page.png', 120, 120),
                UploadedFile::fake()->createWithContent('lesson.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF")];
            $result = $this->post(self::BASE.'generate', $this->input(['source_text' => null, 'files' => $files]), ['Accept' => 'application/json'])
                ->assertCreated()->assertJsonCount(2, 'generation.sources');
            $id = $result->json('generation.id');
            $this->get(self::BASE."generations/$id/sources/source-1")->assertOk()->assertHeader('Content-Type', 'image/png');
            if ($key === 'openai') {
                Http::assertSent(fn ($r) => isset($r['input'][0]['content'][4]['file_data'])
                    && str_starts_with($r['input'][0]['content'][2]['image_url'], 'data:image/png;base64,'));
            } else {
                Http::assertSent(fn ($r) => ($r['contents'][0]['parts'][4]['inlineData']['mimeType'] ?? null) === 'application/pdf');
            }
        }
    }

    public function test_malformed_and_unsafe_sources_are_rejected_before_ai_call(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('not-image.png', 'not an image'),
            UploadedFile::fake()->createWithContent('script.svg', '<svg/>'),
            UploadedFile::fake()->createWithContent('bad.pdf', 'not pdf'),
            UploadedFile::fake()->createWithContent('bad.txt', "invalid\0text"),
        ] as $file) {
            $this->post(self::BASE.'generate', $this->input(['files' => [$file]]), ['Accept' => 'application/json'])->assertUnprocessable();
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiQuestionGeneration::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_drafts_are_logged_and_cannot_be_saved(): void
    {
        foreach (['wrong_answer_count', 'bad_reference', 'html'] as $problem) {
            $draft = $this->draft();
            if ($problem === 'wrong_answer_count') {
                $draft['questions'][0]['options'][1]['is_correct'] = true;
            } elseif ($problem === 'bad_reference') {
                $draft['questions'][0]['source_references'][0]['source_id'] = 'source-99';
            } else {
                $draft['questions'][0]['question'] = '<script>alert(1)</script>';
            }
            $this->fake($draft);
            $id = $this->postJson(self::BASE.'generate', $this->input())->assertStatus(502)->json('generation_id');
            $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertConflict();
        }
        $this->assertSame(3, AiRequest::where('error_code', 'invalid_output')->count());
        $this->assertSame(0, DB::table('exam_question_tbl')->count());
    }

    public function test_permissions_scope_and_source_ownership_are_enforced(): void
    {
        $this->fake($this->draft());
        $id = $this->generate();
        $role = Role::create(['name' => 'operator']);
        $user = User::create(['name' => 'Other', 'role_id' => $role->id]);
        $this->actingAs($user, 'api');
        $this->postJson(self::BASE.'generate', $this->input())->assertForbidden();
        $role->permissions()->attach(Permission::where('name', 'questions.generate')->value('id'));
        $this->actingAs($user->fresh(), 'api');
        $this->getJson(self::BASE."generations/$id")->assertNotFound();
        $this->getJson(self::BASE."generations/$id/sources/source-1")->assertNotFound();
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertForbidden();
        DB::table('role_permission_scopes')->insert(['role_id' => $role->id,
            'permission_id' => Permission::where('name', 'questions.generate')->value('id'), 'scope_type' => 'topic', 'scope_id' => 999]);
        $this->postJson(self::BASE.'generate', $this->input())->assertForbidden();
    }

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-source-test-');
        try {
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();

            return UploadedFile::fake()->createWithContent('sources.zip', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_zip_sources_are_read_in_natural_order_without_extracting_paths(): void
    {
        $this->fake($this->draft());
        $zip = $this->zip(['pages/page10.txt' => 'Page ten.', 'pages/page2.txt' => 'Page two.']);
        $this->post(self::BASE.'generate', $this->input(['source_text' => null, 'files' => [$zip]]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('generation.sources.0.name', 'page2.txt')->assertJsonPath('generation.sources.1.name', 'page10.txt');
        Http::assertSent(function ($r) {
            $input = json_decode($r['input'][0]['content'][0]['text'], true);

            return $input['source_texts'][0]['text'] === 'Page two.' && $input['source_texts'][1]['text'] === 'Page ten.';
        });
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_zip_traversal_nested_archives_and_expansion_limits_are_rejected(): void
    {
        foreach ([['../escape.txt' => 'bad'], ['nested.zip' => 'bad'], ['/absolute.txt' => 'bad'],
            ['huge.txt' => str_repeat('x', 100000)], array_fill_keys(array_map(fn ($i) => "file$i.txt", range(1, 11)), 'Text.')] as $entries) {
            $this->post(self::BASE.'generate', $this->input(['source_text' => null, 'files' => [$this->zip($entries)]]), ['Accept' => 'application/json'])
                ->assertUnprocessable();
        }
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function drawing(): array
    {
        return ['kind' => 'drawing', 'alt' => 'Force arrow', 'source_id' => null, 'crop' => [], 'elements' => [
            ['type' => 'arrow', 'x1' => 100, 'y1' => 500, 'x2' => 800, 'y2' => 500, 'label' => null],
            ['type' => 'text', 'x1' => 400, 'y1' => 400, 'x2' => 400, 'y2' => 400, 'label' => 'F = 6 N'],
        ]];
    }

    public function test_question_answer_and_option_diagrams_render_as_png_and_save_as_images(): void
    {
        $draft = $this->draft(false);
        $draft['questions'][0]['question_diagram'] = ['kind' => 'source_crop', 'alt' => 'Book figure', 'source_id' => 'source-1',
            'crop' => [0.1, 0.1, 0.8, 0.8], 'elements' => []];
        $draft['questions'][0]['answer_diagram'] = $this->drawing();
        $this->fake($draft);
        $id = $this->post(self::BASE.'generate', $this->input(['question_type' => 2, 'source_text' => null,
            'include_diagrams' => true, 'files' => [UploadedFile::fake()->image('page.png', 100, 100)]]), ['Accept' => 'application/json'])
            ->assertCreated()->json('generation.id');
        foreach (['question_diagram', 'answer_diagram'] as $field) {
            $response = $this->postJson(self::BASE."generations/$id/diagram-preview", ['diagram' => $draft['questions'][0][$field]])
                ->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertStringStartsWith("\x89PNG", $response->getContent());
        }
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->assertStringContainsString('<img src=', DB::table('exam_answer_tbl')->value('answer'));
        $this->assertStringContainsString('<img src=', DB::table('exam_question_tbl')->value('question'));
        $this->assertEquals(1, DB::table('exam_question_tbl')->value('has_diagram'));

        $draft = $this->draft();
        $draft['questions'][0]['options'][0]['diagram'] = $this->drawing();
        $this->fake($draft);
        $id = $this->generate(['include_diagrams' => true]);
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
        $this->assertStringContainsString('<img src=', DB::table('exam_question_options_tbl')->where('is_answer', 1)->value('option'));
    }

    public function test_invalid_crops_and_drawing_code_are_rejected(): void
    {
        $this->fake($this->draft());
        $id = $this->generate();
        foreach ([
            ['kind' => 'source_crop', 'alt' => 'Bad', 'source_id' => 'source-1', 'crop' => [0, 0, 1, 1], 'elements' => []],
            array_replace($this->drawing(), ['elements' => [['type' => 'script', 'x1' => 0, 'y1' => 0, 'x2' => 0, 'y2' => 0, 'label' => null]]]),
            array_replace($this->drawing(), ['source_id' => '../../secret']),
        ] as $diagram) {
            $this->postJson(self::BASE."generations/$id/diagram-preview", ['diagram' => $diagram])->assertUnprocessable();
        }
    }

    public function test_urdu_and_bilingual_written_questions_save_requested_languages(): void
    {
        foreach (['ur', 'both'] as $language) {
            $draft = $this->draft(false);
            $draft['questions'][0]['question_um'] = 'قوت کی تعریف کریں۔';
            $draft['questions'][0]['answer_um'] = 'قوت جسم کی حرکت کو تبدیل کر سکتی ہے۔';
            if ($language === 'ur') {
                $draft['questions'][0]['question'] = null;
                $draft['questions'][0]['answer'] = null;
            }
            $this->fake($draft);
            $id = $this->generate(['question_type' => 2, 'language' => $language]);
            $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
        }
        $this->assertSame(2, DB::table('exam_answer_tbl')->whereNotNull('answer_um')->count());
        $this->assertSame(1, DB::table('exam_answer_tbl')->whereNull('answer')->count());
    }

    public function test_invalid_selection_text_only_models_and_invalid_hierarchy_do_not_call_ai(): void
    {
        $this->postJson(self::BASE.'generate', $this->input(['count' => 6]))->assertUnprocessable();
        $this->postJson(self::BASE.'generate', $this->input(['topic_id' => 999]))->assertUnprocessable();
        $this->postJson(self::BASE.'generate', $this->input(['question_type' => 2, 'scenario_based' => true, 'question_presentation_type_id' => 1]))->assertUnprocessable();
        AiModel::first()->update(['supports_images' => false]);
        $this->post(self::BASE.'generate', $this->input(['files' => [UploadedFile::fake()->image('page.png')]]), ['Accept' => 'application/json'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_save_rechecks_create_scope_and_rolls_back_all_selected_questions_on_failure(): void
    {
        $this->fake($this->draft(true, 2));
        $id = $this->generate(['count' => 2]);
        $role = Role::create(['name' => 'restricted']);
        $role->permissions()->attach(Permission::whereIn('name', ['questions.generate', 'questions.create'])->pluck('id'));
        $user = auth()->user();
        $user->update(['role_id' => $role->id]);
        $this->actingAs($user->fresh(), 'api');
        DB::table('role_permission_scopes')->insert(['role_id' => $role->id,
            'permission_id' => Permission::where('name', 'questions.create')->value('id'), 'scope_type' => 'topic', 'scope_id' => 999]);
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertForbidden();
        DB::table('role_permission_scopes')->delete();
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0, 99]])->assertUnprocessable();
        $this->assertSame(0, DB::table('exam_question_tbl')->count());
        DB::table('book_unit_topic_tbl')->where('id', 1)->update(['topic_name' => 'Changed topic']);
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertConflict();
    }

    public function test_database_save_failure_rolls_back_questions_and_uploaded_diagrams(): void
    {
        $draft = $this->draft(true, 2);
        $draft['questions'][0]['question_diagram'] = $this->drawing();
        $this->fake($draft);
        $id = $this->generate(['count' => 2, 'include_diagrams' => true]);
        DB::unprepared("CREATE TRIGGER reject_second_question BEFORE INSERT ON exam_question_tbl WHEN NEW.question LIKE '%case 2.%' BEGIN SELECT RAISE(ABORT, 'Simulated save failure'); END;");
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0, 1]])->assertUnprocessable();
        $this->assertSame(0, DB::table('exam_question_tbl')->count());
        $this->assertSame(0, DB::table('exam_question_options_tbl')->count());
        $this->assertSame([], AiQuestionGeneration::find($id)->saved_questions);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_generation_routes_require_admin_authentication(): void
    {
        $this->withMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->postJson(self::BASE.'generate', $this->input())->assertUnauthorized();
        $this->getJson(self::BASE.'generations/1')->assertUnauthorized();
        $this->postJson(self::BASE.'generations/1/save', ['reviewed' => true, 'indices' => [0]])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_unreadable_sources_return_review_flags_without_invented_answers(): void
    {
        $draft = $this->draft();
        $draft['questions'][0]['needs_review'] = true;
        $draft['questions'][0]['review_reason'] = 'The source is incomplete.';
        $draft['questions'][0]['options'] = [];
        $draft['questions'][0]['explanation'] = null;
        $draft['warnings'] = ['Upload a clearer source.'];
        $this->fake($draft);
        $id = $this->generate();
        $this->getJson(self::BASE."generations/$id")->assertOk()->assertJsonPath('generation.draft.warnings.0', 'Upload a clearer source.');
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertUnprocessable();
        $draft['questions'][0]['needs_review'] = false;
        $this->postJson(self::BASE."generations/$id/update", ['draft' => $draft])->assertUnprocessable();
    }

    public function test_context_and_optional_prompt_work_without_source_uploads(): void
    {
        $draft = $this->draft();
        $draft['questions'][0]['source_references'] = [];
        $this->fake($draft);
        $id = $this->generate(['source_text' => null, 'prompt' => 'Create a question on force units.']);
        $this->getJson(self::BASE."generations/$id")->assertOk()->assertJsonCount(0, 'generation.sources');
        $this->postJson(self::BASE."generations/$id/save", ['reviewed' => true, 'indices' => [0]])->assertOk();
    }
}
