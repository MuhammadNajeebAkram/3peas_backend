<?php

namespace App\Http\Controllers;

use App\Models\StudentActivity;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StudentActivityController extends Controller
{
    public function getStudentActivitiesForLms(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
            'activity_type' => ['nullable', 'string'],
            'offered_program_id' => ['nullable', 'integer', 'exists:offered_programs,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subject_tbl,id'],
        ]);

        try {
            $user = $request->user();
            $limit = (int) ($validated['limit'] ?? 10);

            $activities = StudentActivity::query()
                ->where('user_id', $user->id)
                ->when(
                    isset($validated['activity_type']),
                    fn ($query) => $query->where('activity_type', $validated['activity_type'])
                )
                ->when(
                    isset($validated['offered_program_id']),
                    fn ($query) => $query->where('offered_program_id', $validated['offered_program_id'])
                )
                ->when(
                    isset($validated['subject_id']),
                    fn ($query) => $query->where('subject_id', $validated['subject_id'])
                )
                ->orderByDesc('activity_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(function (StudentActivity $activity) {
                    return [
                        'id' => $activity->id,
                        'activity_type' => $activity->activity_type,
                        'title' => $activity->title,
                        'description' => $activity->description,
                        'offered_program_id' => $activity->offered_program_id,
                        'subject_id' => $activity->subject_id,
                        'unit_id' => $activity->unit_id,
                        'reference_id' => $activity->reference_id,
                        'reference_type' => $activity->reference_type,
                        'meta' => $activity->meta,
                        'activity_at' => optional($activity->activity_at)->toISOString(),
                    ];
                })
                ->values();

            return response()->json([
                'success' => 1,
                'data' => $activities,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
