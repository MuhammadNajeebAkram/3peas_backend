<?php

namespace App\Http\Services;

use App\Models\TeacherProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherCollectionPermissionService
{
    public function eligibility(TeacherProfile $profile): array
    {
        $profile->loadMissing('webUser');
        $user = $profile->webUser;
        $reasons = [];

        if ($profile->status !== 'active') {
            $reasons[] = 'Teacher profile must be approved and active.';
        }
        if (!$user || $user->role !== 'teacher') {
            $reasons[] = 'An associated teacher account is required.';
        } elseif ($user->status !== 'active') {
            $reasons[] = 'Teacher web account must be active.';
        }
        if (!$user || blank($user->phone)) {
            $reasons[] = 'A valid contact phone number is required.';
        }
        if (!is_string($profile->collection_code)
            || !preg_match('/^[A-Z0-9-]{6,32}$/', $profile->collection_code)) {
            $reasons[] = 'A valid existing collection code is required.';
        }

        return [
            'eligible' => $reasons === [],
            'reason' => $reasons[0] ?? null,
            'reasons' => $reasons,
        ];
    }

    public function canCollect(?TeacherProfile $profile): bool
    {
        return $profile !== null
            && (bool) $profile->can_collect_student_subscriptions
            && $this->eligibility($profile)['eligible'];
    }

    public function assertCanCollect(?TeacherProfile $profile): TeacherProfile
    {
        abort_unless($this->canCollect($profile), 403, 'This teacher is not authorized to collect student subscriptions.');
        return $profile;
    }

    public function eligibleTeachersQuery(): Builder
    {
        return TeacherProfile::query()
            ->where('can_collect_student_subscriptions', true)
            ->where('status', 'active')
            ->whereNotNull('collection_code')->where('collection_code', '!=', '')
            ->whereHas('webUser', fn (Builder $query) => $query
                ->where('role', 'teacher')->where('status', 'active')->whereNotNull('phone')->where('phone', '!=', ''));
    }

    public function eligibleTeacherForCode(string $code, bool $lock = false): ?TeacherProfile
    {
        $query = $this->eligibleTeachersQuery()->where('collection_code', $code)->with('webUser');
        $profile = $lock ? $query->lockForUpdate()->first() : $query->first();
        return $profile && $this->eligibility($profile)['eligible'] ? $profile : null;
    }

    public function anyEligibleTeacher(): bool
    {
        return $this->eligibleTeachersQuery()->with('webUser')->get()
            ->contains(fn (TeacherProfile $profile) => $this->eligibility($profile)['eligible']);
    }

    public function setPermission(int $profileId, bool $enabled, int $adminId, string $reason): array
    {
        return DB::transaction(function () use ($profileId, $enabled, $adminId, $reason) {
            $profile = TeacherProfile::with('webUser')->lockForUpdate()->findOrFail($profileId);
            $eligibility = $this->eligibility($profile);

            if ($enabled && !$eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'collection_permission' => $eligibility['reasons'],
                ]);
            }

            $before = (bool) $profile->can_collect_student_subscriptions;
            if ($before === $enabled) {
                return ['profile' => $profile, 'changed' => false, 'eligibility' => $eligibility];
            }

            $profile->forceFill(['can_collect_student_subscriptions' => $enabled])->save();
            DB::table('teacher_profile_events')->insert([
                'teacher_profile_id' => $profile->id,
                'admin_id' => $adminId,
                'action' => $enabled ? 'collection_permission_enabled' : 'collection_permission_disabled',
                'collection_code' => null,
                'details' => json_encode([
                    'reason' => $reason,
                    'previous_enabled' => $before,
                    'enabled' => $enabled,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return ['profile' => $profile->fresh('webUser'), 'changed' => true, 'eligibility' => $eligibility];
        });
    }
}
