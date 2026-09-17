<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AiPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [];
        foreach (['ai-providers', 'ai-models', 'ai-requests'] as $resource) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $ids[] = Permission::firstOrCreate(['name' => $resource.'.'.$action])->id;
            }
        }
        $ids[] = Permission::firstOrCreate(['name' => 'questions.generate-explanation'])->id;
        $ids[] = Permission::firstOrCreate(['name' => 'questions.generate'])->id;
        $ids[] = Permission::firstOrCreate(['name' => 'ai-providers.test'])->id;
        Role::firstOrCreate(['name' => 'super_admin'])->permissions()->syncWithoutDetaching($ids);
    }
}
