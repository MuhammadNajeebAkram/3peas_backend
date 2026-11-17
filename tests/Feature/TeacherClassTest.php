<?php

namespace Tests\Feature;

use App\Models\TeacherClass;
use App\Models\TeacherProfile;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherClassTest extends TeacherBackendTest
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['class_tbl', 'subject_tbl', 'curriculum_board_tbl'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                if ($table === 'class_tbl') { $t->string('class_name'); $t->string('class_name_um')->nullable(); }
                if ($table === 'subject_tbl') { $t->string('subject_name'); $t->string('subject_name_um')->nullable(); }
                if ($table === 'curriculum_board_tbl') $t->string('name');
                $t->boolean('activate')->default(true);
                $t->timestamps();
            });
            DB::table($table)->insert(['id' => 1, 'activate' => 1, 'created_at' => now(), 'updated_at' => now()] + match ($table) {
                'class_tbl' => ['class_name' => 'Grade 9'],
                'subject_tbl' => ['subject_name' => 'Mathematics'],
                default => ['name' => 'Federal Board'],
            });
        }
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'active']);
        app(\App\Http\Services\TeacherSubscriptionService::class)->assignDefaultFree(
            TeacherProfile::where('web_user_id', 2)->value('id')
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Grade 9 Mathematics', 'class_id' => 1, 'subject_id' => 1,
            'curriculum_board_id' => 1, 'section' => 'A'], $overrides);
    }

    public function test_active_teacher_can_create_edit_list_and_archive_owned_classes(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        $created = $this->postJson('/web_api/auth/teacher/classes', $this->payload(['teacher_profile_id' => 99]))
            ->assertUnprocessable();
        $this->assertNull($created->json('data'));
        $id = $this->postJson('/web_api/auth/teacher/classes', $this->payload())->assertCreated()
            ->assertJsonPath('data.is_active', true)->json('data.id');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', TeacherClass::findOrFail($id)->class_code);
        $this->putJson('/web_api/auth/teacher/classes/'.$id, ['name' => 'Grade 9 Advanced'])->assertOk()
            ->assertJsonPath('data.name', 'Grade 9 Advanced');
        $this->getJson('/web_api/auth/teacher/classes')->assertOk()->assertJsonPath('summary.active_classes', 1);
        $this->deleteJson('/web_api/auth/teacher/classes/'.$id)->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('teacher_classes', ['id' => $id, 'is_active' => false]);
        $this->putJson('/web_api/auth/teacher/classes/'.$id, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('teacher_classes', ['id' => $id, 'is_active' => true]);
    }

    public function test_teacher_class_access_requires_active_approved_profile_and_hides_other_owners(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'pending']);
        $this->getJson('/web_api/auth/teacher/classes')->assertForbidden();
        TeacherProfile::where('web_user_id', 2)->update(['status' => 'active']);
        DB::table('web_users')->insert(['id' => 4, 'name' => 'Other Teacher', 'email' => 'other@example.test', 'status' => 'active', 'role' => 'teacher']);
        $other = TeacherProfile::create(['web_user_id' => 4, 'status' => 'active']);
        $class = new TeacherClass(['class_id' => 1, 'subject_id' => 1, 'curriculum_board_id' => 1,
            'name' => 'Private class', 'section' => '']);
        $class->teacher_profile_id = $other->id;
        $class->class_code = 'ABCDEFGH';
        $class->save();
        $this->getJson('/web_api/auth/teacher/classes/'.$class->id)->assertNotFound();
        $this->putJson('/web_api/auth/teacher/classes/'.$class->id, ['name' => 'Takeover'])->assertNotFound();
        $this->deleteJson('/web_api/auth/teacher/classes/'.$class->id)->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_access_teacher_class_api(): void
    {
        $this->getJson('/web_api/auth/teacher/classes')->assertForbidden();
        $this->postJson('/web_api/auth/teacher/classes', $this->payload())->assertForbidden();
    }

    public function test_inactive_teacher_account_cannot_manage_classes(): void
    {
        WebUser::whereKey(2)->update(['status' => 'blocked']);
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->getJson('/web_api/auth/teacher/classes')->assertForbidden();
        $this->postJson('/web_api/auth/teacher/classes', $this->payload())->assertForbidden();
    }
    public function test_invalid_class_data_and_client_ownership_override_are_rejected(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/classes', $this->payload(['name' => 'x', 'class_id' => 999]))
            ->assertUnprocessable();
        $this->postJson('/web_api/auth/teacher/classes', $this->payload(['teacher_profile_id' => 999]))
            ->assertUnprocessable();
        $this->assertDatabaseCount('teacher_classes', 0);
    }

    public function test_free_plan_active_class_limit_is_configured_archive_releases_slot_and_reactivation_is_checked(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        $ids = [];
        foreach (['A', 'B', 'C'] as $section) {
            $ids[] = $this->postJson('/web_api/auth/teacher/classes', $this->payload(['section' => $section]))
                ->assertCreated()->json('data.id');
        }
        $this->postJson('/web_api/auth/teacher/classes', $this->payload(['section' => 'D']))
            ->assertForbidden()->assertJsonPath('code', 'teacher_class_limit_reached')
            ->assertJsonPath('data.current_active_classes', 3)->assertJsonPath('data.max_active_classes', 3);

        $this->deleteJson('/web_api/auth/teacher/classes/'.$ids[0])->assertOk();
        $newId = $this->postJson('/web_api/auth/teacher/classes', $this->payload(['section' => 'D']))->assertCreated()->json('data.id');
        $this->putJson('/web_api/auth/teacher/classes/'.$ids[0], ['is_active' => true])
            ->assertForbidden()->assertJsonPath('code', 'teacher_class_limit_reached');
        $this->assertDatabaseHas('teacher_classes', ['id' => $ids[0], 'is_active' => false]);
        $this->assertDatabaseHas('teacher_classes', ['id' => $newId, 'is_active' => true]);
    }

    public function test_lowering_configured_plan_limit_does_not_archive_existing_classes(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        $this->postJson('/web_api/auth/teacher/classes', $this->payload(['section' => 'A']))->assertCreated();
        $this->postJson('/web_api/auth/teacher/classes', $this->payload(['section' => 'B']))->assertCreated();
        \App\Models\TeacherSubscriptionPlan::where('code', 'free')->update(['max_active_classes' => 1]);

        $this->postJson('/web_api/auth/teacher/classes', $this->payload(['section' => 'C']))
            ->assertForbidden()->assertJsonPath('data.current_active_classes', 2)->assertJsonPath('data.max_active_classes', 1);
        $this->assertDatabaseCount('teacher_classes', 2);
        $this->assertSame(2, TeacherClass::where('teacher_profile_id', TeacherProfile::where('web_user_id', 2)->value('id'))->where('is_active', true)->count());
    }

    public function test_duplicate_offering_is_rejected_and_invitation_codes_are_unique(): void
    {
        $this->actingAs(WebUser::find(2), 'web_api');
        $first = $this->postJson('/web_api/auth/teacher/classes', $this->payload())->assertCreated()->json('data.class_code');
        $this->postJson('/web_api/auth/teacher/classes', $this->payload())->assertUnprocessable();
        $this->assertSame(1, TeacherClass::where('class_code', $first)->count());
        $this->assertNotSame($first, TeacherClass::generateUniqueCode());
    }
}

