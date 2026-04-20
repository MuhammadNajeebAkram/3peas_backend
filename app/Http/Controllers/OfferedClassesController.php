<?php

namespace App\Http\Controllers;

use App\Models\OfferedClass;
use App\Models\UserClass;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class OfferedClassesController extends Controller
{
    //
    private string $guard = 'web_api';

    private function offeredClassesQuery()
    {
        return OfferedClass::with([
                'userClass:id,class_name,slug',
                'curriculumBoard:id,name',
                'offeredPrograms' => function ($query) {
                    $query->where('is_active', true)->orderBy('display_order');
                },
                'offeredPrograms.programSubjects' => function ($query) {
                    $query->where('is_active', true)->orderBy('display_order');
                },
                 'offeredPrograms.programSubjects.subject'
            ])           
            ->where('is_active', true)
            ->whereHas('offeredPrograms', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('display_order');
    }

    private function userSubscribedClassesQuery(int $userId)
    {
        return UserSubscription::with([
                'offeredProgram.offeredClass.userClass:id,class_name',
                'offeredProgram.offeredClass.curriculumBoard:id,name',
                'offeredProgram.offeredClass',
                'offeredProgram.programSubjects' => function ($query) {
                    $query->where('is_active', true)->orderBy('display_order');
                },

                'offeredProgram' => function ($query) {
                    $query->where('is_active', true)->orderBy('display_order');
                },
                'offeredProgram.programSubjects.subject'
            ])
            ->where('user_id', $userId)
            ->whereHas('offeredProgram', function ($query) {
                $query->where('is_active', true);
            })
            ->whereHas('offeredProgram.offeredClass', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('created_at', 'desc');
    }

    public function getOfferedClasses(){
        $cls = $this->offeredClassesQuery()->get();

        Log::info('Offered classes retrieved for LMS.', [
            'offered_classes_count' => $cls->count(),
            'first_offered_class_id' => optional($cls->first())->id,
            'first_user_class_slug' => optional(optional($cls->first())->userClass)->slug,
            'first_offered_programs_count' => optional(optional($cls->first())->offeredPrograms)->count(),
        ]);

        return response()->json($cls);
    }

    public function getUserSubscribedClasses(Request $request){
        $user = auth($this->guard)->user();

        $cls = $this->userSubscribedClassesQuery($user->id)->get();

        Log::info("User subscribed classes retrieved for user_id: " . $user->id, [
            'subscribed_classes_count' => $cls->count(),
            'first_subscription_id' => optional($cls->first())->id,
            'first_offered_program_id' => optional(optional($cls->first())->offeredProgram)->id,
            'first_offered_class_id' => optional(optional(optional($cls->first())->offeredProgram)->offeredClass)->id,
        ]);

        return response()->json($cls);
    }

    public function getUserDashboardClasses(){
        $user = auth($this->guard)->user();

        $subscribedClasses = $this->userSubscribedClassesQuery($user->id)->get();

        $subscribedOfferedClassIds = $subscribedClasses
            ->pluck('offeredProgram.offeredClass.id')
            ->filter()
            ->unique()
            ->values();

        $offeredClasses = $this->offeredClassesQuery()
            ->whereIn('id', $subscribedOfferedClassIds)
            ->get();

        Log::info("Dashboard classes retrieved for user_id: " . $user->id, [
            'subscribed_classes_count' => $subscribedClasses->count(),
            'offered_classes_count' => $offeredClasses->count(),
            'subscribed_offered_class_ids' => $subscribedOfferedClassIds->all(),
        ]);

        return response()->json([
            'offered_classes' => $offeredClasses,
            'user_subscribed_classes' => $subscribedClasses,
        ]);
    }

    public function getOfferedClassDetails($slug){
       $offereClass = UserClass::where('slug', $slug)
            ->with([
                'offeredClasses' => function ($query) {
                    $query->where('is_active', true)
                        ->orderBy('display_order')
                        ->with([
                            'curriculumBoard:id,name',
                            'offeredPrograms' => function ($query) {
                                $query->where('is_active', true)
                                    ->orderBy('display_order')
                                    ->with([
                                        'programSubjects' => function ($query) {
                                            $query->where('is_active', true)
                                                ->orderBy('display_order')
                                                ->with('subject');
                                        }
                                    ]);
                            }
                        ]);
                }
            ])
            ->firstOrFail();

            Log::info("Offered class details retrieved for slug: " . $slug, [
                'user_class_id' => $offereClass->id,
                'user_class_slug' => $offereClass->slug,
                'offered_classes_count' => optional($offereClass->offeredClasses)->count(),
                'first_offered_class_id' => optional(optional($offereClass->offeredClasses)->first())->id,
                'first_offered_programs_count' => optional(optional(optional($offereClass->offeredClasses)->first())->offeredPrograms)->count(),
            ]);

        return response()->json($offereClass);
    }   

    public function getAllOfferedClassesForAdmin(Request $request){
        try {
            $classes = OfferedClass::with([
                'userClass:id,class_name',
                'curriculumBoard:id,name',
                            ])
            ->orderBy('display_order')
            ->get()
            ->map(function ($offeredClass) {
                return [
                    'id' => $offeredClass->id,
                    'class_name' => $offeredClass->userClass->class_name ?? null,
                    'class_id' => $offeredClass->class_id,
                    'curriculum_board_name' => $offeredClass->curriculumBoard->name ?? null,
                    'curriculum_board_id' => $offeredClass->curriculum_board_id,
                    'price' => $offeredClass->price,
                    'discount_price' => $offeredClass->discount_price,
                    'discount_percent' => $offeredClass->discount_percent,
                    'is_free' => $offeredClass->is_free,
                    'session_start' => $offeredClass->session_start,
                    'session_end' => $offeredClass->session_end,
                    'is_active' => $offeredClass->is_active,
                    'display_order' => $offeredClass->display_order,
                ];
            });

            return response()->json($classes);
        } catch (\Exception $e) {
            Log::error("Error retrieving offered classes for admin: " . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve offered classes'], 500);
        }
    }
    public function getActiveOfferedClassesForAdmin(Request $request){
        try {
            $classes = OfferedClass::with([
                'userClass:id,class_name',
                'curriculumBoard:id,name',
                            ])
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->map(function ($offeredClass) {
                return [
                    'id' => $offeredClass->id,
                   'class_name' => $offeredClass->userClass->class_name . ' (' . $offeredClass->curriculumBoard->name . ')',
                                       
                ];
            });
            return response()->json($classes);
        } catch (\Exception $e) {
            Log::error("Error retrieving active offered classes for admin: " . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve active offered classes'], 500);
        }
    }
    public function saveOfferedClassForAdmin(Request $request){
        $validate = $request->validate([
            'class_id' => 'required|exists:class_tbl,id',
            'curriculum_board_id' => 'required|exists:curriculum_board_tbl,id',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'is_free' => 'required|boolean',
            'session_start' => 'required|date',
            'session_end' => 'required|date|after_or_equal:session_start',
            'is_active' => 'required|boolean',           
        ]);

        try {
            $offeredClass = new OfferedClass();
            $offeredClass->class_id = $request->class_id;
            $offeredClass->curriculum_board_id = $request->curriculum_board_id;
            $offeredClass->price = $request->price;
            $offeredClass->discount_price = $request->discount_price;
            $offeredClass->discount_percent = $request->discount_percent;
            $offeredClass->is_free = $request->is_free;
            $offeredClass->session_start = $request->session_start;
            $offeredClass->session_end = $request->session_end;
            $offeredClass->is_active = $request->is_active;
           
            $offeredClass->save();

            return response()->json([
                'success' => 1,
                'message' => 'Offered class saved successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error("Error saving offered class for admin: " . $e->getMessage());
            return response()->json(['error' => 'Failed to save offered class'], 500);
        }
        }

        public function updateOfferedClassForAdmin(Request $request, $id){
            $validate = $request->validate([
                'class_id' => 'required|exists:class_tbl,id',
                'curriculum_board_id' => 'required|exists:curriculum_board_tbl,id',
                'price' => 'required|numeric|min:0',
                'discount_price' => 'nullable|numeric|min:0',
                'discount_percent' => 'nullable|numeric|min:0|max:100',
                'is_free' => 'required|boolean',
                'session_start' => 'required|date',
                'session_end' => 'required|date|after_or_equal:session_start',
                'is_active' => 'required|boolean',           
            ]);

            try {
                $offeredClass = OfferedClass::findOrFail($id);
                $offeredClass->class_id = $request->class_id;
                $offeredClass->curriculum_board_id = $request->curriculum_board_id;
                $offeredClass->price = $request->price;
                $offeredClass->discount_price = $request->discount_price;
                $offeredClass->discount_percent = $request->discount_percent;
                $offeredClass->is_free = $request->is_free;
                $offeredClass->session_start = $request->session_start;
                $offeredClass->session_end = $request->session_end;
                $offeredClass->is_active = $request->is_active; 
                $offeredClass->save();
                return response()->json([
                    'success' => 1,
                    'message' => 'Offered class updated successfully.'
                ]);
            } catch (\Exception $e) {
                Log::error("Error updating offered class for admin: " . $e->getMessage());
                return response()->json(['error' => 'Failed to update offered class'], 500);
            }
        }

        public function activateOfferedClassForAdmin(Request $request)
        {
            $validate = $request->validate([
                'id' => 'required|integer|exists:offered_classes_tbl,id',
                'activate' => 'required|boolean',
            ]);

            try {
                $offeredClass = OfferedClass::findOrFail($request->id);
                $offeredClass->is_active = $request->activate;
                $offeredClass->save();

                return response()->json([
                    'success' => 1,
                    'message' => 'Offered class activation status updated successfully.'
                ]);
            } catch (\Exception $e) {
                Log::error("Error updating offered class activation status for admin: " . $e->getMessage());
                return response()->json(['error' => 'Failed to update offered class activation status'], 500);
            }
        }
}

