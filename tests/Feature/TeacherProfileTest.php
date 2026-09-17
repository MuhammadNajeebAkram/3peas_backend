<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'teacher_test', 'database.connections.teacher_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('teacher_test');
        foreach (['web_users', 'users', 'institute_tbl', 'city_tbl'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
            });
            DB::table($name)->insert(['id' => 1]);
        }
        (require database_path('migrations/2026_09_17_000001_create_teacher_profiles_table.php'))->up();
    }

    public function test_profile_starts_pending_and_teacher_code_survives_updates(): void
    {
        $profile = TeacherProfile::create(['web_user_id' => 1, 'institute_id' => 1, 'city_id' => 1]);
        $code = $profile->teacher_code;
        $this->assertMatchesRegularExpression('/^TCH-[A-Z0-9]{12}$/', $code);
        $this->assertSame('pending', $profile->fresh()->status);
        $this->assertNull($profile->fresh()->collection_code);
        $this->assertSame(1, $profile->institute->id);
        $this->assertSame(1, $profile->city->id);
        $profile->city_id = null;
        $profile->save();
        $this->assertSame($code, $profile->fresh()->teacher_code);
        foreach (['status', 'collection_code', 'teacher_code', 'approved_by', 'approved_at', 'admin_note'] as $field) {
            $this->assertFalse($profile->isFillable($field));
        }
    }

    public function test_one_profile_per_web_user_is_enforced(): void
    {
        TeacherProfile::create(['web_user_id' => 1]);
        $this->expectException(QueryException::class);
        TeacherProfile::create(['web_user_id' => 1]);
    }

    public function test_unknown_institute_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        TeacherProfile::create(['web_user_id' => 1, 'institute_id' => 999]);
    }

    public function test_unknown_city_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        TeacherProfile::create(['web_user_id' => 1, 'city_id' => 999]);
    }

    public function test_collection_codes_cannot_be_shared(): void
    {
        DB::table('web_users')->insert(['id' => 2]);
        $first = TeacherProfile::create(['web_user_id' => 1]);
        $first->collection_code = 'COL-TEST';
        $first->save();
        $second = TeacherProfile::create(['web_user_id' => 2]);
        $second->collection_code = 'COL-TEST';
        $this->expectException(QueryException::class);
        $second->save();
    }
}
