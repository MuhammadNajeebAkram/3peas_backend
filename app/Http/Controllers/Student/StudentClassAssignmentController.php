<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Services\StudentClassAssignmentService;
use App\Models\WebUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentClassAssignmentController extends Controller
{
    public function __construct(private readonly StudentClassAssignmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'in:all,pending,submitted,submitted_late,closed,overdue'],
        ]);

        return response()->json($this->service->listForStudent($this->student($request), $filters));
    }

    public function show(Request $request, int $assignmentId): JsonResponse
    {
        return response()->json($this->service->showForStudent($this->student($request), $assignmentId));
    }

    public function saveDraft(Request $request, int $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'text_response' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'remove_attachment_ids' => ['sometimes'],
        ]);

        if (array_key_exists('remove_attachment_ids', $validated)) {
            $validated['remove_attachment_ids'] = $this->normalizeIdList($validated['remove_attachment_ids']);
        }

        $files = $this->extractUploadedFiles($request, 'attachments');

        return response()->json(
            $this->service->saveDraft($this->student($request), $assignmentId, $validated, $files)
        );
    }

    public function submit(Request $request, int $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'text_response' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'remove_attachment_ids' => ['sometimes'],
        ]);

        if (array_key_exists('remove_attachment_ids', $validated)) {
            $validated['remove_attachment_ids'] = $this->normalizeIdList($validated['remove_attachment_ids']);
        }

        $files = $this->extractUploadedFiles($request, 'attachments');

        return response()->json(
            $this->service->submit($this->student($request), $assignmentId, $validated, $files)
        );
    }

    public function deleteAttachment(Request $request, int $assignmentId, int $attachmentId): JsonResponse
    {
        return response()->json(
            $this->service->deleteOwnSubmissionAttachment($this->student($request), $assignmentId, $attachmentId)
        );
    }

    public function downloadAssignmentAttachment(Request $request, int $assignmentId, int $attachmentId): Response|StreamedResponse
    {
        return $this->service->streamAssignmentAttachmentForStudent($this->student($request), $assignmentId, $attachmentId);
    }

    public function downloadOwnSubmissionAttachment(Request $request, int $assignmentId, int $attachmentId): Response|StreamedResponse
    {
        return $this->service->streamOwnSubmissionAttachment($this->student($request), $assignmentId, $attachmentId);
    }

    private function student(Request $request): WebUser
    {
        /** @var WebUser|null $user */
        $user = $request->user();
        if (!$user || $user->role !== 'student' || $user->status !== 'active') {
            abort(403, 'Student access only.');
        }

        return $user;
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
