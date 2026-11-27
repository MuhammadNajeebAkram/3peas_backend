<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Services\BulkQuestionExplanationService;
use App\Http\Services\QuestionExplanationService;
use App\Jobs\ProcessBulkExplanationItemJob;
use App\Models\AiExplanationBatch;
use App\Models\AiExplanationBatchItem;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AiPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BulkQuestionExplanationTest extends TestCase
{
    private const BASE = '/api/admin/auth/question/bulk-explanations';

    private User $superAdmin;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'bulk_explanation_test',
            'database.connections.bulk_explanation_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'services.openai.api_key' => 'test-key',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        DB::purge('bulk_explanation_test');

        foreach ([
            'roles' => 'name',
            'users' => 'name role_id',
            'role_permission_scopes' => 'role_id permission_id scope_type scope_id',
            'exam_question_tbl' => 'question question_um question_type is_mcq topic_id unit_id book_id cognitive_domain topic_content has_diagram scenario_group_id question_presentation_type_id activate status explanation explanation_um',
            'book_unit_topic_tbl' => 'unit_id topic_name',
            'book_unit_tbl' => 'book_id',
            'book_tbl' => 'subject_id class_id curriculum_board_id',
            'subject_tbl' => 'subject_name',
            'class_tbl' => 'class_name',
            'question_scenario_groups_tbl' => 'scenario_text scenario_text_um scenario_image',
            'question_presentation_type_tbl' => 'type_name code activate sort_order',
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

        foreach ([
            '2026_04_01_191541_create_permissions_table.php',
            '2026_04_01_191631_create_role_permissions_table.php',
            '2026_09_15_000001_create_ai_models_table.php',
            '2026_09_15_000002_create_ai_requests_table.php',
            '2026_09_16_000001_create_ai_providers_table.php',
            '2026_09_16_000002_add_ai_model_image_capability.php',
            '2026_11_27_000001_create_ai_explanation_batches_table.php',
            '2026_11_27_000002_create_ai_explanation_batch_items_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        $this->seed(AiPermissionsSeeder::class);
        Permission::firstOrCreate(['name' => 'questions.view']);
        Permission::firstOrCreate(['name' => 'questions.update']);

        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);

        $superRoleId = Role::where('name', 'super_admin')->value('id');
        Role::find($superRoleId)->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', ['questions.view', 'questions.update', 'questions.bulk-generate-explanation'])->pluck('id')
        );

        $this->superAdmin = User::create(['name' => 'Super Admin', 'role_id' => $superRoleId]);
        $this->actingAs($this->superAdmin, 'api');

        $this->model = AiModel::create([
            'ai_provider_id' => AiProvider::where('key', 'openai')->value('id'),
            'name' => 'Mini',
            'model_key' => 'gpt-5.4-mini',
            'is_active' => true,
            'is_default' => true,
            'supports_images' => true,
            'input_price_per_million' => '0.75',
            'cached_input_price_per_million' => '0.075',
            'output_price_per_million' => '4.5',
            'currency' => 'USD',
        ]);

        // Academic hierarchy
        DB::table('subject_tbl')->insert([
            ['id' => 1, 'subject_name' => 'Physics'],
            ['id' => 2, 'subject_name' => 'Chemistry'],
        ]);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => 'Grade 9']);
        DB::table('book_tbl')->insert([
            ['id' => 1, 'subject_id' => 1, 'class_id' => 1, 'curriculum_board_id' => 1],
            ['id' => 2, 'subject_id' => 2, 'class_id' => 1, 'curriculum_board_id' => 2],
        ]);
        DB::table('book_unit_tbl')->insert([
            ['id' => 1, 'book_id' => 1],
            ['id' => 2, 'book_id' => 2],
        ]);
        DB::table('book_unit_topic_tbl')->insert([
            ['id' => 1, 'unit_id' => 1, 'topic_name' => 'Force'],
            ['id' => 2, 'unit_id' => 2, 'topic_name' => 'Atoms'],
        ]);
        DB::table('question_presentation_type_tbl')->insert([
            ['id' => 1, 'type_name' => 'Straight MCQ', 'code' => 'straight', 'activate' => 1, 'sort_order' => 1],
            ['id' => 2, 'type_name' => 'Scenario MCQ', 'code' => 'scenario', 'activate' => 1, 'sort_order' => 2],
        ]);
        DB::table('question_scenario_groups_tbl')->insert([
            ['id' => 1, 'scenario_text' => 'A car accelerates uniformly from rest.', 'scenario_text_um' => 'ایک کار یکساں اسراع سے چلتی ہے۔', 'scenario_image' => null],
        ]);

        // Seed diverse questions:
        // Q1: Missing BOTH (legacy NULL presentation type = straight MCQ, inherited unit/book via topic_id=1)
        $this->seedQuestion(1, ['explanation' => null, 'explanation_um' => '<p><br></p>', 'question_presentation_type_id' => null]);
        // Q2: Missing EN only (Urdu exists)
        $this->seedQuestion(2, ['explanation' => '<p>&nbsp;</p>', 'explanation_um' => '<p>موجود اردو وضاحت۔</p>', 'question_presentation_type_id' => 1]);
        // Q3: Missing UR only (English exists)
        $this->seedQuestion(3, ['explanation' => '<p>Existing English explanation.</p>', 'explanation_um' => '', 'question_presentation_type_id' => 1]);
        // Q4: Scenario MCQ with diagram image, missing BOTH
        $this->seedQuestion(4, [
            'question' => '<p>Use the diagram to find acceleration.</p><img src="https://cdn.example.com/q4.png">',
            'has_diagram' => 1,
            'scenario_group_id' => 1,
            'question_presentation_type_id' => 2,
            'explanation' => null,
            'explanation_um' => null,
        ]);
        // Q5: Complete (both EN and UR present)
        $this->seedQuestion(5, ['explanation' => '<p>Done EN</p>', 'explanation_um' => '<p>Done UR</p>']);
        // Q6: Ineligible — non-MCQ
        $this->seedQuestion(6, ['is_mcq' => 0, 'question_type' => 2, 'explanation' => null, 'explanation_um' => null]);
        // Q7: Ineligible — invalid answer structure (2 correct answers)
        $this->seedQuestion(7, ['explanation' => null, 'explanation_um' => null], [
            ['option' => 'A', 'option_um' => 'الف', 'is_answer' => 1],
            ['option' => 'B', 'option_um' => 'ب', 'is_answer' => 1],
        ]);
        // Q8: Chemistry (subject 2, board 2), missing BOTH
        $this->seedQuestion(8, ['topic_id' => 2, 'explanation' => null, 'explanation_um' => null]);

        Http::preventStrayRequests();
    }

    private function seedQuestion(int $id, array $overrides = [], ?array $options = null): void
    {
        DB::table('exam_question_tbl')->insert(array_merge([
            'id' => $id,
            'question' => "Question #{$id} text",
            'question_um' => "سوال #{$id}",
            'question_type' => 1,
            'is_mcq' => 1,
            'topic_id' => 1,
            'unit_id' => null,
            'book_id' => null,
            'has_diagram' => 0,
            'scenario_group_id' => null,
            'question_presentation_type_id' => null,
            'activate' => 1,
            'status' => 'published',
            'explanation' => null,
            'explanation_um' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        $rows = $options ?? [
            ['option' => '6 N', 'option_um' => '6 نیوٹن', 'is_answer' => 1],
            ['option' => '3 N', 'option_um' => '3 نیوٹن', 'is_answer' => 0],
        ];
        foreach ($rows as $row) {
            DB::table('exam_question_options_tbl')->insert(array_merge(['question_id' => $id], $row));
        }
    }

    private function openAiResponse(array $draft): array
    {
        return [
            'id' => 'resp_bulk_'.uniqid(),
            'status' => 'completed',
            'model' => 'gpt-5.4-mini',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($draft, JSON_UNESCAPED_UNICODE)]],
            ]],
            'usage' => [
                'input_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens' => 40,
                'output_tokens_details' => ['reasoning_tokens' => 0],
                'total_tokens' => 140,
            ],
        ];
    }

    public function test_preview_filters_counts_legacy_null_presentation_and_cost_estimate(): void
    {
        $response = $this->postJson(self::BASE.'/preview', [
            'subject_id' => 1,
            'language' => 'both',
        ])->assertOk();

        $response->assertJsonPath('data.summary.total_eligible_questions', 4)
            ->assertJsonPath('data.summary.missing_both_count', 2)
            ->assertJsonPath('data.summary.missing_en_only_count', 1)
            ->assertJsonPath('data.summary.missing_ur_only_count', 1)
            ->assertJsonPath('data.summary.with_diagrams_or_images_count', 1)
            ->assertJsonPath('data.summary.invalid_mcq_count', 1)
            ->assertJsonPath('data.summary.invalid_answer_structure_count', 1)
            ->assertJsonPath('data.summary.already_complete_count', 1)
            ->assertJsonPath('data.cost_estimate.is_estimate', true)
            ->assertJsonPath('data.cost_estimate.pricing_available', true);

        $items = collect($response->json('data.items'))->keyBy('question_id');
        $this->assertSame('both', $items[1]['effective_language']);
        $this->assertTrue($items[1]['is_straight_mcq']);
        $this->assertSame('en', $items[2]['effective_language']);
        $this->assertSame('ur', $items[3]['effective_language']);
        $this->assertTrue($items[4]['has_diagram_or_image']);

        // Legacy NULL presentation type (Q1) is included when filtering by Straight MCQ (id=1)
        $straight = $this->postJson(self::BASE.'/preview', [
            'subject_id' => 1,
            'question_presentation_type_id' => 1,
            'language' => 'both',
        ])->assertOk();
        $straightIds = collect($straight->json('data.items'))->pluck('question_id')->all();
        $this->assertSame([1, 2, 3], $straightIds);

        // Non-vision model excludes diagram question Q4 as ineligible
        $this->model->update(['supports_images' => false]);
        $noVision = $this->postJson(self::BASE.'/preview', [
            'subject_id' => 1,
            'language' => 'both',
            'model_id' => $this->model->id,
        ])->assertOk();
        $this->assertSame([1, 2, 3], collect($noVision->json('data.items'))->pluck('question_id')->all());
        $this->assertSame(3, $noVision->json('data.summary.ineligible_count'));

        // Missing pricing never invents a zero cost
        $this->model->update(['input_price_per_million' => null, 'output_price_per_million' => null]);
        $this->postJson(self::BASE.'/preview', ['subject_id' => 1, 'language' => 'both'])
            ->assertOk()
            ->assertJsonPath('data.cost_estimate.pricing_available', false)
            ->assertJsonPath('data.cost_estimate.estimated_cost', null);

        // Preview never modifies database
        $this->assertSame(0, AiExplanationBatch::count());
        $this->assertSame(0, AiRequest::count());
    }

    public function test_batch_creation_validation_queue_dispatch_and_draft_only_generation(): void
    {
        Queue::fake();

        // Reject overwrite_existing = true and batch_size > 100
        $this->postJson(self::BASE.'/batches', [
            'subject_id' => 1,
            'language' => 'both',
            'overwrite_existing' => true,
        ])->assertUnprocessable();

        $this->postJson(self::BASE.'/batches', [
            'subject_id' => 1,
            'language' => 'both',
            'batch_size' => 101,
        ])->assertUnprocessable();

        // Create batch for Q1 (both), Q2 (en only), Q3 (ur only)
        $createRes = $this->postJson(self::BASE.'/batches', [
            'subject_id' => 1,
            'question_ids' => [1, 2, 3],
            'language' => 'both',
            'batch_size' => 25,
        ])->assertCreated();

        $batchId = (int) $createRes->json('data.id');
        $this->assertSame(3, $createRes->json('data.total_items'));
        $this->assertFalse($createRes->json('data.overwrite_existing'));

        Queue::assertPushedOn('ai-explanations', ProcessBulkExplanationItemJob::class);
        Queue::assertPushed(ProcessBulkExplanationItemJob::class, 3);

        // Execute jobs synchronously with mocked OpenAI responses
        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push($this->openAiResponse([
                    'explanation' => 'Using \\(F = ma\\), force is \\(6\\text{ N}\\).',
                    'explanation_um' => 'فارمولا \\(F = ma\\) کے مطابق قوت 6 نیوٹن ہے۔',
                    'needs_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->openAiResponse([
                    'explanation' => 'Force equals \\(2 \\times 3 = 6\\text{ N}\\).',
                    'needs_review' => false,
                    'review_reason' => null,
                ]))
                ->push($this->openAiResponse([
                    'explanation_um' => 'قوت \\(6\\text{ N}\\) بنتی ہے۔',
                    'needs_review' => false,
                    'review_reason' => null,
                ])),
        ]);

        $service = app(BulkQuestionExplanationService::class);
        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('question_id')->get();
        foreach ($items as $item) {
            (new ProcessBulkExplanationItemJob($item->id))->handle($service);
        }

        // Duplicate job execution must be idempotent and NOT send extra AI calls
        (new ProcessBulkExplanationItemJob($items[0]->id))->handle($service);
        Http::assertSentCount(3);

        // Verify drafts stored, NO auto-save to exam_question_tbl!
        $this->assertNull(DB::table('exam_question_tbl')->where('id', 1)->value('explanation'));
        $this->assertSame('<p>&nbsp;</p>', DB::table('exam_question_tbl')->where('id', 2)->value('explanation'));
        $this->assertSame('<p>موجود اردو وضاحت۔</p>', DB::table('exam_question_tbl')->where('id', 2)->value('explanation_um'));
        $this->assertSame('<p>Existing English explanation.</p>', DB::table('exam_question_tbl')->where('id', 3)->value('explanation'));

        // Verify telemetry trigger_type = 'bulk'
        $this->assertSame(['bulk', 'bulk', 'bulk'], AiRequest::orderBy('id')->pluck('trigger_type')->all());

        $batch = AiExplanationBatch::findOrFail($batchId);
        $this->assertSame(AiExplanationBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(3, $batch->generated_items);
        $this->assertSame(0, $batch->saved_items);
        $this->assertSame(420, $batch->actual_total_tokens);
        $this->assertNotNull($batch->actual_cost);

        // Apply approved drafts for Q2 (EN only) and Q3 (UR only)
        $this->postJson(self::BASE."/batches/{$batchId}/apply", [
            'item_ids' => [$items[1]->id, $items[2]->id],
        ])->assertOk()->assertJsonPath('data.saved_count', 2);

        // Verify Q2 preserved existing Urdu and Q3 preserved existing English, while saving Tiptap HTML
        $q2 = DB::table('exam_question_tbl')->where('id', 2)->first();
        $this->assertStringContainsString('data-type="inline-math"', (string) $q2->explanation);
        $this->assertSame('<p>موجود اردو وضاحت۔</p>', $q2->explanation_um);

        $q3 = DB::table('exam_question_tbl')->where('id', 3)->first();
        $this->assertSame('<p>Existing English explanation.</p>', $q3->explanation);
        $this->assertStringContainsString('data-type="inline-math"', (string) $q3->explanation_um);

        // Q1 was NOT selected in apply -> still untouched
        $this->assertNull(DB::table('exam_question_tbl')->where('id', 1)->value('explanation'));
    }

    public function test_needs_review_quarantine_and_stale_conflict_rejection(): void
    {
        Queue::fake();

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertCreated()->json('data.id');

        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push($this->openAiResponse([
                    'explanation' => null,
                    'needs_review' => true,
                    'review_reason' => 'The marked answer appears incorrect.',
                ]))
                ->push($this->openAiResponse([
                    'explanation' => 'Force is \\(6\\text{ N}\\).',
                    'needs_review' => false,
                    'review_reason' => null,
                ])),
        ]);

        $service = app(BulkQuestionExplanationService::class);
        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('question_id')->get();
        foreach ($items as $item) {
            (new ProcessBulkExplanationItemJob($item->id))->handle($service);
        }

        $items = $items->map->fresh();
        $this->assertSame(AiExplanationBatchItem::STATUS_NEEDS_REVIEW, $items[0]->status);
        $this->assertTrue($items[0]->needs_review);
        $this->assertSame('The marked answer appears incorrect.', $items[0]->review_reason);

        // Reject saving needs_review item
        $this->postJson(self::BASE."/batches/{$batchId}/apply", [
            'item_ids' => [$items[0]->id],
        ])->assertUnprocessable();

        // Mutate Q2 option after generation -> stale context must return 409 Conflict
        DB::table('exam_question_options_tbl')->where('question_id', 2)->where('is_answer', 1)->update(['option' => '9 N']);
        $this->postJson(self::BASE."/batches/{$batchId}/apply", [
            'item_ids' => [$items[1]->id],
        ])->assertConflict();

        // Restore option, but fill explanation concurrently -> stale explanation hash returns 409 Conflict (never overwrites!)
        DB::table('exam_question_options_tbl')->where('question_id', 2)->where('is_answer', 1)->update(['option' => '6 N']);
        DB::table('exam_question_tbl')->where('id', 2)->update(['explanation' => '<p>Manually entered explanation.</p>']);
        $this->postJson(self::BASE."/batches/{$batchId}/apply", [
            'item_ids' => [$items[1]->id],
        ])->assertConflict();
        $this->assertSame('<p>Manually entered explanation.</p>', DB::table('exam_question_tbl')->where('id', 2)->value('explanation'));
    }

    public function test_provider_failure_retry_failed_cancellation_lock_and_concurrent_fill(): void
    {
        Queue::fake();

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2, 3],
            'language' => 'both',
        ])->assertCreated()->json('data.id');

        $service = app(BulkQuestionExplanationService::class);
        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('question_id')->get();

        // 1) Item 0 fails with HTTP 404 (permanent failure, no retry)
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Not found']], 404)]);
        $res0 = $service->processItem($items[0]->id, 1, 3);
        $this->assertSame(AiExplanationBatchItem::STATUS_FAILED, $res0['status']);
        $this->assertFalse($res0['retryable']);

        // 2) Item 1 hits global question lock -> retryable on attempt 1, does NOT call AI
        $lock = Cache::lock(QuestionExplanationService::questionLockKey(2), 210);
        $lock->get();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake();
        $resLock = $service->processItem($items[1]->id, 1, 3);
        $this->assertTrue($resLock['retryable']);
        $this->assertSame(AiExplanationBatchItem::STATUS_PENDING, $resLock['status']);
        Http::assertNothingSent();
        $lock->release();

        // 3) Fill Q2 explanation concurrently before retry -> item marked skipped, zero AI call
        DB::table('exam_question_tbl')->where('id', 2)->update(['explanation' => '<p>Filled concurrently.</p>']);
        $resSkipped = $service->processItem($items[1]->id, 2, 3);
        $this->assertSame(AiExplanationBatchItem::STATUS_SKIPPED, $resSkipped['status']);
        $this->assertSame('explanation_already_present', $items[1]->fresh()->error_code);
        Http::assertNothingSent();

        // 4) Cancel batch while Item 2 is still pending -> Item 2 becomes skipped, Item 0 stays failed
        $this->postJson(self::BASE."/batches/{$batchId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', AiExplanationBatch::STATUS_CANCELLED)
            ->assertJsonPath('data.skipped_items', 2)
            ->assertJsonPath('data.failed_items', 1);

        // 5) Retry failed items -> ONLY Item 0 is reset to pending and re-dispatched
        Queue::fake();
        $this->postJson(self::BASE."/batches/{$batchId}/retry-failed")
            ->assertOk()
            ->assertJsonPath('data.pending_items', 1);
        Queue::assertPushed(ProcessBulkExplanationItemJob::class, 1);

        // Re-run Item 0 with success
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'explanation' => 'Retried EN explanation.',
                'explanation_um' => 'دوبارہ کوشش شدہ اردو وضاحت۔',
                'needs_review' => false,
                'review_reason' => null,
            ])),
        ]);
        $service->processItem($items[0]->id, 1, 3);
        $this->assertSame(AiExplanationBatchItem::STATUS_GENERATED, $items[0]->fresh()->status);
    }

    public function test_permissions_batch_isolation_and_scope_recheck(): void
    {
        Queue::fake();

        $editorRole = Role::create(['name' => 'editor']);
        $editor = User::create(['name' => 'Editor', 'role_id' => $editorRole->id]);
        $otherEditor = User::create(['name' => 'Other Editor', 'role_id' => $editorRole->id]);

        // Without questions.bulk-generate-explanation -> 403
        $editorRole->permissions()->sync(Permission::whereIn('name', ['questions.view', 'questions.generate-explanation'])->pluck('id'));
        $this->actingAs($editor->fresh(), 'api');
        $this->postJson(self::BASE.'/preview', ['subject_id' => 1])->assertForbidden();
        $this->postJson(self::BASE.'/batches', ['question_ids' => [1], 'language' => 'en'])->assertForbidden();

        // Grant bulk permission + restrict scope to subject 1 only
        $editorRole->permissions()->sync(Permission::whereIn('name', ['questions.view', 'questions.bulk-generate-explanation'])->pluck('id'));
        DB::table('role_permission_scopes')->insert([
            'role_id' => $editorRole->id,
            'permission_id' => Permission::where('name', 'questions.bulk-generate-explanation')->value('id'),
            'scope_type' => 'subject',
            'scope_id' => 1,
        ]);

        // Preview sees subject 1 questions but NOT subject 2 (Q8)
        $this->actingAs($editor->fresh(), 'api');
        $previewIds = collect($this->postJson(self::BASE.'/preview', ['language' => 'both'])->assertOk()->json('data.items'))
            ->pluck('question_id')
            ->all();
        $this->assertNotContains(8, $previewIds);

        // Create batch as editor
        $batchId = (int) $this->postJson(self::BASE.'/batches', ['question_ids' => [1], 'language' => 'en'])
            ->assertCreated()
            ->json('data.id');

        // Other non-super-admin editor cannot view or cancel this batch
        $this->actingAs($otherEditor->fresh(), 'api');
        $this->getJson(self::BASE."/batches/{$batchId}")->assertForbidden();
        $this->postJson(self::BASE."/batches/{$batchId}/cancel")->assertForbidden();

        // Revoke scope before job runs -> job fails cleanly with unauthorized_scope and makes zero AI calls
        DB::table('role_permission_scopes')->where('role_id', $editorRole->id)->update(['scope_id' => 999]);
        $item = AiExplanationBatchItem::where('batch_id', $batchId)->firstOrFail();
        Http::fake();
        $res = app(BulkQuestionExplanationService::class)->processItem($item->id, 1, 3);
        $this->assertSame(AiExplanationBatchItem::STATUS_FAILED, $res['status']);
        $this->assertSame('unauthorized_scope', $item->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_rate_limited_batch_exceeding_20_items_completes_without_premature_failure(): void
    {
        Queue::fake();
        RateLimiter::clear('ai-bulk-explanations');

        // Seed 25 missing-explanation questions (IDs 101..125)
        $questionIds = [];
        for ($i = 101; $i <= 125; $i++) {
            $this->seedQuestion($i, ['explanation' => null, 'explanation_um' => '<p>UR</p>']);
            $questionIds[] = $i;
        }

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => $questionIds,
            'language' => 'en',
            'batch_size' => 25,
        ])->assertCreated()->json('data.id');

        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('id')->get();
        $this->assertCount(25, $items);

        // Verify job configuration prevents rate-limit attempt exhaustion
        $sampleJob = new ProcessBulkExplanationItemJob($items[0]->id);
        $this->assertSame(0, $sampleJob->tries);
        $this->assertSame(3, $sampleJob->maxExceptions);
        $this->assertTrue($sampleJob->retryUntil()->getTimestamp() > now()->addMinutes(60)->getTimestamp());

        Http::fake([
            'api.openai.com/v1/responses' => fn () => Http::response($this->openAiResponse([
                'explanation' => 'Clear explanation for rate-limit test.',
                'needs_review' => false,
                'review_reason' => null,
            ])),
        ]);

        $service = app(BulkQuestionExplanationService::class);
        $middleware = $sampleJob->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);

        $runThroughPipeline = function (AiExplanationBatchItem $item) use ($service): array {
            $job = new ProcessBulkExplanationItemJob($item->id);
            $releaseRecord = (object) ['released' => false, 'delay' => null];
            $fakeQueueJob = new class($releaseRecord) implements \Illuminate\Contracts\Queue\Job
            {
                public function __construct(private object $record) {}
                public function release($delay = 0): void { $this->record->released = true; $this->record->delay = $delay; }
                public function isReleased(): bool { return $this->record->released; }
                public function uuid() { return null; }
                public function getJobId() { return 'fake-job-id'; }
                public function payload() { return []; }
                public function fire() {}
                public function delete() {}
                public function isDeleted() { return false; }
                public function isDeletedOrReleased() { return $this->record->released; }
                public function attempts() { return 1; }
                public function hasFailed() { return false; }
                public function markAsFailed() {}
                public function fail($e = null) {}
                public function maxTries() { return 0; }
                public function maxExceptions() { return 3; }
                public function timeout() { return 150; }
                public function retryUntil() { return null; }
                public function getName() { return ProcessBulkExplanationItemJob::class; }
                public function resolveName() { return ProcessBulkExplanationItemJob::class; }
                public function getConnectionName() { return 'database_ai_explanations'; }
                public function getQueue() { return 'ai-explanations'; }
                public function getRawBody() { return ''; }
            };
            $job->setJob($fakeQueueJob);

            (new Pipeline($this->app))
                ->send($job)
                ->through($job->middleware())
                ->then(fn (ProcessBulkExplanationItemJob $j) => $j->handle($service));

            return ['released' => $releaseRecord->released, 'delay' => $releaseRecord->delay];
        };

        $releasedItems = [];

        // Simulate minute 1 through the REAL RateLimited middleware via Pipeline:
        // First 20 items execute; items 21..25 are released by RateLimited without calling handle() or burning attempt_count.
        Carbon::setTestNow(now());
        try {
            foreach ($items as $item) {
                $outcome = $runThroughPipeline($item);
                if ($outcome['released']) {
                    $this->assertGreaterThan(0, $outcome['delay']);
                    $releasedItems[] = $item;
                }
            }

            $this->assertCount(5, $releasedItems);
            Http::assertSentCount(20);
            foreach ($releasedItems as $pendingItem) {
                $pendingItem->refresh();
                $this->assertSame(AiExplanationBatchItem::STATUS_PENDING, $pendingItem->status);
                $this->assertSame(0, $pendingItem->attempt_count);
            }

            // Advance virtual clock past the 60s window and re-run the 5 released items through the real middleware pipeline
            Carbon::setTestNow(now()->addSeconds(65));
            foreach ($releasedItems as $item) {
                $outcome = $runThroughPipeline($item);
                $this->assertFalse($outcome['released']);
            }
        } finally {
            Carbon::setTestNow();
            RateLimiter::clear(md5('ai-bulk-explanationsai-bulk-explanations'));
        }

        $batch = AiExplanationBatch::findOrFail($batchId);
        $this->assertSame(AiExplanationBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(25, $batch->generated_items);
        $this->assertSame(0, $batch->failed_items);
        Http::assertSentCount(25);
    }

    public function test_stale_processing_recovery_and_orphaned_pending_reconciliation(): void
    {
        Queue::fake();

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2, 4],
            'language' => 'en',
        ])->assertCreated()->json('data.id');

        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('question_id')->get();

        // Item 0: already generated (must never be disturbed by recovery!)
        $items[0]->update(['status' => AiExplanationBatchItem::STATUS_GENERATED, 'draft_explanation' => 'Keep me']);
        // Item 1: stuck in processing past stale threshold (300s > 240s)
        $items[1]->update([
            'status' => AiExplanationBatchItem::STATUS_PROCESSING,
            'processing_started_at' => now()->subSeconds(300),
        ]);
        // Item 2: committed pending but never dispatched (dispatched_at = null)
        $items[2]->update([
            'status' => AiExplanationBatchItem::STATUS_PENDING,
            'dispatched_at' => null,
        ]);

        // While question #2 lock is still held, stale recovery must NOT touch Item 1
        Queue::fake();
        $activeLock = Cache::lock(QuestionExplanationService::questionLockKey(2), 60);
        $activeLock->get();
        $resLocked = $this->postJson(self::BASE."/batches/{$batchId}/reconcile")->assertOk();
        $this->assertSame(0, $resLocked->json('data.recovered_stale_processing'));
        $this->assertSame(1, $resLocked->json('data.re_dispatched_pending'));
        $this->assertSame(AiExplanationBatchItem::STATUS_PROCESSING, $items[1]->fresh()->status);
        $this->assertNotNull($items[2]->fresh()->dispatched_at);
        Queue::assertPushed(ProcessBulkExplanationItemJob::class, 1);
        $activeLock->release();

        // Calling reconcile again immediately is idempotent for Item 2 (0 re-dispatches),
        // and now recovers stale Item 1 -> failed (stale_processing_timeout)
        Queue::fake();
        $resRecovered = $this->postJson(self::BASE."/batches/{$batchId}/reconcile")->assertOk();
        $this->assertSame(1, $resRecovered->json('data.recovered_stale_processing'));
        $this->assertSame(0, $resRecovered->json('data.re_dispatched_pending'));
        $this->assertSame(AiExplanationBatchItem::STATUS_FAILED, $items[1]->fresh()->status);
        $this->assertSame('stale_processing_timeout', $items[1]->fresh()->error_code);
        // Item 0 draft preserved
        $this->assertSame(AiExplanationBatchItem::STATUS_GENERATED, $items[0]->fresh()->status);
        $this->assertSame('Keep me', $items[0]->fresh()->draft_explanation);
        Queue::assertNothingPushed();

        // Cancellation during reconciliation skips pending items without dispatching
        $this->postJson(self::BASE."/batches/{$batchId}/cancel")->assertOk();
        $items[2]->update(['status' => AiExplanationBatchItem::STATUS_PENDING, 'dispatched_at' => null]);
        Queue::fake();
        $this->postJson(self::BASE."/batches/{$batchId}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.re_dispatched_pending', 0);
        $this->assertSame(AiExplanationBatchItem::STATUS_SKIPPED, $items[2]->fresh()->status);
        $this->assertSame('batch_cancelled', $items[2]->fresh()->error_code);
        Queue::assertNothingPushed();
    }

    public function test_cost_aggregation_across_multiple_attempts_and_controlled_retry_policy(): void
    {
        Queue::fake();

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2, 4],
            'language' => 'en',
        ])->assertCreated()->json('data.id');

        $service = app(BulkQuestionExplanationService::class);
        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('question_id')->get();

        // 1) Item 0: Transient 429 on attempt 1 -> ONLY 1 outbound call (no nested AiService retry!), retryable = true
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'rate limited']], 429)]);
        $res429 = $service->processItem($items[0]->id, null, 3);
        Http::assertSentCount(1);
        $this->assertTrue($res429['retryable']);
        $this->assertSame(AiExplanationBatchItem::STATUS_PENDING, $items[0]->fresh()->status);
        $this->assertSame(1, $items[0]->fresh()->attempt_count);

        // Attempt 2: returns invalid_output (consumes 140 tokens & cost, non-retryable -> failed)
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'explanation' => str_repeat('word ', 45),
                'needs_review' => false,
                'review_reason' => null,
            ])),
        ]);
        $resInvalid = $service->processItem($items[0]->id, null, 3);
        $this->assertFalse($resInvalid['retryable']);
        $this->assertSame(AiExplanationBatchItem::STATUS_FAILED, $items[0]->fresh()->status);

        // Admin retries failed item -> succeeds on next run (another 140 tokens & cost)
        Queue::fake();
        $this->postJson(self::BASE."/batches/{$batchId}/retry-failed")->assertOk();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'explanation' => 'Valid explanation after retry.',
                'needs_review' => false,
                'review_reason' => null,
            ])),
        ]);
        $service->processItem($items[0]->id, null, 3);
        $this->assertSame(AiExplanationBatchItem::STATUS_GENERATED, $items[0]->fresh()->status);

        // Batch actual tokens & cost must reconcile across BOTH priced AiRequest rows (140 + 140 = 280 tokens)
        $batch = AiExplanationBatch::findOrFail($batchId);
        $this->assertSame(280, $batch->actual_total_tokens);
        $expectedCost = AiRequest::where('trigger_type', 'bulk')->sum('estimated_cost');
        $this->assertSame(number_format((float) $expectedCost, 8, '.', ''), $batch->actual_cost);

        // 2) Item 1: ConnectionException (504 ambiguous timeout) -> non-retryable, 1 attempt only
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(fn () => throw new ConnectionException('Timed out'));
        $resTimeout = $service->processItem($items[1]->id, null, 3);
        $this->assertFalse($resTimeout['retryable']);
        $this->assertSame(AiExplanationBatchItem::STATUS_FAILED, $items[1]->fresh()->status);
        $this->assertSame('connection_error', $items[1]->fresh()->error_code);

        // 3) Item 2: HTTP 500 across 3 execution attempts -> retryable on 1 & 2, terminal failed on 3
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => 'server error'], 500)]);
        $this->assertTrue($service->processItem($items[2]->id, null, 3)['retryable']);
        $this->assertTrue($service->processItem($items[2]->id, null, 3)['retryable']);
        $this->assertFalse($service->processItem($items[2]->id, null, 3)['retryable']);
        $this->assertSame(AiExplanationBatchItem::STATUS_FAILED, $items[2]->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_active_batch_exclusion_and_release_after_cancellation(): void
    {
        Queue::fake();

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertCreated()->json('data.id');

        // Preview excludes Q1 and Q2 while they are in an active batch and reports excluded_active_batch_count
        $this->postJson(self::BASE.'/preview', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.summary.total_eligible_questions', 0)
            ->assertJsonPath('data.summary.excluded_active_batch_count', 2);

        // Creating an overlapping batch for Q1 & Q2 is rejected
        $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertUnprocessable();

        // Cancel Batch 1 -> Q1 & Q2 are legitimately available again
        $this->postJson(self::BASE."/batches/{$batchId}/cancel")->assertOk();

        $this->postJson(self::BASE.'/preview', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.summary.total_eligible_questions', 2)
            ->assertJsonPath('data.summary.excluded_active_batch_count', 0);

        $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertCreated()->assertJsonPath('data.total_items', 2);
    }

    public function test_multi_item_apply_atomic_rollback_conflict_diagnostics_and_list_endpoints(): void
    {
        Queue::fake();

        $batchId = (int) $this->postJson(self::BASE.'/batches', [
            'question_ids' => [1, 2],
            'language' => 'en',
        ])->assertCreated()->json('data.id');

        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push($this->openAiResponse(['explanation' => 'Clean draft for Q1.', 'needs_review' => false, 'review_reason' => null]))
                ->push($this->openAiResponse(['explanation' => 'Draft for Q2.', 'needs_review' => false, 'review_reason' => null])),
        ]);

        $service = app(BulkQuestionExplanationService::class);
        $items = AiExplanationBatchItem::where('batch_id', $batchId)->orderBy('question_id')->get();
        foreach ($items as $item) {
            $service->processItem($item->id);
        }

        // Verify GET /batches and GET /batches/{batch}/items filtering & pagination
        $this->getJson(self::BASE.'/batches?status=completed&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson(self::BASE."/batches/{$batchId}/items?status=generated&needs_review=0&per_page=1")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_page', 1);

        // Mutate Q2 option so item #2 conflicts while item #1 is clean
        DB::table('exam_question_options_tbl')->where('question_id', 2)->where('is_answer', 1)->update(['option' => '99 N']);

        $this->postJson(self::BASE."/batches/{$batchId}/apply", [
            'item_ids' => [$items[0]->id, $items[1]->id],
        ])->assertConflict()
            ->assertJsonPath('conflict.item_id', $items[1]->id)
            ->assertJsonPath('conflict.question_id', 2)
            ->assertJsonPath('conflict.effective_language', 'en');

        // Atomic rollback: Q1 must NOT have been partially saved!
        $this->assertNull(DB::table('exam_question_tbl')->where('id', 1)->value('explanation'));
        $this->assertSame(AiExplanationBatchItem::STATUS_GENERATED, $items[0]->fresh()->status);
        $this->assertSame(AiExplanationBatchItem::STATUS_GENERATED, $items[1]->fresh()->status);
    }
}
