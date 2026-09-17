<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class TeacherPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [];
        foreach (['teachers.view', 'teachers.approve', 'teacher-settlements.view', 'teacher-settlements.review'] as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name])->id;
        }
        Role::firstOrCreate(['name' => 'super_admin'])->permissions()->syncWithoutDetaching($ids);
    }
}
