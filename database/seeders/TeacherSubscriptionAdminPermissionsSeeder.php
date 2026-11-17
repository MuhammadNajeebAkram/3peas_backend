<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class TeacherSubscriptionAdminPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'teacher-subscription-plans.view',
        'teacher-subscription-plans.manage',
        'teacher-subscriptions.view',
        'teacher-subscriptions.manage',
        'teacher-subscriptions.history.view',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }
}
