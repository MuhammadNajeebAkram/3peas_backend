<?php
namespace App\Http\Services;

use App\Models\BookUnit;
use App\Models\ModelPaper;
use App\Models\PaperPartSectionQuestion;
use App\Models\PaperQuestionSection;
use App\Models\PaperQuestionSectionSubSection;
use App\Models\QuestionPairingScheme;
use App\Http\Services\WebUserService;
use App\Models\BookUnitTopic;
use App\Models\ExamQuestion;
use App\Models\QuestionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Added for debugging
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class ModelPaperQuestionService {
    protected $userService;

    // Reverting to the standard, cleaner Constructor Injection
    public function __construct(WebUserService $userService){
         $this->userService = $userService;
    }

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
                    'is_random_question_type' => $section['isRandomQuestionType'],
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
                        'show_name' => $subSection['showName'] ?? 0,

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

    public function updateModelPaperQuestion(Request $request)
{
    DB::beginTransaction();

    try {
        $questionId = $request->id;

        $question = PaperPartSectionQuestion::find($questionId);
        if (!$question) {
            throw new \Exception('Question not found.');
        }

        $questionData = [
            'question_statement' => $request->questionStatement,
            'question_no' => $request->questionNo,
            'marks' => $request->marks,
            'section_id' => $request->partSection,
            'urdu_lang' => $request->urduLanguage,
            'sequence' => $request->sequence,
            'is_get_statement' => $request->getStatement,
        ];

        $question->update($questionData);

        $sectionsData = $request->sections ?? [];

        foreach ($sectionsData as $section) {
            $sectionId = $section['id'] ?? 0;

            $sectionData = [
                'question_id' => $questionId,
                'section_name' => $section['sectionName'] ?? null,
                'no_of_sub_sections' => $section['noOfSubSections'] ?? null,
                'is_random_question_type' => $section['isRandomQuestionType'] ?? 0,
                'activate' => 1,
            ];

            if ($sectionId == 0) {
                $savedSection = PaperQuestionSection::create($sectionData);
                $sectionId = $savedSection->id;
            } else {
                $updatedSection = PaperQuestionSection::find($sectionId);
                if (!$updatedSection) {
                    throw new \Exception("Section with ID {$sectionId} not found.");
                }
                $updatedSection->update($sectionData);
            }

            // --- Sub-sections ---
            foreach ($section['subSections'] ?? [] as $subSection) {
                $subSectionId = $subSection['id'] ?? 0;

                $subSectionData = [
                    'section_id' => $sectionId,
                    'sub_section_name' => $subSection['subSectionName'] ?? null,
                    'total_questions' => $subSection['noOfQuestions'] ?? null,
                    'question_type_id' => $subSection['selectedQuestionType'] ?? null,
                    'is_random_units' => $subSection['isRandomUnits'] ?? 1,
                    'no_of_random_units' => $subSection['noOfRandomUnits'] ?? 1,
                    'activate' => 1,
                    'show_name' => $subSection['showName'] ?? 0,
                ];

                if ($subSectionId == 0) {
                    PaperQuestionSectionSubSection::create($subSectionData);
                } else {
                    $existingSub = PaperQuestionSectionSubSection::find($subSectionId);
                    if (!$existingSub) {
                        throw new \Exception("Sub-section with ID {$subSectionId} not found.");
                    }
                    $existingSub->update($subSectionData);
                }
            }
        }

        DB::commit();

        return response()->json([
            'success' => 1,
            'message' => 'Question updated successfully.',
        ], 200);

    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        Log::error('Database error in updateModelPaperQuestion', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => 0,
            'message' => 'A database error occurred while saving: ' . $e->getMessage()
        ], 500);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('General exception in updateModelPaperQuestion', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => 0,
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
                  ->where('curriculum_board_id', $board_id)
                  ->where('activate', 1);
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

public function getPaperQuestions(Request $request, $subject_id){
    try{
        $data = $this->userService->getUserClass($request);
        $classData = $data->getData(true);
        $class_id = $classData['data']['class_id'] ?? null;

       

        // --- Debug Check 1: Class ID ---
        if (!$class_id) {
            Log::warning('ModelPaperQuestionService: Class ID not found for user in getModelPaper.');
            return response()->json([
                'success' => 0,
                'message' => 'User class information could not be retrieved.',
                'data' => []
            ], 400);
        }

        $questions = ModelPaper::where('subject_id', '=', $subject_id)
        ->where('class_id', '=', $class_id)
        ->with(['paperParts.partSections.questions', 'paperClass', 'paperSubject'])
        ->get();

        return response()->json([
            'success' => 1,
            'data' => $questions,
        ]);

    }catch (\Illuminate\Database\QueryException $e) {
        Log::error('\Illuminate\Database\QueryException: ', $e);
        return response()->json([
            'success' => 0,
            'message' => 'A database error occurred: ' . $e->getMessage()
        ], 500);
    } catch (\Exception $e) {
        Log::error('Exception: ', $e);
        return response()->json([
            'success' => 0,
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ], 500);
    }


}
public function getPaperSubQuestions($question_id){
    try{
        $question = PaperPartSectionQuestion::where('id', '=', $question_id)
        ->with('sections.subSections.pairingSchemes')->first();

        if (!$question) {
            return response()->json([
                'success' => 0,
                'message' => 'Question with ID ' . $question_id . ' not found.',
                
            ], 404);
        }

        $sections = $question['sections']?? collect();
        $questions = [];
        $SubSections = [];

        foreach($sections as $section){
            $subSections = $section['subSections'] ?? collect();
            $SubSections[] = $subSections;
            foreach($subSections as $subSection){
                $pairingSchemes = $subSection['pairingSchemes'] ?? collect();
                $randomUnits = (int) $subSection['no_of_random_units'];
                $isRandom = (bool) ($subSection['is_random_units'] ?? false);
                $questionType = $subSection['question_type_id'];
                $totalQuestions = $subSection['total_questions'];
                $relationToLoad = $questionType == 1 ? 'answerOptions' : 'answers';
                
                if ($isRandom && $randomUnits > 0 && $randomUnits < $pairingSchemes->count()) {
                    $selectedSchemes = Arr::random($pairingSchemes->all(), $randomUnits);
                } else {
                    // Otherwise take all pairing schemes
                    $selectedSchemes = $pairingSchemes;
                }

                $allSubSectionQuestions = collect();
                
                foreach($selectedSchemes as $scheme){
                    $noOfSelectedQuestion = $scheme['no_of_questions'];
                    $topicIds = BookUnitTopic::where('unit_id', $scheme['unit_id'])->pluck('id');

                    $query = ExamQuestion::whereIn('topic_id', $topicIds)
                    ->where('question_type', $questionType)->with($relationToLoad)
                    ->inRandomOrder()->limit($noOfSelectedQuestion)->get();

                    $allSubSectionQuestions = $allSubSectionQuestions->merge($query);

                    

                }

                $structuredData[] = [
                    'sub_section_id' => $subSection['id'],
                    'sub_section_name' => $subSection['sub_section_name'] ?? 'Unnamed Subsection',
                    'question_type_id' => $questionType,
                    'questions' => $allSubSectionQuestions,
                ];

            }
        }
       

        return response()->json([
            'success' => 1,
            'data' => $questions,
        ]);

    }catch (\Illuminate\Database\QueryException $e) {
        Log::error('\Illuminate\Database\QueryException: ', [
            'exception' => $e->getMessage(),
            'code' => $e->getCode(),
            'trace' => $e->getTraceAsString() // For full details
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'A database error occurred: ' . $e->getMessage()
        ], 500);
    } catch (\Exception $e) {
        Log::error('Exception: ', [
            'exception' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ], 500);
    }

}

public function getFullPaperStructure(Request $request, $subject_id)
{
    try {
        // --- Get user class info ---
        $data = $this->userService->getUserClass($request);
        $classData = $data->getData(true);
        $class_id = $classData['data']['class_id'] ?? null;

        if (!$class_id) {
            Log::warning('Class ID not found for user.');
            return response()->json([
                'success' => 0,
                'message' => 'User class information could not be retrieved.',
                'data' => []
            ], 400);
        }

        // --- Get Model Paper ---
        $modelPapers = ModelPaper::where('subject_id', $subject_id)
    ->where('class_id', $class_id)
    ->with([
        'paperParts.partSections' => function ($query) {
            $query->with([
                'questions' => function ($q) {
                    $q->where('activate', 1);
                    $q->orderBy('sequence', 'asc');
                },
                'questions.sections.subSections.pairingSchemes',
            ]);
        },
        'paperClass',
        'paperSubject'
    ])
    ->get();

        if ($modelPapers->isEmpty()) {
            return response()->json([
                'success' => 0,
                'message' => 'No model paper found for this subject/class.',
            ], 404);
        }
        
$randomQTypes = [];
        // --- Build complete hierarchy including random questions ---
        foreach ($modelPapers as $paper) {
            foreach ($paper->paperParts as $part) {
                foreach ($part->partSections as $section) {
                    foreach ($section->questions as $question) {
                       
                       // Log::info('Checking statement flag', ['value' => $question->is_get_statement]);
                        $allStatements = [];
                         

                        
                        foreach ($question->sections as $subSectionGroup) {
                             $randomQuestionType = $subSectionGroup->is_random_question_type;
                            if($randomQuestionType){
                                 $randomQTypes = PaperQuestionSectionSubSection::where('section_id', $subSectionGroup->id)
                                ->pluck('question_type_id')
                                ->unique()
                                ->toArray();

                                shuffle($randomQTypes);
                            }
                            $i = 0;
                           
                            

                            foreach ($subSectionGroup->subSections as $subSection) {
                                $pairingSchemes = $subSection['pairingSchemes'] ?? collect();
                                $randomUnits = (int) $subSection['no_of_random_units'];
                                $isRandom = (bool) ($subSection['is_random_units'] ?? false);
                                $questionType = $randomQuestionType
    ? ($randomQTypes[$i % count($randomQTypes)] ?? $subSection['question_type_id'])
    : $subSection['question_type_id'];
                                $i++;
                                $qTypeIsMCQ = QuestionType::where('id', $questionType)->first();
                                $relationToLoad = $qTypeIsMCQ->is_mcq == 1 ? 'answerOptions' : 'answers';

                                // Select pairing schemes (random or all)
                                if ($isRandom && $randomUnits > 0 && $randomUnits < $pairingSchemes->count()) {
                                    $selectedSchemes = Arr::random($pairingSchemes->all(), $randomUnits);
                                } else {
                                    $selectedSchemes = $pairingSchemes;
                                }

                                $allSubSectionQuestions = collect();

                                foreach ($selectedSchemes as $scheme) {
                                    $noOfSelectedQuestion = $scheme['no_of_questions'];
                                    $topicIds = BookUnitTopic::where('unit_id', $scheme['unit_id'])->pluck('id');

                                    $query = ExamQuestion::whereIn('topic_id', $topicIds)
                                        ->where('question_type', $questionType)
                                        ->with($relationToLoad)
                                        ->inRandomOrder()
                                        ->limit($noOfSelectedQuestion)
                                        ->get();
                                        
                                       

                                    $allSubSectionQuestions = $allSubSectionQuestions->merge($query);
                                    //Log::info('Checking statement flag', ['value' => $question->is_get_statement, 'id' => $question->id]);
                                    

                                    if ($question->is_get_statement == 1 && $query->isNotEmpty()) {
        $statements = $query->pluck('question')->filter()->toArray();
        $allStatements = array_merge($allStatements, $statements);
    }
                                }
                                if ($question->is_get_statement == 1 && !empty($allStatements)) {
    if ($question->urdu_lang == 1) {
        // Urdu version
        $question->question_statement = implode(' یا<br>', $allStatements);
    } else {
        // English version
        $question->question_statement = implode(' or<br>', $allStatements);
    }
}

                                // Attach generated questions to subSection dynamically
                                $subSection->allSubSectionQuestions = $allSubSectionQuestions;
                            }
                        }
                    }
                }
            }
        }

        return response()->json([
            'success' => 1,
            'data' => $modelPapers,
           
        ]);

    } catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getFullPaperStructure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getFullPaperStructure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
}

public function getQuestionsForUpdate($partSectionId){
    try{
        $questions = PaperPartSectionQuestion::where('section_id', '=', $partSectionId)
        ->with('sections.subSections')
        ->get();

        return response()->json([
            'success' => 1,
            'data' => $questions,
        ]);

    }catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in getQuestionsForUpdate', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in getQuestionsForUpdate', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }

}

public function activateModelPaperQuestion($id, $status)
{
    try {
        $question = PaperPartSectionQuestion::find($id);

        if (!$question) {
            return response()->json([
                'success' => 0,
                'message' => 'Question not found.',
            ], 404);
        }

        // ✅ Corrected syntax: key => value
        $question->update(['activate' => $status]);

        return response()->json([
            'success' => 1,
            'message' => 'Question activation status updated successfully.',
            'data' => $question,
        ], 200);

    } catch (\Illuminate\Database\QueryException $e) {
        Log::error('Database error in activateModelPaperQuestion', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => 0,
            'message' => 'Database error: ' . $e->getMessage(),
        ], 500);

    } catch (\Exception $e) {
        Log::error('General exception in activateModelPaperQuestion', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
}

}