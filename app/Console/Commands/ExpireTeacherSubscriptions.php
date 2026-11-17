<?php

namespace App\Console\Commands;

use App\Http\Services\TeacherSubscriptionService;
use Illuminate\Console\Command;

class ExpireTeacherSubscriptions extends Command
{
    protected $signature = 'teachers:subscriptions-expire';
    protected $description = 'Mark expired teacher subscriptions and append lifecycle history';

    public function handle(TeacherSubscriptionService $subscriptions): int
    {
        $count = $subscriptions->expireDueSubscriptions();
        $this->info("Expired {$count} teacher subscription(s).");
        return self::SUCCESS;
    }
}
