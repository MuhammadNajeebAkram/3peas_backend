<?php

namespace App\Console\Commands;

use App\Http\Services\TeacherClassAnnouncementService;
use Illuminate\Console\Command;

class PublishScheduledClassAnnouncements extends Command
{
    protected $signature = 'announcements:publish-scheduled';

    protected $description = 'Publish due scheduled class announcements and notify actively enrolled students once.';

    public function handle(TeacherClassAnnouncementService $service): int
    {
        $count = $service->syncScheduledForClasses();
        $this->info("Published {$count} scheduled class announcement(s).");

        return self::SUCCESS;
    }
}
