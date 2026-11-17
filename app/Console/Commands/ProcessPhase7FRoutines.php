<?php

namespace App\Console\Commands;

use App\Http\Services\TeacherClassAssignmentService;
use App\Http\Services\TeacherClassCalendarService;
use Illuminate\Console\Command;

class ProcessPhase7FRoutines extends Command
{
    protected $signature = 'phase7f:process-scheduled';

    protected $description = 'Publish scheduled class assignments and dispatch due/calendar reminders';

    public function __construct(
        private readonly TeacherClassAssignmentService $assignmentService,
        private readonly TeacherClassCalendarService $calendarService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $assignmentStats = $this->assignmentService->processScheduledAndReminders();
        $calendarReminders = $this->calendarService->processReminders();

        $this->info(sprintf(
            'Phase 7F scheduled processing complete: %d published, %d assignment reminders, %d calendar reminders.',
            $assignmentStats['published_count'],
            $assignmentStats['reminder_count'],
            $calendarReminders
        ));

        return self::SUCCESS;
    }
}
