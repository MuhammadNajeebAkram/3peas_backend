<?php

namespace App\Console\Commands;

use App\Http\Services\TeacherSubscriptionService;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\TeacherSubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillTeacherFreeSubscriptions extends Command
{
    protected $signature = 'teachers:subscriptions-backfill-free {--dry-run : Report eligible teachers without writing records}';
    protected $description = 'Assign the default Free plan to approved teachers who have no subscription history';

    public function handle(TeacherSubscriptionService $subscriptions): int
    {
        $dryRun = $this->option('dry-run');
        $counts = [
            'processed' => 0,
            'eligible' => 0,
            'already_subscribed' => 0,
            'history_only_skipped' => 0,
            'excluded_account_status' => 0,
            'inconsistent' => 0,
            'assigned' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $inconsistencies = [];

        $defaults = TeacherSubscriptionPlan::query()->where('is_default', true)->where('is_active', true)->get(['id', 'code']);
        if ($defaults->count() !== 1 || (int) $defaults->first()?->id !== 1 || $defaults->first()?->code !== 'free') {
            $counts['inconsistent']++;
            $inconsistencies[] = 'Expected exactly one active default Free Plan with ID 1; found '.json_encode($defaults->toArray());
        }

        TeacherProfile::query()->where('status', 'active')->with('webUser:id,role,status')
            ->orderBy('id')->chunkById(100, function ($profiles) use (&$counts, &$inconsistencies, $subscriptions, $dryRun) {
                foreach ($profiles as $profile) {
                    $counts['processed']++;
                    $user = $profile->webUser;

                    if (!$user || $user->role !== 'teacher') {
                        $counts['inconsistent']++;
                        $counts['skipped']++;
                        $inconsistencies[] = "Teacher profile {$profile->id} has no teacher web account.";
                        continue;
                    }

                    if ($user->status !== 'active') {
                        $counts['excluded_account_status']++;
                        $counts['skipped']++;
                        continue;
                    }

                    if (!$profile->approved_at) {
                        $counts['inconsistent']++;
                        $inconsistencies[] = "Teacher profile {$profile->id} is active but has no approved_at timestamp.";
                    }

                    $existing = TeacherSubscription::query()->where('teacher_profile_id', $profile->id)->orderBy('id')->get();
                    if ($existing->isNotEmpty()) {
                        $valid = $existing->contains(fn (TeacherSubscription $subscription) =>
                            $subscription->status === 'active'
                            && $subscription->starts_at?->lte(now())
                            && (!$subscription->expires_at || $subscription->expires_at->gt(now()))
                        );
                        if ($valid) {
                            $counts['already_subscribed']++;
                        } else {
                            $counts['history_only_skipped']++;
                        }
                        if ($existing->where('status', 'active')->count() > 1) {
                            $counts['inconsistent']++;
                            $inconsistencies[] = "Teacher profile {$profile->id} has multiple active subscriptions.";
                        }
                        $counts['skipped']++;
                        continue;
                    }

                    $counts['eligible']++;
                    if ($dryRun) continue;

                    try {
                        $assigned = DB::transaction(function () use ($profile, $subscriptions) {
                            $lockedProfile = TeacherProfile::query()->lockForUpdate()->findOrFail($profile->id);
                            if (TeacherSubscription::where('teacher_profile_id', $lockedProfile->id)->exists()) return false;

                            $subscriptions->assignDefaultFree(
                                $lockedProfile,
                                null,
                                'free_plan_backfilled',
                                'Backfilled for an existing approved teacher.'
                            );
                            return true;
                        });

                        if ($assigned) {
                            $counts['assigned']++;
                        } else {
                            $counts['skipped']++;
                            $counts['already_subscribed']++;
                        }
                    } catch (Throwable $e) {
                        $counts['failed']++;
                        $this->error("Teacher profile {$profile->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info($dryRun ? 'Dry run only: no database records were changed.' : 'Backfill complete.');
        $this->table(['Metric', 'Count'], [
            ['Approved active teacher profiles processed', $counts['processed']],
            ['Already subscribed (valid current assignment)', $counts['already_subscribed']],
            ['Eligible for Free Plan assignment', $counts['eligible']],
            ['Excluded due to account status', $counts['excluded_account_status']],
            ['Skipped with non-current subscription history', $counts['history_only_skipped']],
            ['Data inconsistencies', $counts['inconsistent']],
            ['Assigned during this run', $counts['assigned']],
            ['Skipped', $counts['skipped']],
            ['Failed', $counts['failed']],
        ]);
        if ($inconsistencies) {
            $this->warn('Data inconsistencies:');
            foreach (array_slice($inconsistencies, 0, 50) as $message) $this->line('- '.$message);
            if (count($inconsistencies) > 50) $this->line('... and '.(count($inconsistencies) - 50).' more.');
        }
        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
