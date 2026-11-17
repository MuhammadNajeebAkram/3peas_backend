<?php

namespace App\Http\Services;

use App\Models\OfferedProgram;
use App\Models\TeacherClass;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

/**
 * Reusable backend access-control service enforcing student program subscriptions
 * for teacher class joining, approvals, and resource participation.
 */
class StudentClassAccessService
{
    /**
     * Cache covering program IDs per teacher class within request lifecycle to prevent duplicate queries.
     *
     * @var array<int, Collection<int, OfferedProgram>>
     */
    private array $classCoveringProgramsCache = [];

    /**
     * Resolve all active OfferedPrograms that cover the given TeacherClass.
     * Matches through:
     *   teacher_classes.class_id            -> offered_classes.class_id
     *   teacher_classes.curriculum_board_id -> offered_classes.curriculum_board_id
     *   offered_classes.id                  -> offered_programs.offered_class_id
     *   program_subjects.offered_program_id -> offered_programs.id
     *   program_subjects.subject_id         -> teacher_classes.subject_id
     *
     * @return Collection<int, OfferedProgram>
     */
    public function resolveCoveringPrograms(TeacherClass $class): Collection
    {
        $classId = (int) $class->id;
        if (isset($this->classCoveringProgramsCache[$classId])) {
            return $this->classCoveringProgramsCache[$classId];
        }

        $programs = OfferedProgram::query()
            ->where('is_active', true)
            ->whereHas('offeredClass', function ($q) use ($class) {
                $q->where('class_id', $class->class_id)
                    ->where('curriculum_board_id', $class->curriculum_board_id)
                    ->where('is_active', true);
            })
            ->whereHas('programSubjects', function ($q) use ($class) {
                $q->where('subject_id', $class->subject_id)
                    ->where('is_active', true);
            })
            ->with(['offeredClass.userClass:id,class_name', 'offeredClass.curriculumBoard:id,name'])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $this->classCoveringProgramsCache[$classId] = $programs;

        return $programs;
    }

    /**
     * Resolve covering program IDs array.
     *
     * @return array<int>
     */
    public function resolveCoveringProgramIds(TeacherClass $class): array
    {
        return $this->resolveCoveringPrograms($class)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Retrieve all active, non-expired offered_program_ids for a student in a single query.
     * Useful for batch checks without N+1 queries.
     *
     * @return array<int>
     */
    public function getActiveProgramIdsForStudent(int $studentId): array
    {
        return UserSubscription::query()
            ->where('user_id', $studentId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', today());
            })
            ->pluck('offered_program_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Fast check whether a student has an active subscription covering the given TeacherClass.
     */
    public function hasActiveSubscription(int $studentId, TeacherClass $class): bool
    {
        $coveringIds = $this->resolveCoveringProgramIds($class);
        if (empty($coveringIds)) {
            return false;
        }

        return UserSubscription::query()
            ->where('user_id', $studentId)
            ->whereIn('offered_program_id', $coveringIds)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', today());
            })
            ->exists();
    }

    /**
     * Perform detailed authorization check for a student against a teacher class.
     *
     * @return array{
     *     has_access: bool,
     *     status: string,
     *     code: string,
     *     message: string,
     *     matching_subscription: ?UserSubscription,
     *     matching_program: ?OfferedProgram,
     *     covering_programs: Collection<int, OfferedProgram>,
     *     expires_at: ?string,
     *     program_slug: ?string,
     *     program_title: ?string,
     *     subscription_url: ?string
     * }
     */
    public function checkStudentAccess(WebUser $student, TeacherClass $class): array
    {
        if ($student->role !== 'student' || $student->status !== 'active') {
            return [
                'has_access' => false,
                'status' => 'inactive_account',
                'code' => 'student_account_inactive',
                'message' => 'Active student account required.',
                'matching_subscription' => null,
                'matching_program' => null,
                'covering_programs' => collect(),
                'expires_at' => null,
                'program_slug' => null,
                'program_title' => null,
                'subscription_url' => null,
            ];
        }

        $coveringPrograms = $this->resolveCoveringPrograms($class);

        if ($coveringPrograms->isEmpty()) {
            return [
                'has_access' => false,
                'status' => 'program_not_covered',
                'code' => 'program_not_covered',
                'message' => 'This class does not belong to any currently active program offering.',
                'matching_subscription' => null,
                'matching_program' => null,
                'covering_programs' => collect(),
                'expires_at' => null,
                'program_slug' => null,
                'program_title' => null,
                'subscription_url' => null,
            ];
        }

        $coveringProgramIds = $coveringPrograms->pluck('id')->all();

        /** @var Collection<int, UserSubscription> $subscriptions */
        $subscriptions = UserSubscription::query()
            ->where('user_id', $student->id)
            ->whereIn('offered_program_id', $coveringProgramIds)
            ->orderByDesc('id')
            ->get();

        $today = today();

        // 1. Check for an active, non-expired subscription
        $activeSub = $subscriptions->first(function (UserSubscription $sub) use ($today) {
            if ($sub->status !== 'active') {
                return false;
            }
            if ($sub->expires_at && $sub->expires_at->isBefore($today)) {
                return false;
            }
            return true;
        });

        if ($activeSub) {
            $matchingProgram = $coveringPrograms->firstWhere('id', $activeSub->offered_program_id)
                ?? $coveringPrograms->first();

            return [
                'has_access' => true,
                'status' => 'valid',
                'code' => 'active_subscription',
                'message' => 'Valid active program subscription found.',
                'matching_subscription' => $activeSub,
                'matching_program' => $matchingProgram,
                'covering_programs' => $coveringPrograms,
                'expires_at' => $activeSub->expires_at?->toDateString(),
                'program_slug' => $matchingProgram?->slug,
                'program_title' => $matchingProgram?->title,
                'subscription_url' => $matchingProgram ? "/lms/subscribe/{$matchingProgram->slug}" : null,
            ];
        }

        // 2. Check for an expired subscription to provide specific guidance
        $expiredSub = $subscriptions->first(function (UserSubscription $sub) use ($today) {
            return $sub->status === 'active' && $sub->expires_at && $sub->expires_at->isBefore($today);
        });

        if ($expiredSub) {
            $expiredProgram = $coveringPrograms->firstWhere('id', $expiredSub->offered_program_id)
                ?? $coveringPrograms->first();

            return [
                'has_access' => false,
                'status' => 'expired',
                'code' => 'subscription_expired',
                'message' => "Your subscription to {$expiredProgram->title} expired on {$expiredSub->expires_at->toDateString()}. Please renew your subscription to access this class.",
                'matching_subscription' => $expiredSub,
                'matching_program' => $expiredProgram,
                'covering_programs' => $coveringPrograms,
                'expires_at' => $expiredSub->expires_at->toDateString(),
                'program_slug' => $expiredProgram->slug,
                'program_title' => $expiredProgram->title,
                'subscription_url' => "/lms/subscribe/{$expiredProgram->slug}",
            ];
        }

        // 3. Otherwise, subscription is required (missing, pending, or non-matching)
        $primaryProgram = $coveringPrograms->first();

        return [
            'has_access' => false,
            'status' => 'required',
            'code' => 'subscription_required',
            'message' => "An active subscription to {$primaryProgram->title} is required to join and access this class.",
            'matching_subscription' => null,
            'matching_program' => $primaryProgram,
            'covering_programs' => $coveringPrograms,
            'expires_at' => null,
            'program_slug' => $primaryProgram->slug,
            'program_title' => $primaryProgram->title,
            'subscription_url' => "/lms/subscribe/{$primaryProgram->slug}",
        ];
    }

    /**
     * Assert that the student has active subscription access to the class.
     * Throws HTTP 403 HttpResponseException on denial.
     *
     * @throws HttpResponseException
     * @return array Authorization result
     */
    public function assertStudentAccess(WebUser $student, TeacherClass $class, string $actionContext = 'participate'): array
    {
        $result = $this->checkStudentAccess($student, $class);

        if (!$result['has_access']) {
            $programPayload = $result['matching_program'] ? [
                'id' => (int) $result['matching_program']->id,
                'title' => $result['matching_program']->title,
                'slug' => $result['matching_program']->slug,
            ] : null;

            throw new HttpResponseException(response()->json([
                'success' => false,
                'code' => $result['code'],
                'message' => $result['message'],
                'program' => $programPayload,
                'subscription_url' => $result['subscription_url'],
                'expires_at' => $result['expires_at'],
                'action_context' => $actionContext,
            ], 403));
        }

        return $result;
    }
}
