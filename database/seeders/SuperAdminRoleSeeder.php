<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SuperAdminRoleSeeder extends Seeder
{
    /**
     * Seed the default super admin role with every permission.
     */
    public function run(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin']);
        if (!$role->display_name) {
            $role->display_name = 'Super Admin';
            $role->save();
        }

        $role->permissions()->sync(
            Permission::query()->pluck('id')->all()
        );
    }
}
