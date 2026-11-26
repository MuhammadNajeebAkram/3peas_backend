<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class ReceiptExtractionAiPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'receipt-extraction-ai.view',
            'receipt-extraction-ai.update',
            'receipt-extraction-ai.test',
        ];

        $permissionIds = [];
        foreach ($names as $name) {
            $permissionIds[] = Permission::firstOrCreate(['name' => $name])->id;
        }

        Role::firstOrCreate(['name' => 'super_admin'])
            ->permissions()
            ->syncWithoutDetaching($permissionIds);
    }
}
