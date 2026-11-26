<?php

namespace App\Http\Services;

use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSettlement;
use App\Models\TeacherSubscription;
use App\Models\UserSubscription;
use App\Models\WebUser;
use App\Models\WebUserProfile;
use App\Models\WebUserRoleChange;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebUserRoleChangeService
{
    public const ALLOWED_ROLES = ['student', 'teacher'];

    public const BLOCKER_USER_ACCOUNT_INACTIVE = 'USER_ACCOUNT_INACTIVE';
    public const BLOCKER_SAME_ROLE = 'SAME_ROLE';
    public const BLOCKER_UNSUPPORTED_CURRENT_ROLE = 'UNSUPPORTED_CURRENT_ROLE';
    public const BLOCKER_TEACHER_HAS_ACTIVE_CLASSES = 'TEACHER_HAS_ACTIVE_CLASSES';
    public const BLOCKER_TEACHER_HAS_PENDING_PAYMENT_REQUESTS = 'TEACHER_HAS_PENDING_PAYMENT_REQUESTS';
    public const BLOCKER_TEACHER_HAS_PENDING_SETTLEMENTS = 'TEACHER_HAS_PENDING_SETTLEMENTS';
    public const BLOCKER_TEACHER_HAS_UNSETTLED_COLLECTIONS = 'TEACHER_HAS_UNSETTLED_COLLECTIONS';

    public function __construct(
        protected TeacherSubscriptionService $teacherSubscriptions,
        protected TeacherFinancialReportService $teacherFinancials,
    ) {
    }

    /**
     * Read-only pre-flight evaluation of a proposed role transition.
     */
    public function previewRoleChange(int $webUserId, string $targetRole): array
    {
        $targetRole = strtolower(trim($targetRole));
        if (!in_array($targetRole, self::ALLOWED_ROLES, true)) {
            throw ValidationException::withMessages([
                'target_role' => 'Target role must be either student or teacher.',
            ]);
        }

        $user = WebUser::with(['profile', 'teacherProfile'])->findOrFail($webUserId);
        $evaluation = $this->evaluateTransition($user, $targetRole);

        return [
            'user_id' => (int) $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => $user->phone,
            'current_role' => $user->role,
            'target_role' => $targetRole,
            'eligible' => empty($evaluation['blockers']),
            'blockers' => $evaluation['blockers'],
            'warnings' => $evaluation['warnings'],
            'requirements' => $evaluation['requirements'],
        ];
    }

    /**
     * Single transactional entry point for changing an LMS user's role between student and teacher.
     */
    public function changeRole(int $webUserId, string $targetRole, int $adminId, array $options = []): array
    {
        $targetRole = strtolower(trim($targetRole));
        if (!in_array($targetRole, self::ALLOWED_ROLES, true)) {
            throw ValidationException::withMessages([
                'target_role' => 'Target role must be either student or teacher.',
            ]);
        }

        $reason = trim((string) ($options['reason'] ?? ''));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'A reason between 5 and 500 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($webUserId, $targetRole, $adminId, $options, $reason) {
            /** @var WebUser $user */
            $user = WebUser::lockForUpdate()->findOrFail($webUserId);

            /** @var TeacherProfile|null $lockedTeacherProfile */
            $lockedTeacherProfile = TeacherProfile::where('web_user_id', $user->id)
                ->lockForUpdate()
                ->first();

            $user->setRelation('teacherProfile', $lockedTeacherProfile);
            $user->loadMissing('profile');

            $fromRole = strtolower(trim((string) $user->role));

            if ($fromRole === $targetRole) {
                throw ValidationException::withMessages([
                    'target_role' => "User already has the {$targetRole} role.",
                ]);
            }

            // Independently revalidate all eligibility rules and blockers inside the transaction.
            $evaluation = $this->evaluateTransition($user, $targetRole);
            if (!empty($evaluation['blockers'])) {
                throw new HttpResponseException(response()->json([
                    'success' => 0,
                    'code' => $evaluation['blockers'][0]['code'] ?? 'ROLE_CHANGE_BLOCKED',
                    'message' => $evaluation['blockers'][0]['message'] ?? 'Role change is blocked due to active account constraints.',
                    'blockers' => $evaluation['blockers'],
                ], 409));
            }

            if ($fromRole === 'student' && $targetRole === 'teacher') {
                $transitionResult = $this->transitionStudentToTeacher($user, $lockedTeacherProfile, $adminId, $reason, $options);
            } elseif ($fromRole === 'teacher' && $targetRole === 'student') {
                $transitionResult = $this->transitionTeacherToStudent($user, $lockedTeacherProfile, $adminId, $reason);
            } else {
                throw new HttpResponseException(response()->json([
                    'success' => 0,
                    'code' => self::BLOCKER_UNSUPPORTED_CURRENT_ROLE,
                    'message' => "Unsupported role transition from '{$fromRole}' to '{$targetRole}'.",
                ], 409));
            }

            // Invalidate active LMS JWT session so user must re-authenticate under the new role.
            $user->last_token = null;
            $user->save();

            $audit = WebUserRoleChange::create([
                'web_user_id' => $user->id,
                'from_role' => $fromRole,
                'to_role' => $targetRole,
                'changed_by_admin_id' => $adminId,
                'reason' => $reason,
                'metadata' => array_merge($transitionResult['metadata'], [
                    'warnings_present' => $evaluation['warnings'],
                    'session_revoked' => true,
                ]),
                'created_at' => now(),
            ]);

            return [
                'user' => $user->fresh(['profile', 'teacherProfile'])->only(['id', 'name', 'email', 'phone', 'role', 'status']),
                'teacher_profile' => $transitionResult['teacher_profile']
                    ? $transitionResult['teacher_profile']->fresh()->makeHidden('collection_code')
                    : null,
                'teacher_subscription' => $transitionResult['teacher_subscription']
                    ? [
                        'id' => $transitionResult['teacher_subscription']->id,
                        'status' => $transitionResult['teacher_subscription']->status,
                        'plan_id' => $transitionResult['teacher_subscription']->teacher_subscription_plan_id,
                        'plan_name' => $transitionResult['teacher_subscription']->plan?->name,
                    ]
                    : null,
                'role_change' => $audit,
                'session_revoked' => true,
            ];
        });
    }

    /**
     * Evaluate blockers, warnings, and requirements without mutating any data.
     */
    private function evaluateTransition(WebUser $user, string $targetRole): array
    {
        $blockers = [];
        $warnings = [];
        $currentRole = strtolower(trim((string) $user->role));
        $teacherProfile = $user->teacherProfile;

        if (($user->status ?? 'active') !== 'active') {
            $blockers[] = [
                'code' => self::BLOCKER_USER_ACCOUNT_INACTIVE,
                'message' => 'Blocked or inactive user accounts cannot change roles.',
            ];
        }

        if (!in_array($currentRole, self::ALLOWED_ROLES, true)) {
            $blockers[] = [
                'code' => self::BLOCKER_UNSUPPORTED_CURRENT_ROLE,
                'message' => "Current role '{$currentRole}' is not eligible for student/teacher role transitions.",
            ];
        }

        if ($currentRole === $targetRole) {
            $blockers[] = [
                'code' => self::BLOCKER_SAME_ROLE,
                'message' => "User already has the {$targetRole} role.",
            ];
        }

        $requirements = [
            'phone_required' => false,
            'existing_teacher_profile_id' => $teacherProfile?->id,
            'existing_teacher_code' => $teacherProfile?->teacher_code,
            'existing_teacher_status' => $teacherProfile?->status,
        ];

        if ($currentRole === 'student' && $targetRole === 'teacher') {
            $requirements['phone_required'] = blank($user->phone);

            if (Schema::hasTable('user_subscriptions')) {
                $activeStudentSubCount = UserSubscription::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count();

                if ($activeStudentSubCount > 0) {
                    $warnings[] = [
                        'code' => 'ACTIVE_STUDENT_SUBSCRIPTIONS',
                        'message' => "User has {$activeStudentSubCount} active student subscription(s). Records will be preserved, but Student Portal access will no longer be available after conversion.",
                        'meta' => ['count' => $activeStudentSubCount],
                    ];
                }
            }

            if (Schema::hasTable('teacher_class_enrollments')) {
                $activeEnrollments = DB::table('teacher_class_enrollments')
                    ->where('student_web_user_id', $user->id)
                    ->whereIn('status', ['approved', 'pending'])
                    ->count();

                if ($activeEnrollments > 0) {
                    $warnings[] = [
                        'code' => 'ACTIVE_STUDENT_CLASS_ENROLLMENTS',
                        'message' => "User has {$activeEnrollments} student class enrollment(s). Historical records will be preserved, but they will lose student class access.",
                        'meta' => ['count' => $activeEnrollments],
                    ];
                }
            }

            if ($teacherProfile) {
                $warnings[] = [
                    'code' => 'EXISTING_TEACHER_PROFILE_REUSED',
                    'message' => "An existing historical teacher profile ({$teacherProfile->teacher_code}) will be reused and updated rather than creating a duplicate.",
                    'meta' => [
                        'teacher_profile_id' => $teacherProfile->id,
                        'teacher_code' => $teacherProfile->teacher_code,
                        'previous_status' => $teacherProfile->status,
                    ],
                ];
            }

            $warnings[] = [
                'code' => 'STUDENT_PORTAL_ACCESS_LOST',
                'message' => 'The user will lose access to student-only features and will be logged out immediately.',
            ];
        }

        if ($currentRole === 'teacher' && $targetRole === 'student') {
            if ($teacherProfile) {
                $teacherBlockers = $this->evaluateTeacherToStudentBlockers($teacherProfile);
                $blockers = array_merge($blockers, $teacherBlockers);
            }

            $warnings[] = [
                'code' => 'TEACHER_ACCESS_SUSPENDED',
                'message' => 'Teacher profile and active teacher subscription will be suspended, collection permission disabled, and historical teacher records preserved.',
            ];
            $warnings[] = [
                'code' => 'SESSION_REVOKED',
                'message' => 'The user will be logged out immediately and must log in again to access the Student Portal.',
            ];
        }

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'requirements' => $requirements,
        ];
    }

    /**
     * Enforce all mandatory Teacher -> Student blockers using existing financial & class tables.
     */
    private function evaluateTeacherToStudentBlockers(TeacherProfile $profile): array
    {
        $blockers = [];

        if (Schema::hasTable('teacher_classes')) {
            $activeClassesCount = DB::table('teacher_classes')
                ->where('teacher_profile_id', $profile->id)
                ->where('is_active', true)
                ->count();

            if ($activeClassesCount > 0) {
                $blockers[] = [
                    'code' => self::BLOCKER_TEACHER_HAS_ACTIVE_CLASSES,
                    'message' => "Teacher has {$activeClassesCount} active class(es). Deactivate or resolve all active classes before converting to student.",
                    'meta' => ['active_classes_count' => $activeClassesCount],
                ];
            }
        }

        if (Schema::hasTable('subscription_payment_requests')) {
            $pendingPaymentsCount = SubscriptionPaymentRequest::where('teacher_profile_id', $profile->id)
                ->where('payment_method', 'teacher')
                ->where('status', 'pending')
                ->count();

            if ($pendingPaymentsCount > 0) {
                $blockers[] = [
                    'code' => self::BLOCKER_TEACHER_HAS_PENDING_PAYMENT_REQUESTS,
                    'message' => "Teacher has {$pendingPaymentsCount} pending student payment request(s) awaiting resolution.",
                    'meta' => ['pending_payments_count' => $pendingPaymentsCount],
                ];
            }
        }

        if (Schema::hasTable('teacher_settlements')) {
            $pendingSettlementsCount = TeacherSettlement::where('teacher_profile_id', $profile->id)
                ->where('status', 'pending')
                ->count();

            if ($pendingSettlementsCount > 0) {
                $blockers[] = [
                    'code' => self::BLOCKER_TEACHER_HAS_PENDING_SETTLEMENTS,
                    'message' => "Teacher has {$pendingSettlementsCount} pending remittance settlement(s) awaiting admin review.",
                    'meta' => ['pending_settlements_count' => $pendingSettlementsCount],
                ];
            }
        }

        if (Schema::hasTable('subscription_payment_requests') && Schema::hasTable('teacher_settlements')) {
            $summary = $this->teacherFinancials->summary($profile->id);
            $outstandingAmount = $summary['outstanding_balance']['amount'] ?? '0.00';
            $outstandingCents = TeacherSettlementService::cents($outstandingAmount);

            if ($outstandingCents > 0) {
                $blockers[] = [
                    'code' => self::BLOCKER_TEACHER_HAS_UNSETTLED_COLLECTIONS,
                    'message' => "Teacher has an unsettled collection balance of PKR {$outstandingAmount}. All collected funds must be settled before converting to student.",
                    'meta' => [
                        'outstanding_balance' => $outstandingAmount,
                        'currency' => $summary['currency'] ?? 'PKR',
                    ],
                ];
            }
        }

        return $blockers;
    }

    private function transitionStudentToTeacher(
        WebUser $user,
        ?TeacherProfile $existingProfile,
        int $adminId,
        string $reason,
        array $options,
    ): array {
        $teacherStatus = strtolower(trim((string) ($options['teacher_status'] ?? 'active')));
        if (!in_array($teacherStatus, ['active', 'pending'], true)) {
            throw ValidationException::withMessages([
                'teacher_status' => 'Teacher status must be either active or pending.',
            ]);
        }

        $suppliedPhone = isset($options['phone']) ? trim((string) $options['phone']) : '';
        $resolvedPhone = $suppliedPhone !== '' ? $suppliedPhone : trim((string) ($user->phone ?? ''));

        if ($resolvedPhone === '') {
            throw ValidationException::withMessages([
                'phone' => 'A valid contact phone number is required to convert this user to a teacher.',
            ]);
        }

        if ($resolvedPhone !== $user->phone) {
            $phoneTaken = WebUser::withTrashed()
                ->where('phone', $resolvedPhone)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($phoneTaken) {
                throw ValidationException::withMessages([
                    'phone' => 'This phone number is already associated with another account.',
                ]);
            }

            $user->phone = $resolvedPhone;
        }

        $user->role = 'teacher';
        $user->save();

        $reusedProfile = $existingProfile !== null;
        $previousTeacherStatus = $existingProfile?->status;

        $profile = $existingProfile ?? new TeacherProfile();
        if (!$reusedProfile) {
            $profile->web_user_id = $user->id;
            $profile->city_id = $options['city_id'] ?? $user->profile?->city_id;
            $profile->institute_id = $options['institute_id'] ?? $user->profile?->institute_id;
        } else {
            if (array_key_exists('city_id', $options) && $options['city_id'] !== null) {
                $profile->city_id = $options['city_id'];
            } elseif ($profile->city_id === null && $user->profile?->city_id) {
                $profile->city_id = $user->profile->city_id;
            }
            if (array_key_exists('institute_id', $options) && $options['institute_id'] !== null) {
                $profile->institute_id = $options['institute_id'];
            } elseif ($profile->institute_id === null && $user->profile?->institute_id) {
                $profile->institute_id = $user->profile->institute_id;
            }
        }

        // Collection permission is NEVER implicitly granted during role conversion.
        $profile->can_collect_student_subscriptions = false;
        $profile->status = $teacherStatus;
        $profile->admin_note = $reason;
        $profile->status_changed_by = $adminId;
        $profile->status_changed_at = now();

        if ($teacherStatus === 'active') {
            if (!$profile->approved_at) {
                $profile->approved_by = $adminId;
                $profile->approved_at = now();
            }
        }

        $profile->save();

        $generatedCollectionCode = false;
        if ($teacherStatus === 'active' && blank($profile->collection_code)) {
            $code = $this->generateUniqueCollectionCode();
            $this->recordTeacherEvent($profile, $adminId, 'code_assigned', [
                'previous_code' => $profile->collection_code,
                'source' => 'role_change_student_to_teacher',
            ], $code);
            $profile->collection_code = $code;
            $profile->save();
            $generatedCollectionCode = true;
        }

        $this->recordTeacherEvent($profile, $adminId, 'role_changed_student_to_teacher', [
            'reused_profile' => $reusedProfile,
            'previous_status' => $previousTeacherStatus,
            'new_status' => $teacherStatus,
            'reason' => $reason,
        ]);

        $subscription = null;
        if ($teacherStatus === 'active') {
            $subscription = $this->teacherSubscriptions->assignDefaultFree(
                $profile,
                $adminId,
                'free_plan_granted',
                "Automatically assigned during student-to-teacher role conversion: {$reason}"
            );
        }

        return [
            'teacher_profile' => $profile,
            'teacher_subscription' => $subscription,
            'metadata' => [
                'teacher_profile_id' => $profile->id,
                'teacher_code' => $profile->teacher_code,
                'reused_teacher_profile' => $reusedProfile,
                'previous_teacher_status' => $previousTeacherStatus,
                'new_teacher_status' => $teacherStatus,
                'collection_code_generated' => $generatedCollectionCode,
                'collection_permission_enabled' => false,
                'teacher_subscription_id' => $subscription?->id,
                'phone_updated' => $suppliedPhone !== '',
            ],
        ];
    }

    private function transitionTeacherToStudent(
        WebUser $user,
        ?TeacherProfile $existingProfile,
        int $adminId,
        string $reason,
    ): array {
        // Ensure a student profile exists and preserve any existing student profile data.
        $studentProfile = WebUserProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'city_id' => $existingProfile?->city_id,
                'institute_id' => $existingProfile?->institute_id,
                'profile_completed' => filled($user->phone),
                'preferred_language' => 'en',
            ]
        );

        $profileUpdates = [];
        if ($existingProfile) {
            if ($studentProfile->city_id === null && $existingProfile->city_id !== null) {
                $profileUpdates['city_id'] = $existingProfile->city_id;
            }
            if ($studentProfile->institute_id === null && $existingProfile->institute_id !== null) {
                $profileUpdates['institute_id'] = $existingProfile->institute_id;
            }
        }
        if (!$studentProfile->profile_completed && filled($user->phone)) {
            $profileUpdates['profile_completed'] = true;
        }
        if (!empty($profileUpdates)) {
            $studentProfile->update($profileUpdates);
        }

        $suspendedSubscriptionId = null;
        $previousTeacherStatus = null;

        if ($existingProfile) {
            $previousTeacherStatus = $existingProfile->status;

            // Suspend any active teacher subscription using TeacherSubscriptionService.
            if (Schema::hasTable('teacher_subscriptions')) {
                $activeSubscriptions = TeacherSubscription::where('teacher_profile_id', $existingProfile->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->get();

                foreach ($activeSubscriptions as $activeSub) {
                    if ($activeSub->expires_at && $activeSub->expires_at->lessThanOrEqualTo(now())) {
                        $this->teacherSubscriptions->expireIfDue(
                            $activeSub->id,
                            $adminId,
                            "Expired during teacher-to-student role conversion: {$reason}"
                        );
                    } else {
                        $suspended = $this->teacherSubscriptions->suspend(
                            $activeSub->id,
                            $adminId,
                            "Suspended due to teacher-to-student role conversion: {$reason}"
                        );
                        $suspendedSubscriptionId = $suspended->id;
                    }
                }
            }

            // Preserve TeacherProfile and codes; suspend status and revoke collection permission.
            $existingProfile->status = 'suspended';
            $existingProfile->can_collect_student_subscriptions = false;
            $existingProfile->status_changed_by = $adminId;
            $existingProfile->status_changed_at = now();
            $existingProfile->admin_note = $reason;
            $existingProfile->save();

            $this->recordTeacherEvent($existingProfile, $adminId, 'role_changed_teacher_to_student', [
                'previous_status' => $previousTeacherStatus,
                'new_status' => 'suspended',
                'collection_permission_disabled' => true,
                'suspended_subscription_id' => $suspendedSubscriptionId,
                'reason' => $reason,
            ]);
        }

        $user->role = 'student';
        $user->save();

        return [
            'teacher_profile' => $existingProfile,
            'teacher_subscription' => null,
            'metadata' => [
                'student_profile_id' => $studentProfile->id,
                'teacher_profile_id' => $existingProfile?->id,
                'teacher_code' => $existingProfile?->teacher_code,
                'previous_teacher_status' => $previousTeacherStatus,
                'new_teacher_status' => $existingProfile ? 'suspended' : null,
                'collection_permission_disabled' => true,
                'suspended_subscription_id' => $suspendedSubscriptionId,
            ],
        ];
    }

    private function generateUniqueCollectionCode(): string
    {
        do {
            $code = 'COL-' . Str::upper(Str::random(10));
            $existsInEvents = Schema::hasTable('teacher_profile_events')
                && DB::table('teacher_profile_events')->where('collection_code', $code)->exists();
            $existsInProfiles = TeacherProfile::where('collection_code', $code)->exists();
        } while ($existsInEvents || $existsInProfiles);

        return $code;
    }

    private function recordTeacherEvent(
        TeacherProfile $profile,
        int $adminId,
        string $action,
        array $details,
        ?string $collectionCode = null,
    ): void {
        if (!Schema::hasTable('teacher_profile_events')) {
            return;
        }

        DB::table('teacher_profile_events')->insert([
            'teacher_profile_id' => $profile->id,
            'admin_id' => $adminId,
            'action' => $action,
            'collection_code' => $collectionCode,
            'details' => json_encode($details),
            'created_at' => now(),
        ]);
    }
}
