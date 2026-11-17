<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class StudentPaymentRequestPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $ids = collect(['student-payment-requests.view', 'student-payment-requests.review'])
            ->map(fn ($name) => Permission::firstOrCreate(['name' => $name])->id);
        Role::firstOrCreate(['name' => 'super_admin'])->permissions()->syncWithoutDetaching($ids);
        app(\App\Http\Services\StudentSubscriptionPaymentService::class)->recordExistingRequests();
    }
}
