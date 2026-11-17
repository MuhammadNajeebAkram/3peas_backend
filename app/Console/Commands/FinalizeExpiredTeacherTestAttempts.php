<?php

namespace App\Console\Commands;

use App\Http\Services\TeacherClassTestAttemptLifecycleService;
use Illuminate\Console\Command;

class FinalizeExpiredTeacherTestAttempts extends Command
{
    protected $signature = 'tests:finalize-expired';

    protected $description = 'Synchronize scheduled/expired teacher test assignments and finalize overdue student examination attempts.';

    public function handle(TeacherClassTestAttemptLifecycleService $lifecycle): int
    {
        $summary = $lifecycle->finalizeAllExpiredBatch();

        $this->info(sprintf(
            'Activated %d assignments, closed %d assignments, finalized %d expired attempts.',
            $summary['activated_assignments'],
            $summary['closed_assignments'],
            $summary['finalized_attempts']
        ));

        return self::SUCCESS;
    }
}
