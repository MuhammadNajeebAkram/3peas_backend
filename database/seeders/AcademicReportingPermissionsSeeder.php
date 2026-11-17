<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AcademicReportingPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'academic-reports.view',
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
    }
}
