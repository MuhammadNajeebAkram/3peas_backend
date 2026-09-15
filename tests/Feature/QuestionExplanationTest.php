<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Models\AiModel;
use App\Models\AiRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AiPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionExplanationTest extends TestCase
{
    private const GENERATE = '/api/admin/auth/question/generate-explanation';

    private const SAVE = '/api/admin/auth/question/save-explanation';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'explanation_test', 'database.connections.explanation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'services.openai.api_key' => 'test-key', 'cache.default' => 'array']);
        DB::purge('explanation_test');
        foreach (['roles' => 'name', 'users' => 'name role_id',
            'role_permission_scopes' => 'role_id permission_id scope_type scope_id',
            'exam_question_tbl' => 'question question_um question_type is_mcq topic_id unit_id book_id cognitive_domain topic_content has_diagram scenario_group_id explanation explanation_um',
            'book_unit_topic_tbl' => 'unit_id topic_name', 'book_unit_tbl' => 'book_id',
            'book_tbl' => 'subject_id class_id curriculum_board_id', 'subject_tbl' => 'subject_name', 'class_tbl' => 'class_name',
            'question_scenario_groups_tbl' => 'scenario_text scenario_text_um scenario_image',
            'exam_question_options_tbl' => 'question_id option option_um is_answer',
        ] as $tableName => $fields) {
            Schema::create($tableName, function (Blueprint $table) use ($fields) {
                $table->id();
                foreach (explode(' ', $fields) as $field) {
                    $table->text($field)->nullable();
                }
                $table->timestamps();
            });
        }
        foreach (['2026_04_01_191541_create_permissions_table.php', '2026_04_01_191631_create_role_permissions_table.php',
            '2026_09_15_000001_create_ai_models_table.php', '2026_09_15_000002_create_ai_requests_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->seed(AiPermissionsSeeder::class);
        Permission::firstOrCreate(['name' => 'questions.view']);
        Permission::firstOrCreate(['name' => 'questions.update']);
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs(User::create(['name' => 'Admin', 'role_id' => Role::where('name', 'super_admin')->value('id')]), 'api');
        AiModel::create(['provider' => 'openai', 'name' => 'Mini', 'model_key' => 'gpt-5.4-mini',
            'is_active' => true, 'is_default' => true, 'input_price_per_million' => '0.75',
            'cached_input_price_per_million' => '0.075', 'output_price_per_million' => '4.5']);
        DB::table('subject_tbl')->insert(['id' => 1, 'subject_name' => 'Physics']);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 9']);
        DB::table('book_tbl')->insert(['id' => 1, 'subject_id' => 1, 'class_id' => 1, 'curriculum_board_id' => 1]);
        DB::table('book_unit_tbl')->insert(['id' => 1, 'book_id' => 1]);
        DB::table('book_unit_topic_tbl')->insert(['id' => 1, 'unit_id' => 1, 'topic_name' => 'Force']);
        DB::table('exam_question_tbl')->insert(['id' => 1, 'question' => 'Find force for mass 2 kg and acceleration 3 m/s².',
            'question_um' => 'قوت معلوم کریں۔', 'question_type' => 1, 'is_mcq' => 1, 'topic_id' => 1,
            'explanation' => 'Existing English', 'explanation_um' => 'Existing Urdu', 'updated_at' => now()]);
        DB::table('exam_question_options_tbl')->insert([
            ['id' => 1, 'question_id' => 1, 'option' => '6 N', 'option_um' => '6 نیوٹن', 'is_answer' => 1],
            ['id' => 2, 'question_id' => 1, 'option' => '3 N', 'option_um' => '3 نیوٹن', 'is_answer' => 0],
        ]);
        Http::preventStrayRequests();
    }

    private function draft(string $language = 'en'): array
    {
        $draft = ['needs_review' => false, 'review_reason' => null];
        if ($language !== 'ur') {
            $draft['explanation'] = 'Using \\(F = ma\\), the force is \\(2 \\times 3 = 6\\,\\text{N}\\).';
        }
        if ($language !== 'en') {
            $draft['explanation_um'] = 'فارمولے \\(F = ma\\) کے مطابق قوت \\(6\\,\\text{N}\\) ہے۔';
        }

        return $draft;
    }

    private function response(array $draft): array
    {
        return ['id' => 'resp_test', 'model' => 'gpt-5.4-mini-2026-03-17', 'status' => 'completed',
            'output' => [['type' => 'reasoning'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($draft)]]]],
            'usage' => ['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 20],
                'output_tokens' => 50, 'output_tokens_details' => ['reasoning_tokens' => 10], 'total_tokens' => 150]];
    }

    public function test_each_language_returns_short_latex_drafts_and_logs_cost_without_saving(): void
    {
        foreach (['en', 'ur', 'both'] as $language) {
            Http::swap((new Factory)->preventStrayRequests());
            Http::fake(['api.openai.com/v1/responses' => Http::response($this->response($this->draft($language)), 200, ['x-request-id' => 'req_test'])]);
            $result = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => $language])->assertOk();
            foreach ($this->draft($language) as $key => $value) {
                $result->assertJsonPath('data.'.$key, $value);
            }
            if ($language === 'en') {
                $result->assertJsonMissingPath('data.explanation_um');
            }
            if ($language === 'ur') {
                $result->assertJsonMissingPath('data.explanation');
            }
            $record = AiRequest::find($result->json('data.ai_request_id'));
            $this->assertSame('successful', $record->status);
            $this->assertEquals(auth()->id(), $record->user_id);
            $this->assertSame('provider', $record->record_source);
            $this->assertSame('0.00028650', $record->estimated_cost);
            $this->assertSame(150, $record->total_tokens);
            $this->assertSame('req_test', $record->provider_request_id);
            $this->assertStringNotContainsString('test-key', $record->toJson());
        }
        Http::assertSent(function ($request) {
            $context = json_decode($request['input'][0]['content'][0]['text'], true);

            return $request['model'] === 'gpt-5.4-mini' && $request['store'] === false
                && $request['text']['format']['strict'] === true
                && $context['subject'] === 'Physics' && count($context['options']) === 2
                && $context['correct_option_id'] === 1;
        });
        $this->assertSame('Existing English', DB::table('exam_question_tbl')->value('explanation'));
        $this->assertSame(3, AiRequest::count());
    }

    public function test_save_preserves_other_language_and_records_editor(): void
    {
        Http::fake(['*' => Http::response($this->response($this->draft()))]);
        $id = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk()->json('data.ai_request_id');
        $this->postJson(self::SAVE, ['question_id' => 1, 'ai_request_id' => $id, 'language' => 'ur', 'explanation_um' => 'جواب'])->assertUnprocessable();
        $this->postJson(self::SAVE, ['question_id' => '1', 'ai_request_id' => $id, 'language' => 'en',
            'explanation' => $this->draft()['explanation']])->assertOk();
        $this->assertSame('Existing Urdu', DB::table('exam_question_tbl')->value('explanation_um'));
        $this->assertEquals(auth()->id(), AiRequest::find($id)->metadata['saved_by']);
        $this->postJson('/api/admin/auth/ai-requests/update/'.$id, ['metadata' => []])->assertConflict();
        $this->postJson(self::SAVE, ['question_id' => 1, 'ai_request_id' => $id, 'language' => 'en', 'explanation' => 'Another edit'])->assertConflict();
    }

    public function test_both_languages_save_and_stale_question_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->response($this->draft('both')))]);
        $id = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'both'])->assertOk()->json('data.ai_request_id');
        $payload = ['question_id' => 1, 'ai_request_id' => $id, 'language' => 'both',
            'explanation' => $this->draft('both')['explanation'], 'explanation_um' => $this->draft('both')['explanation_um']];
        DB::table('exam_question_options_tbl')->where('id', 1)->update(['option' => '7 N']);
        $this->postJson(self::SAVE, $payload)->assertConflict();
        DB::table('exam_question_options_tbl')->where('id', 1)->update(['option' => '6 N']);
        $this->postJson(self::SAVE, $payload)->assertOk()->assertJsonPath('data.explanation_um', $payload['explanation_um']);
    }

    public function test_invalid_questions_models_and_concurrent_requests_do_not_call_openai(): void
    {
        Http::fake();
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'fr'])->assertUnprocessable();
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en', 'model_id' => 999])->assertUnprocessable();
        $this->postJson(self::GENERATE, ['question_id' => 999, 'language' => 'en'])->assertNotFound();
        DB::table('exam_question_options_tbl')->where('id', 2)->update(['is_answer' => 1]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertUnprocessable();
        DB::table('exam_question_options_tbl')->where('id', 2)->update(['is_answer' => 0]);
        $lock = Cache::lock('question-explanation:'.auth()->id().':1', 210);
        $lock->get();
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertConflict();
        $lock->release();
        config(['services.openai.api_key' => null]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, AiRequest::count());
    }

    public function test_retries_have_separate_records_and_timeouts_have_unknown_usage(): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'private provider detail']], 429)
            ->push($this->response($this->draft()))]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk();
        $records = AiRequest::orderBy('id')->get();
        $this->assertSame('failed', $records[0]->status);
        $this->assertSame('successful', $records[1]->status);
        $this->assertSame($records[0]->operation_id, $records[1]->operation_id);
        $this->assertSame(2, $records[1]->attempt_number);
        $this->assertNull($records[0]->input_tokens);
        $this->assertStringNotContainsString('private provider detail', $records[0]->toJson());
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(fn () => throw new ConnectionException('Sensitive exception detail'));
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertStatus(504);
        $this->assertSame(3, AiRequest::count());
        $this->assertNull(AiRequest::latest('id')->first()->estimated_cost);
    }

    public function test_refusals_incomplete_and_invalid_outputs_are_logged_without_returning_a_draft(): void
    {
        $refused = $this->response($this->draft());
        $refused['output'] = [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No']]]];
        $incomplete = $this->response($this->draft());
        $incomplete['status'] = 'incomplete';
        $long = $this->response(['explanation' => str_repeat('word ', 41), 'needs_review' => false, 'review_reason' => null]);
        $badLatex = $this->response(['explanation' => 'Force is \\(6 N', 'needs_review' => false, 'review_reason' => null]);
        foreach ([[$refused, 'refused'], [$incomplete, 'incomplete'], [$long, 'failed'], [$badLatex, 'failed']] as [$body, $status]) {
            Http::swap((new Factory)->preventStrayRequests());
            Http::fake(['*' => Http::response($body)]);
            $response = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertStatus(502);
            $record = AiRequest::find($response->json('ai_request_id'));
            $this->assertSame($status, $record->status);
            $this->assertSame(150, $record->total_tokens);
        }
    }

    public function test_review_flag_and_diagram_context(): void
    {
        $draft = ['explanation' => null, 'needs_review' => true, 'review_reason' => 'The marked answer appears incorrect.'];
        Http::fake(['*' => Http::response($this->response($draft))]);
        DB::table('exam_question_tbl')->where('id', 1)->update(['has_diagram' => 1]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertUnprocessable();
        DB::table('exam_question_tbl')->where('id', 1)->update(['question' => '<p>Calculate the force.</p><img src="https://example.com/force.png">']);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk()->assertJsonPath('data.needs_review', true);
        Http::assertSent(fn ($request) => $request['input'][0]['content'][1] === ['type' => 'input_image', 'image_url' => 'https://example.com/force.png']);
    }

    public function test_permissions_and_question_scopes_block_generation_and_save(): void
    {
        Http::fake(['*' => Http::response($this->response($this->draft()))]);
        $role = Role::create(['name' => 'editor']);
        $user = User::create(['name' => 'Editor', 'role_id' => $role->id]);
        $this->actingAs($user, 'api');
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertForbidden();
        $role->permissions()->sync(Permission::whereIn('name', ['questions.view', 'questions.generate-explanation', 'questions.update'])->pluck('id'));
        $this->actingAs($user->fresh(), 'api');
        foreach (['questions.view', 'questions.generate-explanation'] as $permission) {
            DB::table('role_permission_scopes')->insert(['role_id' => $role->id,
                'permission_id' => Permission::where('name', $permission)->value('id'), 'scope_type' => 'subject', 'scope_id' => 999]);
            $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertForbidden();
            DB::table('role_permission_scopes')->delete();
        }
        Http::assertNothingSent();
        $id = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk()->json('data.ai_request_id');
        DB::table('role_permission_scopes')->insert(['role_id' => $role->id,
            'permission_id' => Permission::where('name', 'questions.update')->value('id'), 'scope_type' => 'book', 'scope_id' => 999]);
        $this->postJson(self::SAVE, ['question_id' => 1, 'ai_request_id' => $id, 'language' => 'en', 'explanation' => 'Short explanation.'])->assertForbidden();
        $this->withMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertUnauthorized();
    }

    public function test_missing_usage_and_unknown_prices_do_not_create_zero_cost_estimates(): void
    {
        $response = $this->response($this->draft());
        unset($response['usage']);
        Http::fake(['*' => Http::response($response)]);
        $id = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk()->json('data.ai_request_id');
        $record = AiRequest::find($id);
        $this->assertNull($record->input_tokens);
        $this->assertNull($record->estimated_cost);
        AiModel::first()->update(['output_price_per_million' => null]);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*' => Http::response($this->response($this->draft()))]);
        $id = $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk()->json('data.ai_request_id');
        $this->assertNull(AiRequest::find($id)->estimated_cost);
    }

    public function test_direct_book_context_and_latex_inequalities_are_preserved(): void
    {
        DB::table('exam_question_tbl')->where('id', 1)->update(['topic_id' => null, 'book_id' => 1, 'question' => 'Which value satisfies \\(x<2\\)?']);
        $draft = ['explanation' => 'The value satisfies \\(x<2\\).', 'needs_review' => false, 'review_reason' => null];
        Http::fake(['*' => Http::response($this->response($draft))]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk()->assertJsonPath('data.explanation', $draft['explanation']);
        Http::assertSent(function ($request) {
            $context = json_decode($request['input'][0]['content'][0]['text'], true);

            return $context['question'] === 'Which value satisfies \\(x<2\\)?' && $context['subject'] === 'Physics';
        });
    }

    public function test_editor_latex_attributes_are_sent_for_questions_options_and_scenarios(): void
    {
        $formula = 'R=\\{(a,b)/a,b\\in Z \\Lambda a + b=0\\}';
        DB::table('exam_question_tbl')->where('id', 1)->update([
            'question' => '<p>If <span data-latex="'.$formula.'" data-type="inline-math"></span>, then:</p>',
            'question_um' => '<p>اگر <span data-latex="x &lt; y &amp; y &gt; 0" data-type="inline-math"></span> ہو۔</p>',
            'scenario_group_id' => 1,
        ]);
        DB::table('question_scenario_groups_tbl')->insert(['id' => 1,
            'scenario_text' => '<div data-type="block-math" data-latex="\\frac{1}{2}"><span>rendered preview</span></div>',
            'scenario_text_um' => '<p><span class="ql-formula" data-value="x^2">duplicate preview</span></p>']);
        DB::table('exam_question_options_tbl')->where('id', 1)->update([
            'option' => '<p><span data-latex="\\{(0,0)\\}" data-type="inline-math"></span></p>',
            'option_um' => '<span data-latex="\\(x=0\\)">rendered</span>',
        ]);
        Http::fake(['*' => Http::response($this->response($this->draft('both')))]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'both'])->assertOk();
        Http::assertSent(function ($request) use ($formula) {
            $context = json_decode($request['input'][0]['content'][0]['text'], true);
            $this->assertSame('If \\('.$formula.'\\), then:', $context['question']);
            $this->assertSame('اگر \\(x < y & y > 0\\) ہو۔', $context['question_ur']);
            $this->assertSame('\\[\\frac{1}{2}\\]', $context['scenario']);
            $this->assertSame('\\(x^2\\)', $context['scenario_ur']);
            $this->assertSame('\\(\\{(0,0)\\}\\)', $context['options'][0]['text']);
            $this->assertSame('\\(x=0\\)', $context['options'][0]['text_ur']);

            return true;
        });
    }

    public function test_raw_math_is_not_parsed_as_html_inside_a_formatted_question(): void
    {
        $question = '<p>If \\(a<b \\text{ and } c>d\\), compare $$x<y$$ and $z>w$.</p>';
        DB::table('exam_question_tbl')->where('id', 1)->update(['question' => $question]);
        Http::fake(['*' => Http::response($this->response($this->draft()))]);
        $this->postJson(self::GENERATE, ['question_id' => 1, 'language' => 'en'])->assertOk();
        Http::assertSent(function ($request) {
            $context = json_decode($request['input'][0]['content'][0]['text'], true);

            return $context['question'] === 'If \\(a<b \\text{ and } c>d\\), compare $$x<y$$ and $z>w$.';
        });
    }
}
