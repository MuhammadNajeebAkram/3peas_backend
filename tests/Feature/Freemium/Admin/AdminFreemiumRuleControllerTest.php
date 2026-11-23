<?php

namespace Tests\Feature\Freemium\Admin;

use App\Models\Freemium\FreemiumAccessRule;
use App\Models\Freemium\FreemiumCampaign;
use App\Models\User;
use Carbon\Carbon;

class AdminFreemiumRuleControllerTest extends AdminFreemiumTestCase
{
    private User $admin;
    private FreemiumCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminWithPermissions([
            'freemium.view',
            'freemium.create',
            'freemium.update',
            'freemium.delete',
        ]);
        $this->actingAs($this->admin, 'api');

        $this->campaign = FreemiumCampaign::create([
            'name'     => 'Rules Test Campaign',
            'status'   => 'draft',
            'priority' => 10,
            'start_at' => Carbon::now(),
            'end_at'   => Carbon::now()->addDays(30),
        ]);
    }

    public function test_rule_index_with_filters(): void
    {
        FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign->id,
            'target_scope'             => 'global',
            'target_status'            => 'active',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign->id,
            'target_scope'             => 'subject',
            'subject_id'               => 1,
            'target_status'            => 'orphaned',
            'rule_priority'            => 20,
            'perm_mcqs'                => 'deny',
            'academic_target_snapshot' => ['scope' => 'subject', 'subject_id' => 1],
        ]);

        // Filter by target_scope=global
        $resGlobal = $this->getJson("/api/admin/auth/freemium/rules?campaign_id={$this->campaign->id}&target_scope=global");
        $resGlobal->assertOk();
        $this->assertCount(1, $resGlobal->json('data'));
        $this->assertEquals('global', $resGlobal->json('data.0.target_scope'));

        // Filter by target_status=orphaned
        $resOrphaned = $this->getJson("/api/admin/auth/freemium/rules?target_status=orphaned");
        $resOrphaned->assertOk();
        $this->assertCount(1, $resOrphaned->json('data'));
        $this->assertEquals('orphaned', $resOrphaned->json('data.0.target_status'));
    }

    public function test_rule_store_cross_scope_validations(): void
    {
        // 1. curriculum_board scope requires curriculum_board_id
        $missingBoardRes = $this->postJson('/api/admin/auth/freemium/rules', [
            'campaign_id'  => $this->campaign->id,
            'target_scope' => 'curriculum_board',
            'perm_mcqs'    => 'allow',
        ]);
        $missingBoardRes->assertStatus(422);
        $missingBoardRes->assertJsonValidationErrors(['curriculum_board_id']);

        // 2. class scope requires class_id
        $missingClassRes = $this->postJson('/api/admin/auth/freemium/rules', [
            'campaign_id'  => $this->campaign->id,
            'target_scope' => 'class',
            'perm_mcqs'    => 'allow',
        ]);
        $missingClassRes->assertStatus(422);
        $missingClassRes->assertJsonValidationErrors(['class_id']);

        // 3. subject scope requires subject_id
        $missingSubjectRes = $this->postJson('/api/admin/auth/freemium/rules', [
            'campaign_id'  => $this->campaign->id,
            'target_scope' => 'subject',
            'perm_mcqs'    => 'allow',
        ]);
        $missingSubjectRes->assertStatus(422);
        $missingSubjectRes->assertJsonValidationErrors(['subject_id']);

        // 4. global scope succeeds without primary ID
        $globalRes = $this->postJson('/api/admin/auth/freemium/rules', [
            'campaign_id'  => $this->campaign->id,
            'target_scope' => 'global',
            'perm_mcqs'    => 'allow',
        ]);
        $globalRes->assertCreated();
    }

    public function test_rule_store_with_all_permission_dimensions(): void
    {
        $payload = [
            'campaign_id'                  => $this->campaign->id,
            'target_scope'                 => 'global',
            'rule_priority'                => 15,
            'perm_mcqs'                    => 'allow',
            'perm_short_questions'         => 'deny',
            'perm_long_questions'          => 'inherit',
            'perm_exercise_questions'      => 'allow',
            'perm_supplementary_questions' => 'deny',
            'perm_answers'                 => 'allow',
            'perm_explanations'            => 'inherit',
            'perm_topic_contents'          => 'deny',
            'perm_past_papers'             => 'allow',
            'perm_model_papers'            => 'inherit',
        ];

        $res = $this->postJson('/api/admin/auth/freemium/rules', $payload);
        $res->assertCreated();

        $ruleId = $res->json('data.id');
        $this->assertDatabaseHas('freemium_access_rules', [
            'id'                      => $ruleId,
            'campaign_id'             => $this->campaign->id,
            'target_scope'            => 'global',
            'rule_priority'           => 15,
            'perm_mcqs'               => 'allow',
            'perm_short_questions'    => 'deny',
            'perm_long_questions'     => 'inherit',
            'perm_past_papers'        => 'allow',
        ]);

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_access_rule',
            'entity_id'   => $ruleId,
            'action'      => 'rule.created',
            'admin_id'    => $this->admin->id,
        ]);
    }

    public function test_rule_show(): void
    {
        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign->id,
            'target_scope'             => 'global',
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $res = $this->getJson("/api/admin/auth/freemium/rules/{$rule->id}");
        $res->assertOk();
        $this->assertEquals($rule->id, $res->json('data.id'));
        $this->assertEquals('global', $res->json('data.target_scope'));
    }

    public function test_rule_update_permissions_and_priority(): void
    {
        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign->id,
            'target_scope'             => 'global',
            'rule_priority'            => 10,
            'perm_mcqs'                => 'inherit',
            'perm_answers'             => 'inherit',
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        // Prohibited update on immutable structural fields
        $badUpdate = $this->putJson("/api/admin/auth/freemium/rules/{$rule->id}", [
            'target_scope' => 'subject',
            'perm_mcqs'    => 'allow',
        ]);
        $badUpdate->assertStatus(422);

        // Valid update on permissions and priority
        $goodUpdate = $this->putJson("/api/admin/auth/freemium/rules/{$rule->id}", [
            'rule_priority' => 5,
            'perm_mcqs'     => 'allow',
            'perm_answers'  => 'deny',
        ]);
        $goodUpdate->assertOk();
        $this->assertEquals(5, $goodUpdate->json('data.rule_priority'));
        $this->assertEquals('allow', $goodUpdate->json('data.perm_mcqs'));
        $this->assertEquals('deny', $goodUpdate->json('data.perm_answers'));

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_access_rule',
            'entity_id'   => $rule->id,
            'action'      => 'rule.updated',
        ]);
    }

    public function test_rule_destroy(): void
    {
        $rule = FreemiumAccessRule::create([
            'campaign_id'              => $this->campaign->id,
            'target_scope'             => 'global',
            'perm_mcqs'                => 'allow',
            'academic_target_snapshot' => ['scope' => 'global'],
        ]);

        $deleteRes = $this->deleteJson("/api/admin/auth/freemium/rules/{$rule->id}");
        $deleteRes->assertOk();

        $this->assertDatabaseMissing('freemium_access_rules', ['id' => $rule->id]);

        $this->assertDatabaseHas('freemium_audit_logs', [
            'entity_type' => 'freemium_access_rule',
            'entity_id'   => $rule->id,
            'action'      => 'rule.deleted',
        ]);
    }
}
