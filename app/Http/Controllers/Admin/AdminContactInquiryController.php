<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminContactInquiryController extends Controller
{
    /**
     * Get paginated contact inquiries with filters and metric counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContactInquiry::query();

        // Filter by type
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search in full_name, email, subject, message, organization
        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search)
                  ->orWhere('organization', 'like', $search)
                  ->orWhere('subject', 'like', $search)
                  ->orWhere('message', 'like', $search);
            });
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $inquiries = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Calculate summary metrics
        $metrics = [
            'total'        => ContactInquiry::count(),
            'pending'      => ContactInquiry::where('status', 'pending')->count(),
            'under_review' => ContactInquiry::where('status', 'under_review')->count(),
            'resolved'     => ContactInquiry::where('status', 'resolved')->count(),
            'publishing'   => ContactInquiry::where('type', 'publishing')->count(),
            'complaint'    => ContactInquiry::where('type', 'complaint')->count(),
            'suggestion'   => ContactInquiry::where('type', 'suggestion')->count(),
            'general'      => ContactInquiry::where('type', 'general')->count(),
        ];

        return response()->json([
            'status'     => 'success',
            'data'       => $inquiries->items(),
            'metrics'    => $metrics,
            'pagination' => [
                'total'        => $inquiries->total(),
                'current_page' => $inquiries->currentPage(),
                'last_page'    => $inquiries->lastPage(),
                'per_page'     => $inquiries->perPage(),
            ],
        ]);
    }

    /**
     * Get a single inquiry.
     */
    public function show(int $id): JsonResponse
    {
        $inquiry = ContactInquiry::find($id);

        if (!$inquiry) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Contact inquiry not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $inquiry,
        ]);
    }

    /**
     * Update inquiry status and/or admin notes.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $inquiry = ContactInquiry::find($id);

        if (!$inquiry) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Contact inquiry not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status'      => 'nullable|string|in:pending,under_review,resolved,archived',
            'admin_notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation error.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        if ($request->has('status') && $request->filled('status')) {
            $inquiry->status = $request->status;
        }

        if ($request->has('admin_notes')) {
            $inquiry->admin_notes = $request->admin_notes;
        }

        $inquiry->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Inquiry updated successfully.',
            'data'    => $inquiry,
        ]);
    }

    /**
     * Delete an inquiry.
     */
    public function destroy(int $id): JsonResponse
    {
        $inquiry = ContactInquiry::find($id);

        if (!$inquiry) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Contact inquiry not found.',
            ], 404);
        }

        $inquiry->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Inquiry deleted successfully.',
        ]);
    }
}
