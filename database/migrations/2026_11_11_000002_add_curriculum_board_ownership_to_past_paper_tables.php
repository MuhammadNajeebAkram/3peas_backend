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
        if (Schema::hasTable('tst_past_papers') && !Schema::hasColumn('tst_past_papers', 'curriculum_board_id')) {
            Schema::table('tst_past_papers', function (Blueprint $table) {
                $table->foreignId('curriculum_board_id')
                    ->nullable()
                    ->after('board_id')
                    ->constrained('curriculum_board_tbl')
                    ->nullOnDelete();

                $table->index(
                    ['is_active', 'curriculum_board_id', 'class_id', 'subject_id', 'year', 'board_id'],
                    'tst_pp_curriculum_scope_idx'
                );
            });
        }

        if (Schema::hasTable('past_paper_tbl') && !Schema::hasColumn('past_paper_tbl', 'curriculum_board_id')) {
            Schema::table('past_paper_tbl', function (Blueprint $table) {
                $table->foreignId('curriculum_board_id')
                    ->nullable()
                    ->after('board_id')
                    ->constrained('curriculum_board_tbl')
                    ->nullOnDelete();

                $table->index(
                    ['activate', 'curriculum_board_id', 'class_id', 'subject_id', 'year', 'board_id'],
                    'pp_tbl_curriculum_scope_idx'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        if (Schema::hasTable('tst_past_papers') && Schema::hasColumn('tst_past_papers', 'curriculum_board_id')) {
            Schema::table('tst_past_papers', function (Blueprint $table) use ($isSqlite) {
                $table->dropIndex('tst_pp_curriculum_scope_idx');
                if (!$isSqlite) {
                    $table->dropForeign(['curriculum_board_id']);
                }
                $table->dropColumn('curriculum_board_id');
            });
        }

        if (Schema::hasTable('past_paper_tbl') && Schema::hasColumn('past_paper_tbl', 'curriculum_board_id')) {
            Schema::table('past_paper_tbl', function (Blueprint $table) use ($isSqlite) {
                $table->dropIndex('pp_tbl_curriculum_scope_idx');
                if (!$isSqlite) {
                    $table->dropForeign(['curriculum_board_id']);
                }
                $table->dropColumn('curriculum_board_id');
            });
        }
    }
};
