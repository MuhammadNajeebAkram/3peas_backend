<?php

namespace App\Http\Controllers;

use App\Http\Services\AwsUploadService;
use App\Http\Services\PastPaperCurriculumOwnershipService;
use App\Models\PastPaperCurriculumAuditLog;
use App\Models\TstPastPaper;
use App\Models\TstPastPaperPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TstPastPaperController extends Controller
{
    protected AwsUploadService $awsUploadService;

    protected PastPaperCurriculumOwnershipService $ownershipService;

    public function __construct()
    {
        $this->awsUploadService = new AwsUploadService();
        $this->ownershipService = new PastPaperCurriculumOwnershipService();
    }

    public function getAllPastPapersForAdmin(Request $request)
    {
        try {
            $query = TstPastPaper::query()
                ->leftJoin('board_tbl as boards', 'boards.id', '=', 'tst_past_papers.board_id')
                ->leftJoin('curriculum_board_tbl as curriculum_boards', 'curriculum_boards.id', '=', 'tst_past_papers.curriculum_board_id')
                ->leftJoin('subject_tbl as subjects', 'subjects.id', '=', 'tst_past_papers.subject_id')
                ->leftJoin('class_tbl as classes', 'classes.id', '=', 'tst_past_papers.class_id')
                ->leftJoin('exam_session_tbl as sessions', 'sessions.id', '=', 'tst_past_papers.session_id')
                ->select([
                    'tst_past_papers.*',
                    'boards.board_name',
                    'curriculum_boards.name as curriculum_board_name',
                    'subjects.subject_name',
                    'classes.class_name',
                    'sessions.session_name',
                ])
                ->withCount('pages')
                ->orderByDesc('tst_past_papers.created_at');

            foreach (['board_id', 'curriculum_board_id', 'subject_id', 'class_id', 'session_id', 'year', 'group'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where("tst_past_papers.{$filter}", $request->input($filter));
                }
            }

            $ownershipStatus = $request->input('ownership_status');
            if ($ownershipStatus === 'assigned') {
                $query->whereNotNull('tst_past_papers.curriculum_board_id');
            } elseif ($ownershipStatus === 'unresolved' || $request->boolean('unresolved_curriculum')) {
                $query->whereNull('tst_past_papers.curriculum_board_id');
            }

            if ($request->filled('is_active')) {
                $query->where('tst_past_papers.is_active', $request->boolean('is_active'));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($builder) use ($search) {
                    $builder->where('tst_past_papers.paper_title', 'like', "%{$search}%")
                        ->orWhere('tst_past_papers.paper_slug', 'like', "%{$search}%");
                });
            }

            $perPage = min((int) $request->input('per_page', 25), 200);
            $pastPapers = $query->paginate($perPage);

            return response()->json([
                'success' => 1,
                'past_papers' => $pastPapers,
                'summary' => $this->ownershipService->getSummaryCounts(PastPaperCurriculumAuditLog::SOURCE_STRUCTURED),
            ]);
        } catch (\Throwable $e) {
            Log::error('getAllPastPapersForAdmin failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve past papers.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPastPaperForAdminById($id)
    {
        try {
            $pastPaper = TstPastPaper::with(['pages', 'board:id,board_name', 'curriculumBoard:id,name', 'subject:id,subject_name', 'userClass:id,class_name'])
                ->findOrFail($id);

            return response()->json([
                'success' => 1,
                'past_paper' => $pastPaper,
                'audit_logs' => $this->ownershipService->getAuditLogs(PastPaperCurriculumAuditLog::SOURCE_STRUCTURED, (int) $pastPaper->id),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Past paper not found.',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function savePastPaperForAdmin(Request $request)
    {
        $validated = $this->validatePastPaper($request);

        try {
            $adminId = Auth::guard('api')->id();
            $validated['paper_slug'] = $this->normalizeSlug($validated['paper_slug'] ?? $validated['paper_title']);
            $validated['is_active'] = $request->boolean('is_active', true);
            $validated['created_by'] = $adminId;
            $validated['updated_by'] = $adminId;

            $pastPaper = DB::transaction(function () use ($validated, $adminId, $request) {
                $created = TstPastPaper::create($validated);

                if ($created->curriculum_board_id !== null && $adminId) {
                    $this->ownershipService->recordInitialCreationAudit(
                        source: PastPaperCurriculumAuditLog::SOURCE_STRUCTURED,
                        paperId: (int) $created->id,
                        curriculumBoardId: (int) $created->curriculum_board_id,
                        adminUserId: (int) $adminId,
                        ipAddress: $request->ip()
                    );
                }

                return $created;
            });

            return response()->json([
                'success' => 1,
                'message' => 'Past paper saved successfully.',
                'past_paper' => $pastPaper,
            ]);
        } catch (\Throwable $e) {
            Log::error('savePastPaperForAdmin failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to save past paper.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePastPaperForAdmin(Request $request, $id)
    {
        $pastPaper = TstPastPaper::findOrFail($id);
        $validated = $this->validatePastPaper($request, $id);

        $existingCurriculumBoardId = $pastPaper->curriculum_board_id !== null ? (int) $pastPaper->curriculum_board_id : null;
        if ($request->exists('curriculum_board_id')) {
            $rawIncoming = $request->input('curriculum_board_id');
            $incomingCurriculumBoardId = ($rawIncoming !== null && $rawIncoming !== '') ? (int) $rawIncoming : null;

            if ($incomingCurriculumBoardId !== $existingCurriculumBoardId) {
                return response()->json([
                    'success' => 0,
                    'code' => 'explicit_ownership_action_required',
                    'message' => 'Use the explicit curriculum-board assignment or reassignment action to change ownership.',
                ], 422);
            }
        }

        unset($validated['curriculum_board_id']);

        try {
            $validated['paper_slug'] = $this->normalizeSlug($validated['paper_slug'] ?? $validated['paper_title']);
            $validated['updated_by'] = Auth::guard('api')->id();

            if ($request->has('is_active')) {
                $validated['is_active'] = $request->boolean('is_active');
            }

            $pastPaper->update($validated);

            return response()->json([
                'success' => 1,
                'message' => 'Past paper updated successfully.',
                'past_paper' => $pastPaper->fresh('pages'),
            ]);
        } catch (\Throwable $e) {
            Log::error('updatePastPaperForAdmin failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update past paper.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function assignCurriculumBoard(Request $request, int $id)
    {
        $validated = $request->validate([
            'curriculum_board_id' => 'required|integer',
            'operation' => ['required', Rule::in(['assign', 'reassign'])],
            'confirm_reassignment' => 'sometimes|boolean',
            'expected_current_curriculum_board_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
        ]);

        $adminId = (int) (Auth::guard('api')->id() ?? Auth::id() ?? 0);

        $result = $this->ownershipService->assignSingle(
            source: PastPaperCurriculumAuditLog::SOURCE_STRUCTURED,
            paperId: $id,
            curriculumBoardId: (int) $validated['curriculum_board_id'],
            operation: $validated['operation'],
            confirmReassignment: (bool) ($validated['confirm_reassignment'] ?? false),
            adminUserId: $adminId,
            expectedCurrentCurriculumBoardId: array_key_exists('expected_current_curriculum_board_id', $validated) && $validated['expected_current_curriculum_board_id'] !== null
                ? (int) $validated['expected_current_curriculum_board_id']
                : null,
            hasExpectedCurrent: $request->exists('expected_current_curriculum_board_id'),
            note: $validated['note'] ?? null,
            ipAddress: $request->ip()
        );

        return response()->json([
            'success' => 1,
            'message' => $result['operation_type'] === PastPaperCurriculumAuditLog::OPERATION_REASSIGN
                ? 'Past paper curriculum board reassigned.'
                : 'Past paper curriculum board assigned.',
            'assignment' => $result,
        ]);
    }

    public function bulkAssignCurriculumBoard(Request $request)
    {
        $validated = $request->validate([
            'paper_ids' => 'required|array|min:1|max:50',
            'paper_ids.*' => 'required|integer|min:1',
            'curriculum_board_id' => 'required|integer',
            'operation' => ['required', Rule::in(['assign', 'reassign'])],
            'confirm_reassignment' => 'sometimes|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        $adminId = (int) (Auth::guard('api')->id() ?? Auth::id() ?? 0);

        $result = $this->ownershipService->bulkAssign(
            source: PastPaperCurriculumAuditLog::SOURCE_STRUCTURED,
            paperIds: $validated['paper_ids'],
            curriculumBoardId: (int) $validated['curriculum_board_id'],
            operation: $validated['operation'],
            confirmReassignment: (bool) ($validated['confirm_reassignment'] ?? false),
            adminUserId: $adminId,
            note: $validated['note'] ?? null,
            ipAddress: $request->ip()
        );

        return response()->json([
            'success' => 1,
            'message' => "Processed {$result['total_requested']} structured past paper record(s): {$result['succeeded_count']} succeeded, {$result['failed_count']} failed.",
            'result' => $result,
        ]);
    }

    public function curriculumAudit(int $id)
    {
        if (!TstPastPaper::where('id', $id)->exists()) {
            return response()->json([
                'success' => 0,
                'code' => 'past_paper_not_found',
                'message' => 'Past paper not found.',
            ], 404);
        }

        return response()->json([
            'success' => 1,
            'audit_logs' => $this->ownershipService->getAuditLogs(PastPaperCurriculumAuditLog::SOURCE_STRUCTURED, $id),
        ]);
    }

    public function activatePastPaperForAdmin(Request $request, $id)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        try {
            $pastPaper = TstPastPaper::findOrFail($id);
            $pastPaper->update([
                'is_active' => $request->boolean('is_active'),
                'updated_by' => Auth::guard('api')->id(),
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Past paper status updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update past paper status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deletePastPaperForAdmin($id)
    {
        try {
            $pastPaper = TstPastPaper::with('pages')->findOrFail($id);
            $pages = $pastPaper->pages->map(fn ($page) => [
                'image_path' => $page->image_path,
                'thumbnail_path' => $page->thumbnail_path,
            ]);

            DB::transaction(function () use ($pastPaper) {
                $pastPaper->delete();
            });

            foreach ($pages as $page) {
                $this->awsUploadService->deleteFileFromS3($page['image_path']);
                $this->awsUploadService->deleteFileFromS3($page['thumbnail_path']);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Past paper deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error('deletePastPaperForAdmin failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to delete past paper.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function savePageForAdmin(Request $request, $pastPaperId)
    {
        $validated = $request->validate([
            'page_no' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('tst_past_paper_pages', 'page_no')
                    ->where('past_paper_id', $pastPaperId)
                    ->where('paper_type', $request->input('paper_type', 'objective')),
            ],
            'paper_type' => ['required', Rule::in(['objective', 'subjective'])],
            'image' => 'required|image|max:10240',
            'thumbnail' => 'required|image|max:5120',
        ]);

        try {
            TstPastPaper::findOrFail($pastPaperId);
            $directory = "tst-past-papers/{$pastPaperId}/{$validated['paper_type']}";
            $imageKey = $this->uploadImage($request->file('image'), $directory, "page-{$validated['page_no']}");
            $thumbnailKey = $this->uploadImage($request->file('thumbnail'), "{$directory}/thumbnails", "page-{$validated['page_no']}-thumb");

            if (!$imageKey || !$thumbnailKey) {
                $this->awsUploadService->deleteFileFromS3($imageKey);
                $this->awsUploadService->deleteFileFromS3($thumbnailKey);

                return response()->json([
                    'success' => 0,
                    'message' => 'Failed to upload page images.',
                ], 500);
            }

            [$width, $height] = $this->getImageSize($request->file('image'));

            $page = TstPastPaperPage::create([
                'past_paper_id' => $pastPaperId,
                'page_no' => $validated['page_no'],
                'paper_type' => $validated['paper_type'],
                'image_path' => $imageKey,
                'thumbnail_path' => $thumbnailKey,
                'image_width' => $width,
                'image_height' => $height,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Past paper page saved successfully.',
                'page' => $page,
            ]);
        } catch (\Throwable $e) {
            Log::error('savePageForAdmin failed', [
                'past_paper_id' => $pastPaperId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to save past paper page.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePageForAdmin(Request $request, $pageId)
    {
        $page = TstPastPaperPage::findOrFail($pageId);
        $paperType = $request->input('paper_type', $page->paper_type);

        $validated = $request->validate([
            'page_no' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::unique('tst_past_paper_pages', 'page_no')
                    ->where('past_paper_id', $page->past_paper_id)
                    ->where('paper_type', $paperType)
                    ->ignore($page->id),
            ],
            'paper_type' => ['sometimes', Rule::in(['objective', 'subjective'])],
            'image' => 'nullable|image|max:10240',
            'thumbnail' => 'nullable|image|max:5120',
        ]);

        try {
            $oldImageKey = null;
            $oldThumbnailKey = null;
            $newImageKey = null;
            $newThumbnailKey = null;
            $directory = "tst-past-papers/{$page->past_paper_id}/{$paperType}";
            $pageNo = $validated['page_no'] ?? $page->page_no;

            if ($request->hasFile('image')) {
                $oldImageKey = $page->image_path;
                $newImageKey = $this->uploadImage($request->file('image'), $directory, "page-{$pageNo}");
                $page->image_path = $newImageKey;
                [$width, $height] = $this->getImageSize($request->file('image'));
                $page->image_width = $width;
                $page->image_height = $height;
            }

            if ($request->hasFile('thumbnail')) {
                $oldThumbnailKey = $page->thumbnail_path;
                $newThumbnailKey = $this->uploadImage($request->file('thumbnail'), "{$directory}/thumbnails", "page-{$pageNo}-thumb");
                $page->thumbnail_path = $newThumbnailKey;
            }

            if (($request->hasFile('image') && !$page->image_path) || ($request->hasFile('thumbnail') && !$page->thumbnail_path)) {
                $this->awsUploadService->deleteFileFromS3($newImageKey);
                $this->awsUploadService->deleteFileFromS3($newThumbnailKey);

                return response()->json([
                    'success' => 0,
                    'message' => 'Failed to upload replacement page image.',
                ], 500);
            }

            $page->fill([
                'page_no' => $pageNo,
                'paper_type' => $paperType,
            ]);
            $page->save();

            $this->awsUploadService->deleteFileFromS3($oldImageKey);
            $this->awsUploadService->deleteFileFromS3($oldThumbnailKey);

            return response()->json([
                'success' => 1,
                'message' => 'Past paper page updated successfully.',
                'page' => $page->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('updatePageForAdmin failed', [
                'page_id' => $pageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to update past paper page.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deletePageForAdmin($pageId)
    {
        try {
            $page = TstPastPaperPage::findOrFail($pageId);
            $imageKey = $page->image_path;
            $thumbnailKey = $page->thumbnail_path;
            $page->delete();

            $this->awsUploadService->deleteFileFromS3($imageKey);
            $this->awsUploadService->deleteFileFromS3($thumbnailKey);

            return response()->json([
                'success' => 1,
                'message' => 'Past paper page deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to delete past paper page.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function searchActivePastPapers(Request $request)
    {
        try {
            $query = TstPastPaper::query()->where('is_active', true);

            foreach (['subject_id', 'class_id', 'board_id', 'session_id', 'year', 'group'] as $filter) {
                if ($request->filled($filter)) {
                    $query->where($filter, $request->input($filter));
                }
            }

            $pastPapers = $query->select('id', 'paper_title', 'paper_slug', 'board_id', 'subject_id', 'class_id', 'session_id', 'year', 'group')
                ->orderByDesc('year')
                ->orderBy('paper_title')
                ->get();

            return response()->json([
                'success' => 1,
                'data' => $pastPapers,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to search active past papers.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActivePastPaperBySlug($slug)
    {
        try {
            $pastPaper = TstPastPaper::with('pages')
                ->where('paper_slug', $slug)
                ->where('is_active', true)
                ->firstOrFail();

            return response()->json([
                'success' => 1,
                'data' => $pastPaper,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Past paper not found.',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function getAllActivePastPaperSlugs()
    {
        try {
            $slugs = TstPastPaper::query()
                ->where('is_active', true)
                ->orderBy('paper_slug')
                ->get(['paper_slug'])
                ->map(fn ($paper) => ['slug' => $paper->paper_slug]);

            return response()->json([
                'success' => 1,
                'data' => $slugs,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve past paper slugs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function validatePastPaper(Request $request, $ignoreId = null): array
    {
        return $request->validate([
            'paper_title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tst_past_papers', 'paper_title')->ignore($ignoreId),
            ],
            'paper_slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('tst_past_papers', 'paper_slug')->ignore($ignoreId),
            ],
            'board_id' => 'required|integer|exists:board_tbl,id',
            'curriculum_board_id' => $ignoreId
                ? 'nullable|integer|exists:curriculum_board_tbl,id'
                : ['nullable', 'integer', Rule::exists('curriculum_board_tbl', 'id')->where('activate', 1)],
            'subject_id' => 'required|integer|exists:subject_tbl,id',
            'class_id' => 'required|integer|exists:class_tbl,id',
            'session_id' => 'required|integer|exists:exam_session_tbl,id',
            'group' => 'required|integer',
            'year' => 'required|integer|min:1900|max:2100',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    protected function normalizeSlug(string $value): string
    {
        return Str::slug($value);
    }

    protected function uploadImage($file, string $directory, string $baseName): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $fileName = $baseName . '-' . uniqid();

        return $this->awsUploadService->uploadFileToS3($file, $extension, $directory, $fileName);
    }

    protected function getImageSize($file): array
    {
        $size = @getimagesize($file->getRealPath());

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }
}
