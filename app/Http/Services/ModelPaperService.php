<?php

namespace App\Http\Services;

use App\Http\Services\WebUserService;
use App\Models\ModelPaper;
use App\Models\PaperParts;
use App\Models\PaperPartSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Added for debugging
use Illuminate\Support\Facades\DB;

class ModelPaperService{

    protected $userService;

    // Reverting to the standard, cleaner Constructor Injection
    public function __construct(WebUserService $userService){
         $this->userService = $userService;
    }

    public function saveModelPaper(Request $request)
{
    // Start a manual database transaction
    DB::beginTransaction(); // 👈 FIX 1: Use beginTransaction()

    try {
        // Use input() for clarity
        $paperData = [
            'title' => $request->title,
            'total_marks' => $request->totla_marks,
            'time_allowed' => $request->time_allowed,
            'instructions' => $request->instructions,
            'class_id' => $request->class_id,
            'subject_id' => $request->subject_id,
            'activate' => 1,
            'urdu_lang' => $request->urdu_lang,
            'total_questions' => $request->total_questions,


        ];
        $paperPartsData = $request->paperPartsData;

        // 1. Create the main model paper record
        $saveData = ModelPaper::create($paperData);
        
        // Check for ModelPaper creation success
        if (!$saveData) {
            throw new \Exception('Failed to create Model Paper.');
        }

        $modelPaperId = $saveData->id;
        
        // 2. Loop through and create all paper parts
        foreach ($paperPartsData as $parts) {
            $tmpParts = [
                'name'               => $parts['partName'] ?? null,
                'name_um'            => $parts['partNameUM'] ?? null,
                'max_marks'          => $parts['maxMarks'] ?? null,
                'max_marks_um'       => $parts['maxMarks'] ?? null,
                'time_allowed'       => $parts['timeAllowed'] ?? null,
                'time_allowed_um'    => $parts['timeAllowedUM'] ?? null,
                'model_paper_id'     => $modelPaperId, // Use ID from saved model
                'sequence'           => $parts['sequence'] ?? null,
                'no_of_sections'     => $parts['noOfSections'] ?? null,
            ];
            
            $part = PaperParts::create($tmpParts);
            $partId = $part->id;
            foreach($parts['sections'] as $section){
                $tmpSection = [
                    'name' => $section['sectionName'] ?? null,
                    'no_of_questions' => $section['noOfQuestions'] ?? null,
                    'paper_part_id' => $partId,
                    'activate' => 1,
                    'show_name' => $section['showName'] ?? null,
                    'sequence' => $section['sequence'] ?? null,
                    'urdu_lang' => $paperData['urdu_lang'] ?? null,
                ];

                PaperPartSection::create($tmpSection);

            }
        }

        // Commit the transaction only if all steps succeeded
        DB::commit();

        return response()->json([
            'success' => 1,
            'message' => 'Model Paper and parts saved successfully.',
        ], 200);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        
        return response()->json([
            'success' => 0, // Changed to 0 for consistency with "false"
            'message' => 'A database error occurred while saving: ' . $e->getMessage()
        ], 500);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'success' => 0, // Changed to 0
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ], 500);
    }
}

public function getModelPaperNames(Request $request){
    try{
        $paperNames = null;
         $status = $request->input('status') ?? 1;
          $children = $request->input('children') ?? 0;
         $relationsToLoad = $children == 0 ? [null] : 
         ($children == 1 ? ['paperParts'] : ($children == 2 ?
          ['paperParts.partSections'] : ($children == 3 ? ['paperParts.partSections.questions'] :
        ($children == 4 ? ['paperParts.partSections.questions.sections'] : ['paperParts.partSections.questions.sections.subSections']))));    

        $status = $request->input('status') ?? 1;
        if($status == 2){
            $paperNames = ModelPaper::select('id', 'title', 'urdu_lang')->with( $relationsToLoad)->get();

        }
        else{
             $paperNames = ModelPaper::select('id', 'title')
        ->where('activate', '=', $status)->with( $relationsToLoad)->get();

        }
       

         return response()->json([
            'success' => 1,
            'message' => 'Model paper names retrieved successfully.',
            'data' => $paperNames
        ], 200);

    }catch (\Illuminate\Database\QueryException $e) {
       
        
        return response()->json([
            'success' => 0, // Changed to 0 for consistency with "false"
            'message' => 'A database error occurred while saving: ' . $e->getMessage()
        ], 500);
        
    } catch (\Exception $e) {
        
        
        return response()->json([
            'success' => 0, // Changed to 0
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ], 500);
    }
}

    public function getModelPaper(Request $req, $subjectId){
        
        $data = $this->userService->getUserClass($req);
        $classData = $data->getData(true);
        $class_id = $classData['data']['class_id'] ?? null;

        // --- Debug Check 1: Class ID ---
        if (!$class_id) {
            Log::warning('ModelPaperService: Class ID not found for user in getModelPaper.');
            return response()->json([
                'success' => 0,
                'message' => 'User class information could not be retrieved.',
                'data' => []
            ], 400);
        }

        // 2. Fetch the ModelPaper, eager loading the parts
        $paper = ModelPaper::where('subject_id', $subjectId)
                           ->where('class_id', $class_id)
                           ->with('paperParts')
                           ->with('paperClass')
                           ->with('paperSubject')
                           ->get(); 
        
        // --- Debug Check 2: Paper Found ---
        if ($paper->isEmpty()) {
             Log::info("ModelPaperService: No ModelPaper found for subject {$subjectId} and class {$class_id}.");
             return response()->json([
                'success' => 0,
                'message' => 'No model paper found for the specified subject and class.',
                'data' => []
             ], 404);
        }

       

       
        
        // 3. Success response
        return response()->json([
            'success' => 1,
            // Returning the collection directly. Laravel converts this to a JSON array.
            'data' => $paper 
        ], 200);
    }
    

    public function getQuestions(Request $req, $subjectId){
        // Method body remains empty
    }

}