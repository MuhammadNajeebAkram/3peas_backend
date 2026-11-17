<?php

namespace Tests\Feature;

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Middleware\CheckPermission;
use App\Models\PastPaperCurriculumAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPastPaperCurriculumOwnershipTest extends TestCase
{
    private User $superAdmin;

    private User $structuredAssigner;

    private User $legacyAssigner;

    private User $readOnlyViewer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pp_ownership_test',
            'database.connections.pp_ownership_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('pp_ownership_test');
        auth()->shouldUse('api');

        $this->createTestSchema();
        $this->seed(\Database\Seeders\LmsPermissionsSeeder::class);
        $this->seedLookupAndPaperData();

        app('router')->aliasMiddleware('permission', CheckPermission::class);
        $this->withoutMiddleware([
            AttachJwtFromCookie::class,
            AuthenticateJwtCookieGuard::class,
        ]);

        $superRole = Role::where('name', 'super_admin')->firstOrFail();
        $this->superAdmin = User::create(['name' => 'Super Admin', 'role_id' => $superRole->id]);

        $this->structuredAssigner = $this->createAdminWithPermissions('structured_curator', [
            'tst-past-papers.view',
            'tst-past-papers.create',
            'tst-past-papers.update',
            'tst-past-papers.assign-curriculum',
        ]);

        $this->legacyAssigner = $this->createAdminWithPermissions('legacy_curator', [
            'legacy-past-papers.view',
            'legacy-past-papers.assign-curriculum',
        ]);

        $this->readOnlyViewer = $this->createAdminWithPermissions('paper_viewer', [
            'tst-past-papers.view',
            'legacy-past-papers.view',
        ]);
    }

    public function test_authorized_admin_can_assign_unresolved_structured_paper_preserving_metadata_and_pages(): void
    {
        $this->actingAs($this->structuredAssigner, 'api');

        $this->postJson('/api/admin/auth/tst-past-papers/101/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
            'expected_current_curriculum_board_id' => null,
            'note' => 'Verified PTB syllabus match',
        ])->assertOk()
            ->assertJsonPath('assignment.paper_source', 'tst_past_papers')
            ->assertJsonPath('assignment.paper_id', 101)
            ->assertJsonPath('assignment.operation_type', 'initial_assignment')
            ->assertJsonPath('assignment.previous_curriculum_board_id', null)
            ->assertJsonPath('assignment.curriculum_board_id', 1);

        $paper = DB::table('tst_past_papers')->where('id', 101)->first();
        $this->assertSame(1, (int) $paper->curriculum_board_id);
        $this->assertSame(11, (int) $paper->board_id);
        $this->assertSame(1, (int) $paper->class_id);
        $this->assertSame(2, (int) $paper->subject_id);
        $this->assertSame(1, (int) $paper->session_id);
        $this->assertSame(2026, (int) $paper->year);
        $this->assertSame(1, (int) $paper->group);
        $this->assertSame(2, DB::table('tst_past_paper_pages')->where('past_paper_id', 101)->count());

        $this->getJson('/api/admin/auth/tst-past-papers/101')
            ->assertOk()
            ->assertJsonPath('past_paper.curriculum_board_id', 1)
            ->assertJsonPath('audit_logs.0.operation_type', 'initial_assignment')
            ->assertJsonPath('audit_logs.0.previous_curriculum_board_id', null)
            ->assertJsonPath('audit_logs.0.new_curriculum_board_id', 1)
            ->assertJsonPath('audit_logs.0.admin_user_id', $this->structuredAssigner->id);

        // Structured endpoint never mutates legacy table row #101
        $this->assertNull(DB::table('past_paper_tbl')->where('id', 101)->value('curriculum_board_id'));
        $this->assertGreaterThan(0, DB::table('admin_activity_logs')->where('admin_user_id', $this->structuredAssigner->id)->count());
    }

    public function test_authorized_admin_can_assign_unresolved_legacy_paper_preserving_exam_metadata(): void
    {
        $this->actingAs($this->legacyAssigner, 'api');

        $this->postJson('/api/admin/auth/legacy-past-papers/201/curriculum-board', [
            'curriculum_board_id' => 2,
            'operation' => 'assign',
            'expected_current_curriculum_board_id' => null,
            'note' => 'Federal curriculum verified',
        ])->assertOk()
            ->assertJsonPath('assignment.paper_source', 'past_paper_tbl')
            ->assertJsonPath('assignment.paper_id', 201)
            ->assertJsonPath('assignment.operation_type', 'initial_assignment')
            ->assertJsonPath('assignment.curriculum_board_id', 2);

        $legacy = DB::table('past_paper_tbl')->where('id', 201)->first();
        $this->assertSame(2, (int) $legacy->curriculum_board_id);
        $this->assertSame(12, (int) $legacy->board_id);
        $this->assertSame(1, (int) $legacy->class_id);
        $this->assertSame(2, (int) $legacy->subject_id);
        $this->assertSame(2, (int) $legacy->session_id);
        $this->assertSame(2023, (int) $legacy->year);
        $this->assertSame(2, (int) $legacy->group);
        $this->assertSame('papers/leg-201-p1.jpg', $legacy->paper_path);

        // Sibling page 202 under same slug remains untouched unless explicitly selected
        $this->assertNull(DB::table('past_paper_tbl')->where('id', 202)->value('curriculum_board_id'));

        // Legacy endpoint never mutates tst_past_papers
        $this->assertNull(DB::table('tst_past_papers')->where('id', 101)->value('curriculum_board_id'));

        $this->getJson('/api/admin/auth/legacy-past-papers/201')
            ->assertOk()
            ->assertJsonPath('past_paper.curriculum_board_id', 2)
            ->assertJsonPath('past_paper.sibling_pages_count', 2)
            ->assertJsonPath('audit_logs.0.operation_type', 'initial_assignment')
            ->assertJsonPath('audit_logs.0.new_curriculum_board_id', 2);
    }

    public function test_unauthorized_cross_source_and_teacher_requests_are_rejected_without_audit_logs(): void
    {
        // Unauthenticated
        $this->postJson('/api/admin/auth/tst-past-papers/101/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertUnauthorized();

        // Teacher / Student (non-admin WebUser) cannot access admin ownership routes
        $webUser = new WebUser();
        $webUser->forceFill(['name' => 'Teacher User', 'role' => 'teacher', 'status' => 'active'])->save();
        $this->actingAs($webUser, 'web_api');
        $this->postJson('/api/admin/auth/legacy-past-papers/201/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertForbidden();

        // Read-only admin can view but cannot assign or bulk assign
        $this->actingAs($this->readOnlyViewer, 'api');
        $this->getJson('/api/admin/auth/tst-past-papers/all')->assertOk();
        $this->getJson('/api/admin/auth/legacy-past-papers/all')->assertOk();

        $this->postJson('/api/admin/auth/tst-past-papers/101/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertForbidden();

        $this->postJson('/api/admin/auth/legacy-past-papers/201/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertForbidden();

        $this->postJson('/api/admin/auth/legacy-past-papers/bulk-curriculum-board', [
            'paper_ids' => [201],
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertForbidden();

        // Structured curator cannot mutate legacy table & legacy curator cannot mutate structured table
        $this->actingAs($this->structuredAssigner, 'api');
        $this->postJson('/api/admin/auth/legacy-past-papers/201/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertForbidden();

        $this->actingAs($this->legacyAssigner, 'api');
        $this->postJson('/api/admin/auth/tst-past-papers/101/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertForbidden();

        $this->assertSame(0, DB::table('past_paper_curriculum_audit_logs')->count());
    }

    public function test_invalid_or_inactive_curriculum_board_and_missing_paper_fail_cleanly_with_zero_audits(): void
    {
        $this->actingAs($this->superAdmin, 'api');

        // Non-existent curriculum board
        $this->postJson('/api/admin/auth/tst-past-papers/101/curriculum-board', [
            'curriculum_board_id' => 9999,
            'operation' => 'assign',
        ])->assertUnprocessable();

        // Inactive curriculum board (id=3)
        $this->postJson('/api/admin/auth/legacy-past-papers/201/curriculum-board', [
            'curriculum_board_id' => 3,
            'operation' => 'assign',
        ])->assertUnprocessable();

        // Missing paper IDs
        $this->postJson('/api/admin/auth/tst-past-papers/9999/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertNotFound()->assertJsonPath('code', 'past_paper_not_found');

        $this->postJson('/api/admin/auth/legacy-past-papers/9999/curriculum-board', [
            'curriculum_board_id' => 1,
            'operation' => 'assign',
        ])->assertNotFound()->assertJsonPath('code', 'past_paper_not_found');

        $this->assertSame(0, DB::table('past_paper_curriculum_audit_logs')->count());
    }

    public function test_reassignment_requires_explicit_operation_confirmation_and_prevents_metadata_overwrite_and_concurrency_races(): void
    {
        $this->actingAs($this->superAdmin, 'api');

        // Paper 102 starts assigned to curriculum_board_id = 1
        // 1) Initial 'assign' on already assigned paper is rejected (409)
        $this->postJson('/api/admin/auth/tst-past-papers/102/curriculum-board', [
            'curriculum_board_id' => 2,
            'operation' => 'assign',
        ])->assertStatus(409)->assertJsonPath('code', 'ownership_already_assigned');

        // 2) Metadata edit endpoint cannot silently swap curriculum_board_id
        $this->postJson('/api/admin/auth/tst-past-papers/update/102', [
            'paper_title' => 'Structured Paper 102 Metadata Only',
            'board_id' => 11,
            'curriculum_board_id' => 2,
            'subject_id' => 2,
            'class_id' => 1,
            'session_id' => 1,
            'group' => 1,
            'year' => 2025,
        ])->assertUnprocessable()->assertJsonPath('code', 'explicit_ownership_action_required');

        $this->assertSame(1, (int) DB::table('tst_past_papers')->where('id', 102)->value('curriculum_board_id'));

        // 3) Reassign without confirm_reassignment=true fails
        $this->postJson('/api/admin/auth/tst-past-papers/102/curriculum-board', [
            'curriculum_board_id' => 2,
            'operation' => 'reassign',
            'confirm_reassignment' => false,
            'expected_current_curriculum_board_id' => 1,
        ])->assertUnprocessable()->assertJsonPath('code', 'reassignment_confirmation_required');

        // 4) Stale concurrent expected_current_curriculum_board_id fails (409 Conflict)
        $this->postJson('/api/admin/auth/tst-past-papers/102/curriculum-board', [
            'curriculum_board_id' => 2,
            'operation' => 'reassign',
            'confirm_reassignment' => true,
            'expected_current_curriculum_board_id' => null,
        ])->assertStatus(409)->assertJsonPath('code', 'ownership_concurrent_modification');

        $this->assertSame(0, DB::table('past_paper_curriculum_audit_logs')->count());

        // 5) Valid explicit reassignment succeeds and appends audit entry
        $this->postJson('/api/admin/auth/tst-past-papers/102/curriculum-board', [
            'curriculum_board_id' => 2,
            'operation' => 'reassign',
            'confirm_reassignment' => true,
            'expected_current_curriculum_board_id' => 1,
            'note' => 'Corrected from PTB to FBISE',
        ])->assertOk()
            ->assertJsonPath('assignment.operation_type', 'reassignment')
            ->assertJsonPath('assignment.previous_curriculum_board_id', 1)
            ->assertJsonPath('assignment.curriculum_board_id', 2);

        $audit = DB::table('past_paper_curriculum_audit_logs')->first();
        $this->assertSame('tst_past_papers', $audit->paper_source);
        $this->assertSame(102, (int) $audit->paper_id);
        $this->assertSame('reassignment', $audit->operation_type);
        $this->assertSame(1, (int) $audit->previous_curriculum_board_id);
        $this->assertSame(2, (int) $audit->new_curriculum_board_id);
        $this->assertSame($this->superAdmin->id, (int) $audit->admin_user_id);

        // Unrelated metadata update (keeping existing 2) preserves ownership cleanly
        $this->postJson('/api/admin/auth/tst-past-papers/update/102', [
            'paper_title' => 'Structured Paper 102 Revised Title',
            'board_id' => 11,
            'subject_id' => 2,
            'class_id' => 1,
            'session_id' => 1,
            'group' => 1,
            'year' => 2025,
        ])->assertOk()->assertJsonPath('past_paper.curriculum_board_id', 2);

        // Append-only audit model throws on update or delete
        $model = PastPaperCurriculumAuditLog::findOrFail($audit->id);
        $this->expectException(\LogicException::class);
        $model->delete();
    }

    public function test_filters_and_safe_bulk_assignment_work_for_both_sources(): void
    {
        $this->actingAs($this->superAdmin, 'api');

        // Structured filters
        $this->getJson('/api/admin/auth/tst-past-papers/all?ownership_status=unresolved')
            ->assertOk()
            ->assertJsonPath('summary.total_all', 2)
            ->assertJsonPath('summary.assigned_count', 1)
            ->assertJsonPath('summary.unresolved_count', 1)
            ->assertJsonCount(1, 'past_papers.data')
            ->assertJsonPath('past_papers.data.0.id', 101);

        $this->getJson('/api/admin/auth/tst-past-papers/all?ownership_status=assigned&curriculum_board_id=1')
            ->assertOk()
            ->assertJsonCount(1, 'past_papers.data')
            ->assertJsonPath('past_papers.data.0.id', 102);

        // Legacy filters
        $this->getJson('/api/admin/auth/legacy-past-papers/all?ownership_status=unresolved')
            ->assertOk()
            ->assertJsonPath('summary.total_all', 4)
            ->assertJsonPath('summary.assigned_count', 1)
            ->assertJsonPath('summary.unresolved_count', 3)
            ->assertJsonCount(3, 'past_papers.data');

        // Bulk assign legacy: 201 (unresolved), 202 (unresolved), 203 (already assigned -> fails cleanly), 9999 (missing -> fails)
        $bulkRes = $this->postJson('/api/admin/auth/legacy-past-papers/bulk-curriculum-board', [
            'paper_ids' => [201, 202, 203, 9999],
            'curriculum_board_id' => 1,
            'operation' => 'assign',
            'note' => 'Batch Sahiwal paper assignment',
        ])->assertOk()
            ->assertJsonPath('result.total_requested', 4)
            ->assertJsonPath('result.succeeded_count', 2)
            ->assertJsonPath('result.failed_count', 2);

        $this->assertSame(1, (int) DB::table('past_paper_tbl')->where('id', 201)->value('curriculum_board_id'));
        $this->assertSame(1, (int) DB::table('past_paper_tbl')->where('id', 202)->value('curriculum_board_id'));
        // 203 remained unchanged (still 2)
        $this->assertSame(2, (int) DB::table('past_paper_tbl')->where('id', 203)->value('curriculum_board_id'));

        // Audit logs created ONLY for the 2 succeeded IDs
        $this->assertSame(2, DB::table('past_paper_curriculum_audit_logs')->where('paper_source', 'past_paper_tbl')->count());
        $this->assertNotEmpty($bulkRes->json('result.failed'));
    }

    private function createAdminWithPermissions(string $roleName, array $permissionNames): User
    {
        $role = Role::create(['name' => $roleName]);
        $user = User::create(['name' => ucfirst($roleName), 'role_id' => $role->id]);

        foreach ($permissionNames as $name) {
            $perm = Permission::where('name', $name)->firstOrFail();
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $perm->id,
            ]);
        }

        return $user;
    }

    private function createTestSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('web_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('role');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('admin_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_user_id');
            $t->string('action');
            $t->string('module');
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('curriculum_board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('board_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('board_name');
            $t->string('short_name')->nullable();
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('subject_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('subject_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('class_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('class_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('exam_session_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('session_name');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });
        Schema::create('tst_past_papers', function (Blueprint $t) {
            $t->id();
            $t->string('paper_title');
            $t->string('paper_slug');
            $t->unsignedBigInteger('board_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('session_id');
            $t->integer('group');
            $t->integer('year');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('tst_past_paper_pages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('past_paper_id');
            $t->integer('page_no');
            $t->string('paper_type')->default('objective');
            $t->string('image_path');
            $t->string('thumbnail_path')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('past_paper_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('paper_name');
            $t->string('paper_slug')->nullable();
            $t->string('paper_path');
            $t->unsignedBigInteger('class_id');
            $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('board_id');
            $t->integer('year');
            $t->unsignedBigInteger('session_id');
            $t->integer('group');
            $t->boolean('activate')->default(true);
            $t->timestamps();
        });

        foreach ([
            '2026_04_01_191541_create_permissions_table.php',
            '2026_04_01_191631_create_role_permissions_table.php',
            '2026_11_11_000002_add_curriculum_board_ownership_to_past_paper_tables.php',
            '2026_11_11_000003_create_past_paper_curriculum_audit_logs_table.php',
        ] as $migrationFile) {
            (require database_path('migrations/' . $migrationFile))->up();
        }
    }

    private function seedLookupAndPaperData(): void
    {
        DB::table('curriculum_board_tbl')->insert([
            ['id' => 1, 'name' => 'Punjab Text Book Board', 'activate' => 1],
            ['id' => 2, 'name' => 'Federal Board', 'activate' => 1],
            ['id' => 3, 'name' => 'Archived Curriculum', 'activate' => 0],
        ]);
        DB::table('board_tbl')->insert([
            ['id' => 11, 'board_name' => 'Lahore Board', 'short_name' => 'Lhr', 'activate' => 1],
            ['id' => 12, 'board_name' => 'Federal Board', 'short_name' => 'Fed', 'activate' => 1],
        ]);
        DB::table('class_tbl')->insert(['id' => 1, 'class_name' => '9th', 'activate' => 1]);
        DB::table('subject_tbl')->insert(['id' => 2, 'subject_name' => 'Physics', 'activate' => 1]);
        DB::table('exam_session_tbl')->insert([
            ['id' => 1, 'session_name' => 'Annual', 'activate' => 1],
            ['id' => 2, 'session_name' => 'Supplementary', 'activate' => 1],
        ]);

        DB::table('tst_past_papers')->insert([
            [
                'id' => 101,
                'paper_title' => 'Structured Paper Unresolved 101',
                'paper_slug' => 'structured-paper-unresolved-101',
                'board_id' => 11,
                'curriculum_board_id' => null,
                'subject_id' => 2,
                'class_id' => 1,
                'session_id' => 1,
                'group' => 1,
                'year' => 2026,
                'is_active' => 1,
            ],
            [
                'id' => 102,
                'paper_title' => 'Structured Paper Assigned 102',
                'paper_slug' => 'structured-paper-assigned-102',
                'board_id' => 11,
                'curriculum_board_id' => 1,
                'subject_id' => 2,
                'class_id' => 1,
                'session_id' => 1,
                'group' => 1,
                'year' => 2025,
                'is_active' => 1,
            ],
        ]);

        DB::table('tst_past_paper_pages')->insert([
            ['id' => 1, 'past_paper_id' => 101, 'page_no' => 1, 'paper_type' => 'objective', 'image_path' => 'tst/101-1.jpg', 'is_active' => 1],
            ['id' => 2, 'past_paper_id' => 101, 'page_no' => 2, 'paper_type' => 'subjective', 'image_path' => 'tst/101-2.jpg', 'is_active' => 1],
        ]);

        DB::table('past_paper_tbl')->insert([
            [
                'id' => 101,
                'paper_name' => 'Legacy Collision ID 101',
                'paper_slug' => 'legacy-collision-101',
                'paper_path' => 'papers/leg-101.jpg',
                'class_id' => 1,
                'subject_id' => 2,
                'board_id' => 11,
                'curriculum_board_id' => null,
                'year' => 2022,
                'session_id' => 1,
                'group' => 1,
                'activate' => 1,
            ],
            [
                'id' => 201,
                'paper_name' => 'Legacy Federal Exam Paper Page 1',
                'paper_slug' => 'legacy-fed-paper',
                'paper_path' => 'papers/leg-201-p1.jpg',
                'class_id' => 1,
                'subject_id' => 2,
                'board_id' => 12,
                'curriculum_board_id' => null,
                'year' => 2023,
                'session_id' => 2,
                'group' => 2,
                'activate' => 1,
            ],
            [
                'id' => 202,
                'paper_name' => 'Legacy Federal Exam Paper Page 1',
                'paper_slug' => 'legacy-fed-paper',
                'paper_path' => 'papers/leg-201-p2.jpg',
                'class_id' => 1,
                'subject_id' => 2,
                'board_id' => 12,
                'curriculum_board_id' => null,
                'year' => 2023,
                'session_id' => 2,
                'group' => 2,
                'activate' => 1,
            ],
            [
                'id' => 203,
                'paper_name' => 'Legacy Already Assigned 203',
                'paper_slug' => 'legacy-assigned-203',
                'paper_path' => 'papers/leg-203.jpg',
                'class_id' => 1,
                'subject_id' => 2,
                'board_id' => 11,
                'curriculum_board_id' => 2,
                'year' => 2024,
                'session_id' => 1,
                'group' => 1,
                'activate' => 1,
            ],
        ]);
    }
}
