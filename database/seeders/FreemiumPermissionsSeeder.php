<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class FreemiumPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'freemium.view',
        'freemium.create',
        'freemium.update',
        'freemium.activate',
        'freemium.delete',
        'freemium.analytics',
    ];

    /**
     * Run the database seeds.
     * Note: Do NOT execute in automated migrations or production without authorization.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }
}
