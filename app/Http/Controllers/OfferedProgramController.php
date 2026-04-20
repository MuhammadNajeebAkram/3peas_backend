<?php

namespace App\Http\Controllers;

use App\Models\OfferedProgram;
use App\Models\ProgramSubject;
use App\Models\UserSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfferedProgramController extends Controller
{
    private string $guard = 'web_api';

    public function getOfferedProgramDetails($slug)
    {
        $offeredProgram = OfferedProgram::with([
            'programSubjects' => function ($query) {
                $query->where('is_active', true)->orderBy('display_order');
            },
            'programSubjects.subject',
            'offeredClass',
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        Log::info("Offered program details retrieved for slug: " . $slug, ['offeredProgram' => $offeredProgram]);

        return response()->json($offeredProgram);
    }

    public function getAllOfferedProgramsForAdmin()
    {
        try {
            $offeredPrograms = OfferedProgram::with([
                'offeredClass',
                'offeredClass.userClass:id,class_name',
                'offeredClass.curriculumBoard:id,name',
                'programSubjects',
            ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($program) {
                    $className = $program->offeredClass?->userClass?->class_name;
                    $boardName = $program->offeredClass?->curriculumBoard?->name;

                    return [
                        'id' => $program->id,
                        'title' => $program->title,
                        'slug' => $program->slug,
                        'description' => $program->description,
                        'is_active' => $program->is_active,
                        'offered_class_id' => $program->offered_class_id,
                        'class_name' => $className && $boardName ? $className . ' (' . $boardName . ')' : null,
                        'display_order' => $program->display_order,
                        'created_at' => $program->created_at,
                        'updated_at' => $program->updated_at,
                        'subjects' => $program->programSubjects,
                    ];
                });

            return response()->json([
                'success' => 1,
                'data' => $offeredPrograms,
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving offered programs for admin: " . $e->getMessage());

            return response()->json([
                'success' => 0,
                'error' => 'Failed to retrieve offered programs',
            ], 500);
        }
    }

    public function getActiveOfferedProgramsForAdmin(){
        try{
            $programs = OfferedProgram::where('is_active', 1)
            ->select([
                'id', 'title'
            ])->get();

            return response()->json([
                'success' => 1,
                'data' => $programs
            ]);

        }
        catch(\Exception $e){
            Log::error('error in getActiveOfferedProgramsForAdmin', $e->getMessage());
            return response()->json([
                'error' => $e->getMessage()
            ]);
        }
    }

    public function saveOfferedProgramForAdmin(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'required|string|max:255|unique:offered_programs,slug',
                'description' => 'nullable|string',
                'offered_class_id' => 'required|exists:offered_classes,id',
                'display_order' => 'nullable|integer',
                'is_active' => 'nullable|boolean',
                'subjects' => 'required|array|min:1',
                'subjects.*.subject_id' => 'required|integer|exists:subject_tbl,id',
                'subjects.*.display_order' => 'nullable|integer',
                'subjects.*.is_demo_available' => 'nullable|boolean',
                'subjects.*.is_free' => 'nullable|boolean',
                'subjects.*.is_active' => 'nullable|boolean',
            ]);

            $offeredProgram = DB::transaction(function () use ($validatedData) {
                $offeredProgram = OfferedProgram::create([
                    'title' => $validatedData['title'],
                    'slug' => $validatedData['slug'],
                    'description' => $validatedData['description'] ?? null,
                    'offered_class_id' => $validatedData['offered_class_id'],
                    'display_order' => $validatedData['display_order'] ?? 1,
                    'is_active' => $validatedData['is_active'] ?? true,
                ]);

                $subjects = collect($validatedData['subjects'])->map(function ($subject) use ($offeredProgram) {
                    return [
                        'offered_program_id' => $offeredProgram->id,
                        'subject_id' => $subject['subject_id'],
                        'display_order' => $subject['display_order'] ?? 1,
                        'is_demo_available' => $subject['is_demo_available'] ?? false,
                        'is_free' => $subject['is_free'] ?? false,
                        'is_active' => $subject['is_active'] ?? true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })->all();

                ProgramSubject::insert($subjects);

                return $offeredProgram->load([
                    'offeredClass.userClass:id,class_name',
                    'offeredClass.curriculumBoard:id,name',
                    'programSubjects.subject',
                ]);
            });

            return response()->json([
                'success' => 1,
                'data' => $offeredProgram,
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error saving offered program for admin: " . $e->getMessage());

            return response()->json([
                'success' => 0,
                'error' => 'Failed to save offered program',
            ], 500);
        }
    }

    public function updateOfferedProgramForAdmin(Request $request, $id)
    {
        try {
            $offeredProgram = OfferedProgram::findOrFail($id);

            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'slug' => 'required|string|max:255|unique:offered_programs,slug,' . $id,
                'description' => 'nullable|string',
                'offered_class_id' => 'required|exists:offered_classes,id',
                'display_order' => 'nullable|integer',
                'is_active' => 'nullable|boolean',
                'subjects' => 'required|array|min:1',
                'subjects.*.subject_id' => 'required|integer|exists:subject_tbl,id',
                'subjects.*.display_order' => 'nullable|integer',
                'subjects.*.is_demo_available' => 'nullable|boolean',
                'subjects.*.is_free' => 'nullable|boolean',
                'subjects.*.is_active' => 'nullable|boolean',
            ]);

            $offeredProgram = DB::transaction(function () use ($offeredProgram, $validatedData) {
                $offeredProgram->update([
                    'title' => $validatedData['title'],
                    'slug' => $validatedData['slug'],
                    'description' => $validatedData['description'] ?? null,
                    'offered_class_id' => $validatedData['offered_class_id'],
                    'display_order' => $validatedData['display_order'] ?? 1,
                    'is_active' => $validatedData['is_active'] ?? true,
                ]);

                $incomingSubjects = collect($validatedData['subjects']);
                $incomingSubjectIds = $incomingSubjects->pluck('subject_id')->all();

                ProgramSubject::where('offered_program_id', $offeredProgram->id)
                    ->whereNotIn('subject_id', $incomingSubjectIds)
                    ->delete();

                foreach ($incomingSubjects as $subject) {
                    ProgramSubject::updateOrCreate(
                        [
                            'offered_program_id' => $offeredProgram->id,
                            'subject_id' => $subject['subject_id'],
                        ],
                        [
                            'display_order' => $subject['display_order'] ?? 1,
                            'is_demo_available' => $subject['is_demo_available'] ?? false,
                            'is_free' => $subject['is_free'] ?? false,
                            'is_active' => $subject['is_active'] ?? true,
                        ]
                    );
                }

                return $offeredProgram->load([
                    'offeredClass.userClass:id,class_name',
                    'offeredClass.curriculumBoard:id,name',
                    'programSubjects.subject',
                ]);
            });

            return response()->json([
                'success' => 1,
                'data' => $offeredProgram,
            ]);
        } catch (\Exception $e) {
            Log::error("Error updating offered program for admin: " . $e->getMessage());

            return response()->json([
                'success' => 0,
                'error' => 'Failed to update offered program',
            ], 500);
        }   
    }

    public function getUserSubscribedOfferedProgramsForLMS()
    {
        try {
            $user = auth($this->guard)->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized',
                ], 401);
            }

            $programs = UserSubscription::with([
                    'offeredProgram.offeredClass.userClass:id,class_name',
                    'offeredProgram.offeredClass.curriculumBoard:id,name',
                    'offeredProgram.programSubjects' => function ($query) {
                        $query->where('is_active', true)->orderBy('display_order');
                    },
                    'offeredProgram.programSubjects.subject',
                ])
                ->where('user_id', $user->id)
                ->whereHas('offeredProgram', function ($query) {
                    $query->where('is_active', true);
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->pluck('offeredProgram')
                ->filter()
                ->unique('id')
                ->values()
                ->map(function ($program) {
                    return [
                        'id' => $program->id,
                        'name' => $program->title,
                        'slug' => $program->slug,
                        'description' => $program->description,
                        'offered_class_id' => $program->offered_class_id,
                        'subjects' => $program->programSubjects
                            ->pluck('subject')
                            ->filter()
                            ->values(),
                    ];
                });

            Log::info('User subscribed offered programs retrieved', [
                'user_id' => $user->id,
                'program_count' => $programs->count(),
            ]);

            return response()->json([
                'success' => 1,
                'data' => $programs,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getUserSubscribedOfferedProgramsForLMS', [
                'message' => $e->getMessage(),
                'user_id' => optional(auth($this->guard)->user())->id,
            ]);

            return response()->json([
                'success' => 0,
                'error' => 'Failed to retrieve subscribed offered programs.',
            ], 500);
        }
    }

}
