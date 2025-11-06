<?php
namespace App\Http\Services;

use App\Models\BookUnit;
use App\Models\ModelPaper;
use App\Models\PaperPartSectionQuestion;
use App\Models\PaperQuestionSection;
use App\Models\PaperQuestionSectionSubSection;
use App\Models\QuestionPairingScheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Added for debugging
use Illuminate\Support\Facades\DB;

class ModelPaperQuestionService {

    public function saveModelPaperQuestion(Request $request){

        DB::beginTransaction();

        try{
            $questionData = [
                'question_statement' => $request->questionStatement,
                'question_no' => $request->questionNo,
                'marks' => $request->marks,
                'section_id' => $request->partSection,
                'urdu_lang' => $request->urduLanguage,
                'sequence' => $request->sequence,
                'activate' => 1,
                'is_get_statement' => $request->getStatement,

            ];

             $sectionsData = $request -> sections;

            $question = PaperPartSectionQuestion::create($questionData);
           
            if (!$question) {
            throw new \Exception('Failed to create Model Paper.');
        }

            $questionId = $question->id;

            foreach($sectionsData as $section){
                $sectionData = [
                    'question_id' => $questionId,
                    'section_name' => $section['sectionName'] ?? null,
                    'no_of_sub_sections' => $section['noOfSubSections'] ?? null,
                    'activate' => 1,
                ];

                $saveSection = PaperQuestionSection::create($sectionData);

                if(!$saveSection)
                    throw new \Exception('Failed to create Model Paper.');

                $sectionId = $saveSection->id;

                //$subSectionsData = $section['subSections'];

                foreach($section['subSections'] as $subSection){
                    $subSectionData = [
                        'section_id' => $sectionId,
                        'sub_section_name' => $subSection['subSectionName'] ?? null,
                        'total_questions' => $subSection['noOfQuestions'] ?? null,
                        'question_type_id' => $subSection['selectedQuestionType'] ?? null,
                        'is_random_units' => $subSection['isRandomUnits'] ?? 1,
                        'no_of_random_units' => $subSection['noOfRandomUnits'] ?? 1,
                        'activate' => 1,

                    ];
                    PaperQuestionSectionSubSection::create($subSectionData);
                }

            }
       
            DB::commit();

              return response()->json([
            'success' => 1,
            'message' => 'Question saved successfully.',
        ], 200);

        }catch (\Illuminate\Database\QueryException $e) {
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

    public function saveQuestionPairingScheme(Request $request){
        DB::beginTransaction();
        try{
            $schemes = $request->schemes;
            $pairingDuplicate = [];

            foreach($schemes as $scheme){
                $data = [
                    'sub_section_id' => $scheme['subSectionId'],
                    'unit_id' => $scheme['unitId'],
                    'no_of_questions' => $scheme['noOfQuestions'],                    
                ];
                $pairing = QuestionPairingScheme::where('sub_section_id', '=', $data['sub_section_id'])
                ->where('unit_id', '=', $data['unit_id'])->first();
                if($pairing){
                    $pairingDuplicate[] = $pairing['unit_id'];
                }
                else
                 QuestionPairingScheme::create($data);
            }

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Question Scheme saved successfully',
                'duplicateData' => $pairingDuplicate,
            ]);



        }catch (\Illuminate\Database\QueryException $e) {
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

    public function getUnits($modelPaperId){
    try{
        // 1. Retrieve the Model Paper and select only necessary fields
        $modelPaper = ModelPaper::select('class_id', 'subject_id', 'curriculum_board_id')
                                ->where('id', $modelPaperId)
                                ->first();

        if(!$modelPaper){
            return response()->json([
                'success' => 0,
                'message' => 'No Model paper found for the given ID.'
            ], 404); // Use 404 status code for "Not Found"
        }

        // Extract the IDs for clarity
        $classId = $modelPaper->class_id;
        $subjectId = $modelPaper->subject_id;
        $board_id = $modelPaper->curriculum_board_id;

        // 2. Query BookUnit using whereHas, passing variables via 'use'
        $results = BookUnit::whereHas('book', function ($query) use ($classId, $subjectId, $board_id) {
            $query->where('class_id', $classId)
                  ->where('subject_id', $subjectId)
                  ->where('curriculum_board_id', $board_id);
        })->get(); // <-- The syntax error was here (missing closing parenthesis for subject_id where clause)

        // 3. Success Response
        return response()->json([
            'success' => 1,
            'message' => 'Units retrieved successfully.',
            'data' => $results
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        return response()->json([
            'success' => 0,
            'message' => 'A database error occurred: ' . $e->getMessage()
        ], 500);
    } catch (\Exception $e) {
        return response()->json([
            'success' => 0,
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ], 500);
    }
}
}