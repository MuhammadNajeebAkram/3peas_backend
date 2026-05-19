<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('roles') && !Schema::hasColumn('roles', 'display_name')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('display_name')->nullable()->after('name');
            });
        }

        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')
                ->select('role_id', 'permission_id', DB::raw('MIN(id) as keep_id'))
                ->groupBy('role_id', 'permission_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->each(function ($duplicate) {
                    DB::table('role_permissions')
                        ->where('role_id', $duplicate->role_id)
                        ->where('permission_id', $duplicate->permission_id)
                        ->where('id', '!=', $duplicate->keep_id)
                        ->delete();
                });

            Schema::table('role_permissions', function (Blueprint $table) {
                $table->unique(['role_id', 'permission_id'], 'role_permissions_role_id_permission_id_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            Schema::table('role_permissions', function (Blueprint $table) {
                $table->dropUnique('role_permissions_role_id_permission_id_unique');
            });
        }

        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'display_name')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('display_name');
            });
        }
    }
};
