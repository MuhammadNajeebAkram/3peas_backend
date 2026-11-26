<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class WebUserRoleChangePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'web-users.change-role']);
        Role::firstOrCreate(['name' => 'super_admin'])
            ->permissions()
            ->syncWithoutDetaching([$permission->id]);
    }
}
