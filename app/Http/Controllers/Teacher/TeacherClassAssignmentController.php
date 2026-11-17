<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Services\TeacherClassAssignmentService;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherClassAssignmentController extends Controller
{
    public function __construct(private readonly TeacherClassAssignmentService $service)
    {
    }

    public function index(Request $request, int $classId): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:all,draft,scheduled,published,closed,archived'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($this->service->listAssignments($this->profile($request), $classId, $filters));
    }

    public function show(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->getAssignmentDetail($this->profile($request), $classId, $assignmentId));
    }

    public function store(Request $request, int $classId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'instructions' => ['required', 'string', 'min:2', 'max:20000'],
            'book_unit_id' => ['nullable', 'integer', 'min:1'],
            'book_unit_topic_id' => ['nullable', 'integer', 'min:1'],
            'max_marks' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'assignment_mode' => ['required', 'in:entire_class,selected_students'],
            'action' => ['sometimes', 'in:draft,schedule,publish'],
            'late_policy' => ['sometimes', 'in:allow_late,block_late'],
            'allow_resubmission' => ['sometimes'],
            'scheduled_publish_at' => ['nullable', 'string', 'max:40'],
            'due_at' => ['nullable', 'string', 'max:40'],
            'student_ids' => ['sometimes'],
        ]);

        if (array_key_exists('allow_resubmission', $validated)) {
            $validated['allow_resubmission'] = filter_var($validated['allow_resubmission'], FILTER_VALIDATE_BOOLEAN);
        }

        $files = $this->extractUploadedFiles($request, 'attachments');

        return response()->json(
            $this->service->createAssignment($this->profile($request), $classId, $validated, $files),
            201
        );
    }

    public function update(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:255'],
            'instructions' => ['sometimes', 'string', 'min:2', 'max:20000'],
            'book_unit_id' => ['nullable', 'integer', 'min:1'],
            'book_unit_topic_id' => ['nullable', 'integer', 'min:1'],
            'max_marks' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'assignment_mode' => ['sometimes', 'in:entire_class,selected_students'],
            'late_policy' => ['sometimes', 'in:allow_late,block_late'],
            'allow_resubmission' => ['sometimes'],
            'scheduled_publish_at' => ['nullable', 'string', 'max:40'],
            'due_at' => ['nullable', 'string', 'max:40'],
            'student_ids' => ['sometimes'],
            'remove_attachment_ids' => ['sometimes'],
        ]);

        if (array_key_exists('allow_resubmission', $validated)) {
            $validated['allow_resubmission'] = filter_var($validated['allow_resubmission'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('remove_attachment_ids', $validated)) {
            $validated['remove_attachment_ids'] = $this->normalizeIdList($validated['remove_attachment_ids']);
        }

        $files = $this->extractUploadedFiles($request, 'attachments');

        return response()->json(
            $this->service->updateAssignment($this->profile($request), $classId, $assignmentId, $validated, $files)
        );
    }

    public function publish(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->publishNow($this->profile($request), $classId, $assignmentId));
    }

    public function close(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->closeAssignment($this->profile($request), $classId, $assignmentId));
    }

    public function reopen(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'due_at' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(
            $this->service->reopenAssignment($this->profile($request), $classId, $assignmentId, $validated['due_at'] ?? null)
        );
    }

    public function archive(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->archiveAssignment($this->profile($request), $classId, $assignmentId));
    }

    public function destroy(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->deleteAssignment($this->profile($request), $classId, $assignmentId));
    }

    public function deleteAttachment(Request $request, int $classId, int $assignmentId, int $attachmentId): JsonResponse
    {
        return response()->json(
            $this->service->deleteAttachment($this->profile($request), $classId, $assignmentId, $attachmentId)
        );
    }

    public function downloadAttachment(Request $request, int $classId, int $assignmentId, int $attachmentId): Response|StreamedResponse
    {
        return $this->service->streamAttachmentForTeacher($this->profile($request), $classId, $assignmentId, $attachmentId);
    }

    public function submissions(Request $request, int $classId, int $assignmentId): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:all,pending,submitted,submitted_late,closed,overdue'],
            'late_only' => ['sometimes', 'nullable'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);

        return response()->json($this->service->listSubmissions($this->profile($request), $classId, $assignmentId, $filters));
    }

    public function submissionDetail(Request $request, int $classId, int $assignmentId, int $studentWebUserId): JsonResponse
    {
        return response()->json(
            $this->service->showSubmissionDetail($this->profile($request), $classId, $assignmentId, $studentWebUserId)
        );
    }

    public function downloadSubmissionAttachment(Request $request, int $classId, int $assignmentId, int $attachmentId): Response|StreamedResponse
    {
        return $this->service->streamSubmissionAttachmentForTeacher(
            $this->profile($request),
            $classId,
            $assignmentId,
            $attachmentId
        );
    }

    private function profile(Request $request): TeacherProfile
    {
        return $request->user()->teacherProfile;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function extractUploadedFiles(Request $request, string $key): array
    {
        $raw = $request->file($key, []);
        if ($raw instanceof UploadedFile) {
            return [$raw];
        }
        return is_array($raw) ? array_values(array_filter($raw, fn ($f) => $f instanceof UploadedFile)) : [];
    }

    private function normalizeIdList(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : explode(',', $raw);
        }
        return is_array($raw) ? array_values(array_unique(array_filter(array_map('intval', $raw)))) : [];
    }
}
