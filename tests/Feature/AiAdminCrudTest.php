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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiAdminCrudTest extends TestCase
{
    private const BASE = '/api/admin/auth/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'ai_test', 'database.connections.ai_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ai_test');
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();
        });
        foreach ([
            '2026_04_01_191541_create_permissions_table.php',
            '2026_04_01_191631_create_role_permissions_table.php',
            '2026_09_15_000001_create_ai_models_table.php',
            '2026_09_15_000002_create_ai_requests_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->seed(AiPermissionsSeeder::class);
        // Keep permission middleware enabled while isolating token parsing from CRUD tests.
        $this->withoutMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->actingAs(User::create(['name' => 'Administrator', 'role_id' => Role::where('name', 'super_admin')->value('id')]), 'api');
    }

    private function model(array $attributes = []): AiModel
    {
        return AiModel::create(array_merge([
            'provider' => 'openai', 'name' => 'Test model', 'model_key' => 'test-model',
            'is_active' => true, 'input_price_per_million' => '0.750000',
        ], $attributes));
    }

    public function test_model_crud_default_switch_and_validation(): void
    {
        $first = $this->postJson(self::BASE.'ai-models/add', [
            'provider' => 'openai', 'name' => 'First', 'model_key' => 'first', 'is_default' => true,
        ])->assertCreated()->json('ai_model.id');
        $second = $this->postJson(self::BASE.'ai-models/add', [
            'provider' => 'openai', 'name' => 'Second', 'model_key' => 'second', 'is_default' => true,
        ])->assertCreated()->json('ai_model.id');
        $this->assertFalse(AiModel::find($first)->is_default);
        $this->assertTrue(AiModel::find($second)->is_default);
        $this->postJson(self::BASE."ai-models/update/$second", ['is_active' => false])->assertUnprocessable();
        $this->postJson(self::BASE."ai-models/update/$second", ['name' => 'Renamed'])->assertOk()->assertJsonPath('ai_model.model_key', 'second');
        $this->postJson(self::BASE.'ai-models/add', ['provider' => 'openai', 'name' => 'Duplicate', 'model_key' => 'first'])->assertUnprocessable();
        $this->postJson(self::BASE."ai-models/update/$first", ['input_price_per_million' => -1])->assertUnprocessable();
        $this->getJson(self::BASE.'ai-models/all?per_page=1')->assertOk()->assertJsonPath('ai_models.total', 2);
        $this->getJson(self::BASE.'ai-models/active')->assertOk()->assertJsonCount(2, 'ai_models');
        $this->deleteJson(self::BASE."ai-models/delete/$second")->assertOk();
        $this->assertTrue(AiModel::withTrashed()->find($second)->trashed());
        $this->assertFalse(AiModel::withTrashed()->find($second)->is_default);
        $this->getJson(self::BASE."ai-models/$second")->assertNotFound();
    }

    public function test_request_crud_snapshots_identity_and_history(): void
    {
        $model = $this->model();
        $body = ['ai_model_id' => $model->id, 'purpose' => 'question_explanation', 'subject_type' => 'exam_question', 'subject_id' => 123];
        $record = $this->postJson(self::BASE.'ai-requests/add', $body)->assertCreated()
            ->assertJsonPath('ai_request.user_id', auth()->id())
            ->assertJsonPath('ai_request.input_tokens', null)
            ->assertJsonPath('ai_request.record_source', 'admin')->json('ai_request');
        $id = $record['id'];
        $model->update(['input_price_per_million' => 5, 'model_key' => 'changed']);
        $this->postJson(self::BASE."ai-requests/update/$id", [
            'status' => 'successful', 'input_tokens' => 100, 'cached_input_tokens' => 20,
            'output_tokens' => 50, 'reasoning_tokens' => 10, 'total_tokens' => 150,
            'response_payload' => ['explanation' => 'A sample explanation'],
            'completed_at' => now()->addSecond()->toIso8601String(),
        ])->assertOk()->assertJsonPath('ai_request.pricing_snapshot.input_price_per_million', '0.750000')
            ->assertJsonPath('ai_request.requested_model', 'test-model');
        $this->postJson(self::BASE."ai-requests/update/$id", ['user_id' => 100])->assertUnprocessable();
        $this->postJson(self::BASE."ai-requests/update/$id", ['ai_model_id' => $model->id])->assertUnprocessable();
        $this->postJson(self::BASE.'ai-requests/add', $body + ['user_id' => 100])->assertUnprocessable();
        $this->getJson(self::BASE.'ai-requests/all?purpose=question_explanation')->assertOk()
            ->assertJsonPath('ai_requests.total', 1)->assertJsonMissingPath('ai_requests.data.0.response_payload');
        $this->getJson(self::BASE.'ai-requests/all?date_to=2099-01-01')->assertOk()->assertJsonPath('ai_requests.total', 1);
        $this->deleteJson(self::BASE.'ai-models/delete/'.$model->id)->assertOk();
        $this->getJson(self::BASE."ai-requests/$id")->assertOk()->assertJsonPath('ai_request.ai_model.id', $model->id);
        $this->deleteJson(self::BASE."ai-requests/delete/$id")->assertOk();
        $this->assertTrue(AiRequest::withTrashed()->find($id)->trashed());
        $this->getJson(self::BASE."ai-requests/$id")->assertNotFound();
    }

    public function test_request_validation_and_unique_attempts(): void
    {
        $model = $this->model();
        $body = ['ai_model_id' => $model->id, 'purpose' => 'translation'];
        $record = $this->postJson(self::BASE.'ai-requests/add', $body)->assertCreated()->json('ai_request');
        $this->postJson(self::BASE.'ai-requests/add', $body + ['operation_id' => $record['operation_id']])->assertUnprocessable();
        $this->postJson(self::BASE.'ai-requests/add', $body + ['operation_id' => $record['operation_id'], 'attempt_number' => 2])->assertCreated();
        foreach ([
            ['subject_id' => 1], ['subject_type' => 'exam_question'],
            ['input_tokens' => -1], ['input_tokens' => 10, 'cached_input_tokens' => 11],
            ['output_tokens' => 10, 'reasoning_tokens' => 11],
            ['input_tokens' => 10, 'output_tokens' => 10, 'total_tokens' => 30],
            ['status' => 'invalid'], ['status' => 'pending', 'completed_at' => now()->toIso8601String()],
            ['metadata' => ['nested' => ['api_key' => 'test-secret']]],
        ] as $invalid) {
            $this->postJson(self::BASE.'ai-requests/add', $body + $invalid)->assertUnprocessable();
        }
        $model->update(['is_active' => false]);
        $this->postJson(self::BASE.'ai-requests/add', $body)->assertUnprocessable();
    }

    public function test_each_route_requires_its_permission_and_admin_authentication(): void
    {
        $role = Role::create(['name' => 'operator']);
        $user = User::create(['name' => 'Operator', 'role_id' => $role->id]);
        foreach (['ai-models', 'ai-requests'] as $resource) {
            foreach ([['GET', 'all', 'view'], ['GET', '999', 'view'], ['POST', 'add', 'create'], ['POST', 'update/999', 'update'], ['DELETE', 'delete/999', 'delete']] as [$method, $path, $permission]) {
                $url = self::BASE.$resource.'/'.$path;
                $route = app('router')->getRoutes()->match(Request::create($url, $method));
                $this->assertContains('permission:'.$resource.'.'.$permission, $route->gatherMiddleware());
                $this->assertContains(AuthenticateJwtCookieGuard::class.':admin', $route->gatherMiddleware());
                $role->permissions()->detach();
                $this->actingAs($user->fresh(), 'api');
                $this->json($method, $url)->assertForbidden();
                $role->permissions()->attach(Permission::where('name', $resource.'.'.$permission)->value('id'));
                $this->actingAs($user->fresh(), 'api');
                $expected = $path === 'all' ? 200 : ($path === 'add' ? 422 : 404);
                $this->json($method, $url)->assertStatus($expected);
            }
        }
        $this->withMiddleware([AttachJwtFromCookie::class, AuthenticateJwtCookieGuard::class]);
        $this->getJson(self::BASE.'ai-models/all')->assertUnauthorized();
        $this->getJson(self::BASE.'ai-requests/all')->assertUnauthorized();
    }

    public function test_permission_seeding_is_idempotent_and_preserves_other_grants(): void
    {
        $role = Role::where('name', 'super_admin')->first();
        $extra = Permission::create(['name' => 'unrelated.view']);
        $role->permissions()->attach($extra);
        $this->seed(AiPermissionsSeeder::class);
        $this->seed(AiPermissionsSeeder::class);
        $this->assertSame(10, $role->permissions()->count());
    }

    public function test_hard_deleting_referenced_records_does_not_delete_usage_history(): void
    {
        $model = $this->model();
        $id = $this->postJson(self::BASE.'ai-requests/add', ['ai_model_id' => $model->id, 'purpose' => 'translation'])
            ->assertCreated()->json('ai_request.id');
        DB::table('users')->where('id', auth()->id())->delete();
        $model->forceDelete();
        $record = AiRequest::findOrFail($id);
        $this->assertNull($record->user_id);
        $this->assertNull($record->ai_model_id);
        $this->assertSame('test-model', $record->requested_model);
    }
}
