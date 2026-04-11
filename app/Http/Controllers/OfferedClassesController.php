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

        return response()->json($cls);
    }

    public function getUserSubscribedClasses(Request $request){
        $user = auth($this->guard)->user();

        $cls = $this->userSubscribedClassesQuery($user->id)->get();

        Log::info("User subscribed classes retrieved for user_id: " . $user->id, ['subscribed_classes_count' => $cls->count()]);

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

            Log::info("Offered class details retrieved for slug: " . $slug, ['offeredClass' => $offereClass]);

        return response()->json($offereClass);
    }   
}
