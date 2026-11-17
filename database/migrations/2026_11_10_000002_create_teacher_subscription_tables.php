<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration can be retried after MySQL's non-transactional DDL stops partway.
        // Existing tables are retained and repaired below; missing tables are created.
        if (!Schema::hasTable('teacher_subscription_plans')) {
        Schema::create('teacher_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->char('currency', 3)->default('PKR');
            $table->enum('billing_interval', ['monthly', 'yearly', 'custom', 'lifetime'])->default('lifetime');
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('max_active_classes')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            // MySQL and SQLite support nullable generated columns; the unique index permits many NULLs
            // while guaranteeing at most one active default plan.
            $table->unsignedTinyInteger('active_default_guard')->nullable()
                ->storedAs('CASE WHEN is_default = 1 AND is_active = 1 THEN 1 ELSE NULL END');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('active_default_guard', 'teacher_subscription_one_active_default');
            $table->index(['is_active', 'billing_interval']);
        });
        }

        if (!Schema::hasTable('teacher_subscription_features')) {
        Schema::create('teacher_subscription_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_subscription_plan_id')->constrained('teacher_subscription_plans', 'id', 'teacher_sub_features_plan_fk')->restrictOnDelete();
            $table->string('feature_key', 100);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['teacher_subscription_plan_id', 'feature_key'], 'teacher_subscription_feature_unique');
            $table->index(['feature_key', 'is_enabled']);
        });
        }

        if (!Schema::hasTable('teacher_subscriptions')) {
        Schema::create('teacher_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles', 'id', 'teacher_subs_profile_fk')->restrictOnDelete();
            $table->foreignId('teacher_subscription_plan_id')->constrained('teacher_subscription_plans', 'id', 'teacher_subs_plan_fk')->restrictOnDelete();
            $table->enum('status', ['active', 'expired', 'cancelled', 'suspended'])->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->enum('assignment_type', ['automatic_free', 'manual_admin', 'paid'])->default('automatic_free');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('admin_note')->nullable();
            // A generated unique guard enforces at most one active-status assignment per teacher,
            // without limiting the number of historical expired/cancelled rows.
            $table->unsignedBigInteger('active_teacher_profile_id')->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN teacher_profile_id ELSE NULL END");
            $table->timestamps();
            $table->unique('active_teacher_profile_id', 'teacher_subscription_one_active_per_teacher');
            $table->index(['teacher_profile_id', 'status', 'expires_at'], 'teacher_subscription_teacher_status_expiry_idx');
            $table->index(['status', 'expires_at'], 'teacher_subscription_expiry_idx');
        });
        }

        if (!Schema::hasTable('teacher_subscription_history')) {
        Schema::create('teacher_subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_subscription_id')->constrained('teacher_subscriptions', 'id', 'teacher_sub_history_sub_fk')->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles', 'id', 'teacher_sub_history_profile_fk')->restrictOnDelete();
            $table->foreignId('previous_plan_id')->nullable()->constrained('teacher_subscription_plans')->restrictOnDelete();
            $table->foreignId('new_plan_id')->nullable()->constrained('teacher_subscription_plans')->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('old_status', 24)->nullable();
            $table->string('new_status', 24)->nullable();
            $table->timestamp('previous_expiry')->nullable();
            $table->timestamp('new_expiry')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['teacher_profile_id', 'created_at'], 'teacher_subscription_history_teacher_idx');
            $table->index(['teacher_subscription_id', 'created_at'], 'teacher_subscription_history_subscription_idx');
        });
        }

        $this->assertColumns('teacher_subscription_plans', [
            'id', 'code', 'name', 'description', 'price', 'currency', 'billing_interval', 'duration_days',
            'max_active_classes', 'is_free', 'is_active', 'is_default', 'active_default_guard', 'created_by',
            'updated_by', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('teacher_subscription_features', [
            'id', 'teacher_subscription_plan_id', 'feature_key', 'is_enabled', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('teacher_subscriptions', [
            'id', 'teacher_profile_id', 'teacher_subscription_plan_id', 'status', 'starts_at', 'expires_at',
            'assignment_type', 'assigned_by', 'admin_note', 'active_teacher_profile_id', 'created_at', 'updated_at',
        ]);
        $this->assertColumns('teacher_subscription_history', [
            'id', 'teacher_subscription_id', 'teacher_profile_id', 'previous_plan_id', 'new_plan_id', 'event_type',
            'old_status', 'new_status', 'previous_expiry', 'new_expiry', 'assigned_by', 'reason', 'metadata', 'created_at',
        ]);

        $this->ensureIndex('teacher_subscription_plans', ['code'], true, 'teacher_subscription_plans_code_unique');
        $this->ensureIndex('teacher_subscription_plans', ['active_default_guard'], true, 'teacher_subscription_one_active_default');
        $this->ensureIndex('teacher_subscription_plans', ['is_active', 'billing_interval'], false, 'teacher_subscription_plans_is_active_billing_interval_index');
        $this->ensureForeignKey('teacher_subscription_plans', 'created_by', 'users', 'id', 'teacher_subscription_plans_created_by_foreign');
        $this->ensureForeignKey('teacher_subscription_plans', 'updated_by', 'users', 'id', 'teacher_subscription_plans_updated_by_foreign');

        $this->ensureIndex('teacher_subscription_features', ['teacher_subscription_plan_id', 'feature_key'], true, 'teacher_subscription_feature_unique');
        $this->ensureIndex('teacher_subscription_features', ['feature_key', 'is_enabled'], false, 'teacher_subscription_features_feature_key_is_enabled_index');
        $this->ensureForeignKey('teacher_subscription_features', 'teacher_subscription_plan_id', 'teacher_subscription_plans', 'id', 'teacher_sub_features_plan_fk');

        $this->ensureIndex('teacher_subscriptions', ['active_teacher_profile_id'], true, 'teacher_subscription_one_active_per_teacher');
        $this->ensureIndex('teacher_subscriptions', ['teacher_profile_id', 'status', 'expires_at'], false, 'teacher_subscription_teacher_status_expiry_idx');
        $this->ensureIndex('teacher_subscriptions', ['status', 'expires_at'], false, 'teacher_subscription_expiry_idx');
        $this->ensureForeignKey('teacher_subscriptions', 'teacher_profile_id', 'teacher_profiles', 'id', 'teacher_subs_profile_fk');
        $this->ensureForeignKey('teacher_subscriptions', 'teacher_subscription_plan_id', 'teacher_subscription_plans', 'id', 'teacher_subs_plan_fk');
        $this->ensureForeignKey('teacher_subscriptions', 'assigned_by', 'users', 'id', 'teacher_subscriptions_assigned_by_foreign');

        $this->ensureIndex('teacher_subscription_history', ['teacher_profile_id', 'created_at'], false, 'teacher_subscription_history_teacher_idx');
        $this->ensureIndex('teacher_subscription_history', ['teacher_subscription_id', 'created_at'], false, 'teacher_subscription_history_subscription_idx');
        $this->ensureForeignKey('teacher_subscription_history', 'teacher_subscription_id', 'teacher_subscriptions', 'id', 'teacher_sub_history_sub_fk');
        $this->ensureForeignKey('teacher_subscription_history', 'teacher_profile_id', 'teacher_profiles', 'id', 'teacher_sub_history_profile_fk');
        $this->ensureForeignKey('teacher_subscription_history', 'previous_plan_id', 'teacher_subscription_plans', 'id', 'teacher_sub_history_prev_plan_fk');
        $this->ensureForeignKey('teacher_subscription_history', 'new_plan_id', 'teacher_subscription_plans', 'id', 'teacher_sub_history_new_plan_fk');
        $this->ensureForeignKey('teacher_subscription_history', 'assigned_by', 'users', 'id', 'teacher_subscription_history_assigned_by_foreign');
    }

    private function assertColumns(string $tableName, array $required): void
    {
        $missing = array_values(array_filter($required, fn (string $column) => !Schema::hasColumn($tableName, $column)));
        if ($missing) {
            throw new RuntimeException("Cannot safely recover {$tableName}; missing required columns: ".implode(', ', $missing));
        }
    }

    private function ensureIndex(string $tableName, array $columns, bool $unique, string $name): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'] ?? []) === $columns && (bool) ($index['unique'] ?? false) === $unique) return;
        }
        Schema::table($tableName, function (Blueprint $table) use ($columns, $unique, $name) {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function ensureForeignKey(string $tableName, string $column, string $foreignTable, string $foreignColumn, string $name): void
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]
                && ($foreignKey['foreign_table'] ?? null) === $foreignTable
                && ($foreignKey['foreign_columns'] ?? []) === [$foreignColumn]) return;
        }
        Schema::table($tableName, function (Blueprint $table) use ($column, $foreignTable, $foreignColumn, $name) {
            $table->foreign($column, $name)->references($foreignColumn)->on($foreignTable)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subscription_history');
        Schema::dropIfExists('teacher_subscriptions');
        Schema::dropIfExists('teacher_subscription_features');
        Schema::dropIfExists('teacher_subscription_plans');
    }
};
