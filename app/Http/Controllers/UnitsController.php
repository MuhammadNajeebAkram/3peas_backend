<?php

namespace App\Http\Controllers;

use App\Models\BookUnit;
use App\Models\OfferedProgram;
use App\Models\UserSubscription;
use App\Http\Services\BookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnitsController extends Controller
{
    private BookService $bookService;
    private string $guard = 'web_api';

    public function __construct(BookService $bookService)
    {
        $this->bookService = $bookService;
    }
   
    //
    public function getAllUnits(Request $request){
        try{
            $units = DB::table('book_unit_view')->get();

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'units' => 'Failed to retrieve units'], 500);

        }
    }
    public function getUnitsOfBook($book_id){
        try{
            $units = DB::table('book_unit_tbl')
            ->where('book_id', '=', $book_id)
            ->where('activate', '=', 1)
            ->select([
                'id',
                'unit_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'units' => 'Failed to retrieve units'], 500);

        }
    }
    public function getUnitsByBook(Request $request){
        try{
            $units = DB::table('book_unit_tbl')
            ->where('book_id', '=', $request -> book_id)
            ->where('activate', '=', 1)
            ->select([
                'id',
                'unit_name'
            ])
            ->get();

            return response()->json([
                'success' => 1,
                'units' => $units
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'units' => 'Failed to retrieve units'], 500);

        }
    }

    public function saveUnit(Request $request){
        $units = $this->bookService->saveUnit($request);
        $unitsData = $units->getData();

        if($unitsData->success == 1){
            return response()->json([
                'success' => 1, // Successfully inserted
                'message' => 'Unit saved successfully.'
            ]);
        }
        else {
            return response()->json([
                'success' => $unitsData->success, // Duplicate entry or error
                'message' => $unitsData->message
            ]);
        }
       


       /*
        try{

            

            
        $checkDuplicate = DB::table('book_unit_tbl')
        ->where('unit_name', '=', $request->unit_name)
        ->where('book_id', '=', $request -> book_id)
        ->exists();  // Use exists() to check if the record exists

        if ($checkDuplicate){

            // Duplicate Record Exists
            return response()->json([
                'success' => 2, // Duplicate entry
                'message' => 'Unit Name is already exists.'
            ]);

        }

            $units = DB::table('book_unit_tbl')
            ->insert([
                'unit_name' => $request -> unit_name,
                'unit_no' => $request -> unit_no,
                'book_id' => $request -> book_id,               
                'activate' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => 1 // Successfully inserted
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0,  // Error occurred
                'error' => $e->getMessage(),
            ]);

        }*/
    }

    public function editUnit(Request $request){
        $units = $this->bookService->updateUnit($request, $request->id);
        $unitsData = $units->getData();

        if($unitsData->success == 1){
            return response()->json([
                'success' => 1, // Successfully updated
                'message' => 'Unit updated successfully.'
            ]);
        }
        else {
            return response()->json([
                'success' => $unitsData->success, // Duplicate entry or error
                'message' => $unitsData->message
            ]);
        }
       /* try{

            if($request -> unit_name != $request -> oldUnitName){
                $checkDuplicate = DB::table('book_unit_tbl')
                ->where('unit_name', '=', $request->unit_name)
                ->where('book_id', '=', $request -> book_id)
                ->exists();  // Use exists() to check if the record exists
                if($checkDuplicate){
                    return response()->json([
                        'success' => 2, // Duplicate entry
                        'message' => 'Unit Name is already exists.'
                    ]); 
                }
        
            }

            $unit = DB::table('book_unit_tbl')
         ->where('id', '=', $request -> id)
        ->update(['unit_name' => $request -> unit_name,
                  'unit_no' => $request -> unit_no,
                  'book_id' => $request -> book_id,                  
                  'updated_at' => now()]);

                  if ($unit) {
                    return response()->json(['success' => 1], 200);
                } else {
                    return response()->json(['success' => 3, 'message' => 'Bad Request'], 400);
                }

        }
        catch(\Exception $e){

            return response()->json([
                'success' => 0, // error
                'message' => $e->getMessage(),
            ]);

        }*/
    }
    public function activateUnit(Request $request){
        $editClass = DB::table('book_unit_tbl')
         ->where('id', '=', $request -> id)
        ->update(['activate' => $request -> activate,
                  'updated_at' => now()]);

        if ($editClass) {
            return response()->json(['success' => 1], 200);
        } else {
            return response()->json(['success' => 0], 400);
        }
    }
    public function getUnitsByUserSelectedBook(Request $request){
        $units = $this->bookService->getUnitsOfUserSelectedBook($request);
        $unitsData = $units->getData();

        if($unitsData->success == 1){
             return response()->json([
            'success' => $unitsData->success,
            'units' => $unitsData->units,
        ]);
           
        }
        else{
             return response()->json([
                'success' => 0,
                'message' => $unitsData->message,
            ]);
        }
       
       /* try{
            $info = DB::table('user_profile_tbl')
            ->where('user_id', $request->user()->id)
            ->select('class_id', 'curriculum_board_id')
            ->first();

           /* $units = DB::table('book_unit_tbl as but')
            ->join('book_tbl as bt', 'but.book_id', '=', 'bt.id')
            ->where('bt.class_id', $info->class_id)
            ->where('bt.subject_id', $request->subject_id)
            ->where('bt.curriculum_board_id', $info->curriculum_board_id)
            ->where('bt.activate', 1)
            ->select('but.id', 'but.unit_name')
            ->get();

            $units = BookUnit::whereHas('book', function ($query) use($info, $request){
                $query->where('class_id', $info->class_id)
                ->where('curriculum_board_id', $info->curriculum_board_id)
                ->where('subject_id', $request->subject_id)
              ->where('activate', 1);
            })
            ->where('activate', 1)
            ->orderBy('book_id', 'asc')
            ->orderBy('unit_no')
            ->select('id', 'unit_name')->get();

            return response()->json([
                'success' => 1,
                'units' => $units,
            ]);

        }
        catch(\Exception $e){
            return response()->json([
                'success' => 0, // error
                'message' => $e->getMessage(),
            ]);

        }*/
    }

    public function getUnitsOfUserSubjectForLMS($program_id, $subject_id)
    {
        try {
            $user = auth($this->guard)->user();

            if (!$user) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $hasSubscription = UserSubscription::where('user_id', $user->id)
                ->where('offered_program_id', $program_id)
                ->exists();

            if (!$hasSubscription) {
                return response()->json([
                    'success' => 0,
                    'message' => 'You are not subscribed to this program.',
                ], 403);
            }

            $program = OfferedProgram::with([
                    'offeredClass:id,class_id,curriculum_board_id',
                    'programSubjects',
                ])
                ->where('id', $program_id)
                ->where('is_active', true)
                ->first();

            if (!$program || !$program->offeredClass) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Program not found.',
                ], 404);
            }

            $subjectExistsInProgram = $program->programSubjects
                ->where('subject_id', (int) $subject_id)
                ->where('is_active', true)
                ->isNotEmpty();

            if (!$subjectExistsInProgram) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Subject is not available in this program.',
                ], 404);
            }

            $units = BookUnit::with('book:id,book_name')
                ->where('activate', 1)
                ->whereHas('book', function ($query) use ($program, $subject_id) {
                    $query->where('activate', 1)
                        ->where('class_id', $program->offeredClass->class_id)
                        ->where('curriculum_board_id', $program->offeredClass->curriculum_board_id)
                        ->where('subject_id', $subject_id);
                })
                ->orderBy('book_id')
                ->orderBy('unit_no')
                ->get()
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'unit_name' => $unit->unit_name,
                        'unit_no' => $unit->unit_no,
                        'book_id' => $unit->book_id,
                        'book_name' => $unit->book?->book_name,
                        //'is_alp' => (bool) $unit->is_alp,
                    ];
                })
                ->values();

            return response()->json([
                'success' => 1,
                'units' => $units,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getUnitsOfUserSubjectForLMS', [
                'program_id' => $program_id,
                'subject_id' => $subject_id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve units.',
            ], 500);
        }
    }

    public function getUnitsForAdmin()
    {
        try {
            $units = BookUnit::with('book:id,book_name')
                ->orderBy('book_id')
                ->orderBy('unit_no')
                ->get()
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'unit_name' => $unit->unit_name,
                        'unit_no' => $unit->unit_no,
                        'book_id' => $unit->book_id,
                        'book_name' => $unit->book?->book_name,
                        'activate' => (bool) $unit->activate,
                        'is_alp' => (bool) $unit->is_alp,
                    ];
                });

            return response()->json([
                'success' => 1,
                'units' => $units,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getActiveUnitsForAdmin()
    {
        try {
            $units = BookUnit::with('book:id,book_name')
                ->where('activate', 1)
                ->orderBy('book_id')
                ->orderBy('unit_no')
                ->get()
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'unit_name' => $unit->unit_name,
                        'unit_no' => $unit->unit_no,
                        'book_id' => $unit->book_id,
                        'book_name' => $unit->book?->book_name,
                        'is_alp' => (bool) $unit->is_alp,
                    ];
                });

            return response()->json([
                'success' => 1,
                'units' => $units,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveUnitForAdmin(Request $request)
    {
        $request->validate([
            'unit_name' => 'required|string|max:255',
            'unit_no' => 'required|integer|min:1',
            'book_id' => 'required|integer|exists:book_tbl,id',
            'is_alp' => 'nullable|boolean',
        ]);

        try {
            $duplicateUnit = BookUnit::where('book_id', $request->book_id)
                ->where('unit_name', $request->unit_name)
                ->exists();

            if ($duplicateUnit) {
                return response()->json([
                    'success' => 2,
                    'message' => 'Unit name already exists for this book.',
                ]);
            }

            BookUnit::create([
                'unit_name' => $request->unit_name,
                'unit_no' => $request->unit_no,
                'book_id' => $request->book_id,
                'activate' => 1,
                'is_alp' => $request->boolean('is_alp', true),
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Unit saved successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateUnitForAdmin(Request $request, $id)
    {
        $request->validate([
            'unit_name' => 'required|string|max:255',
            'unit_no' => 'required|integer|min:1',
            'book_id' => 'required|integer|exists:book_tbl,id',
            'is_alp' => 'nullable|boolean',
        ]);

        try {
            $unit = BookUnit::findOrFail($id);

            $duplicateUnit = BookUnit::where('book_id', $request->book_id)
                ->where('unit_name', $request->unit_name)
                ->where('id', '!=', $id)
                ->exists();

            if ($duplicateUnit) {
                return response()->json([
                    'success' => 2,
                    'message' => 'Unit name already exists for this book.',
                ]);
            }

            $unit->unit_name = $request->unit_name;
            $unit->unit_no = $request->unit_no;
            $unit->book_id = $request->book_id;
            $unit->is_alp = $request->has('is_alp')
                ? $request->boolean('is_alp')
                : $unit->is_alp;

            if ($request->has('activate')) {
                $unit->activate = $request->activate;
            }

            $unit->save();

            return response()->json([
                'success' => 1,
                'message' => 'Unit updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activateUnitForAdmin(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:book_unit_tbl,id',
            'activate' => 'required|boolean',
        ]);

        try {
            $unit = BookUnit::findOrFail($request->id);
            $unit->activate = $request->activate;
            $unit->save();

            return response()->json([
                'success' => 1,
                'message' => 'Unit status updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

   
}
