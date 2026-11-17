<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreTeacherClassRequest;
use App\Http\Requests\Teacher\UpdateTeacherClassRequest;
use App\Http\Services\TeacherSubscriptionService;
use App\Models\TeacherClass;
use App\Models\TeacherClassEnrollment;
use App\Models\TeacherProfile;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teacher Portal – Phase 1 classroom management.
 * Every query is scoped to the teacher profile resolved by EnsureTeacherActive.
 */
class TeacherClassController extends Controller
{
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(private TeacherSubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $classes = TeacherClass::ownedBy($profile)
            ->with($this->relations())
            ->withCount($this->enrollmentCounts())
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $classes->map(fn (TeacherClass $class) => $this->present($class))->values(),
            'summary' => [
                'total_classes' => $classes->count(),
                'active_classes' => $classes->where('is_active', true)->count(),
                'total_students' => (int) $classes->sum('student_count'),
                'pending_enrollment_requests' => (int) $classes->sum('pending_requests_count'),
            ],
        ]);
    }

    public function store(StoreTeacherClassRequest $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validated();
        $data['section'] = $data['section'] ?? '';

        $class = $this->withUniqueRetry(function () use ($profile, $data) {
            return DB::transaction(function () use ($profile, $data) {
                $lockedProfile = TeacherProfile::lockForUpdate()->findOrFail($profile->id);
                $this->subscriptions->assertCanCreateClass($lockedProfile);
                $this->ensureNotDuplicate($lockedProfile, $data);
                $class = new TeacherClass($data);
                $class->teacher_profile_id = $lockedProfile->id;
                $class->class_code = TeacherClass::generateUniqueCode();
                $class->is_active = true;
                $class->requires_approval = $data['requires_approval'] ?? true;
                $class->save();

                return $class;
            });
        });

        return response()->json([
            'success' => true,
            'message' => 'Class created successfully.',
            'data' => $this->present($class->load($this->relations())->loadCount($this->enrollmentCounts())),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $class = $this->findOwned($request, $id);

        return response()->json(['success' => true, 'data' => $this->present($class)]);
    }

    public function update(UpdateTeacherClassRequest $request, int $id): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validated();

        if (array_key_exists('section', $data)) {
            $data['section'] = $data['section'] ?? '';
        }

        $class = $this->withUniqueRetry(function () use ($profile, $id, $data) {
            return DB::transaction(function () use ($profile, $id, $data) {
                $lockedProfile = TeacherProfile::lockForUpdate()->findOrFail($profile->id);
                $class = TeacherClass::ownedBy($lockedProfile)->lockForUpdate()->findOrFail($id);
                $candidate = array_merge(
                    $class->only(['class_id', 'subject_id', 'curriculum_board_id', 'section']),
                    array_intersect_key($data, array_flip(['class_id', 'subject_id', 'curriculum_board_id', 'section']))
                );
                $this->ensureNotDuplicate($lockedProfile, $candidate, $class->id);
                if (($data['is_active'] ?? false) && !$class->is_active) {
                    $this->subscriptions->assertCanCreateClass($lockedProfile);
                }
                $class->fill($data);
                if (array_key_exists('is_active', $data)) {
                    $class->is_active = (bool) $data['is_active'];
                }
                $class->save();
                return $class->fresh($this->relations())->loadCount($this->enrollmentCounts());
            });
        });

        return response()->json([
            'success' => true,
            'message' => 'Class updated successfully.',
            'data' => $this->present($class),
        ]);
    }

    /**
     * Soft archive: the classroom is deactivated, never physically deleted, so future
     * enrollments / results that reference it remain intact. It can be reactivated via PUT.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $class = $this->findOwned($request, $id);

        if ($class->is_active) {
            DB::transaction(function () use ($class) {
                $class->is_active = false;
                $class->save();
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Class archived successfully.',
            'data' => $this->present($class->fresh($this->relations())->loadCount($this->enrollmentCounts())),
        ]);
    }

    private function profile(Request $request): TeacherProfile
    {
        $profile = $request->attributes->get('teacher_profile');
        abort_unless($profile instanceof TeacherProfile, 403, 'Teacher access required.');

        return $profile;
    }

    private function findOwned(Request $request, int $id): TeacherClass
    {
        // 404 (not 403) for classes owned by others so their existence is not disclosed.
        return TeacherClass::ownedBy($this->profile($request))
            ->with($this->relations())
            ->withCount($this->enrollmentCounts())
            ->findOrFail($id);
    }

    private function ensureNotDuplicate(TeacherProfile $profile, array $data, ?int $ignoreId = null): void
    {
        $exists = TeacherClass::ownedBy($profile)
            ->where('class_id', $data['class_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('curriculum_board_id', $data['curriculum_board_id'])
            ->where('section', $data['section'] ?? '')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'section' => 'You already have a class for this grade, subject, board and section.',
            ]);
        }
    }

    /**
     * Retries when a concurrently generated invitation code collides; converts a
     * concurrent duplicate-offering insert into a validation error.
     */
    private function withUniqueRetry(callable $callback)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $e) {
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }
                if (str_contains($e->getMessage(), 'class_code') && $attempt < self::MAX_CODE_ATTEMPTS) {
                    continue;
                }
                if (!str_contains($e->getMessage(), 'class_code')) {
                    throw ValidationException::withMessages([
                        'section' => 'You already have a class for this grade, subject, board and section.',
                    ]);
                }
                throw $e;
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    private function relations(): array
    {
        return [
            'academicClass:id,class_name,class_name_um',
            'subject:id,subject_name,subject_name_um',
            'curriculumBoard:id,name',
        ];
    }

    private function enrollmentCounts(): array
    {
        return [
            'enrollments as student_count' => fn ($q) => $q->where('status', TeacherClassEnrollment::STATUS_ACTIVE),
            'enrollments as pending_requests_count' => fn ($q) => $q->where('status', TeacherClassEnrollment::STATUS_PENDING),
        ];
    }

    private function present(TeacherClass $class): array
    {
        return [
            'id' => $class->id,
            'name' => $class->name,
            'section' => $class->section !== '' ? $class->section : null,
            'class_code' => $class->class_code,
            'is_active' => (bool) $class->is_active,
            'requires_approval' => (bool) $class->requires_approval,
            'class' => $class->academicClass ? [
                'id' => $class->academicClass->id,
                'name' => $class->academicClass->class_name,
                'name_um' => $class->academicClass->class_name_um,
            ] : null,
            'subject' => $class->subject ? [
                'id' => $class->subject->id,
                'name' => $class->subject->subject_name,
                'name_um' => $class->subject->subject_name_um,
            ] : null,
            'curriculum_board' => $class->curriculumBoard ? [
                'id' => $class->curriculumBoard->id,
                'name' => $class->curriculumBoard->name,
            ] : null,
            'student_count' => (int) ($class->student_count ?? 0),
            'pending_requests_count' => (int) ($class->pending_requests_count ?? 0),
            'created_at' => $class->created_at?->toIso8601String(),
            'updated_at' => $class->updated_at?->toIso8601String(),
        ];
    }
}
