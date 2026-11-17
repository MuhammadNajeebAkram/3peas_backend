<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class QuestionReportPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'question-reports.view',
        'question-reports.manage',
    ];

    public function run(): void
    {
        $ids = [];
        foreach (self::PERMISSIONS as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name])->id;
        }

        $superAdmin = Role::where('name', 'super_admin')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching($ids);
        }

        $admin = Role::where('name', 'admin')->first();
        if ($admin) {
            $admin->permissions()->syncWithoutDetaching($ids);
        }
    }
}
