<?php

namespace App\Http\Controllers\ModelPapers;

use App\Http\Controllers\Controller;
use App\Http\Services\ModelPaperQuestionService;
use App\Http\Services\ModelPaperService;
use App\Models\ModelPaper;
use App\Models\PaperParts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModelPaperController extends Controller
{
    //
     
      protected $modelPaperService;
      protected $questionService;

    public function __construct(ModelPaperService $modelPaperService, ModelPaperQuestionService $questionService)
    {
       
        $this->modelPaperService = $modelPaperService;
        $this->questionService = $questionService;
    }
    public function saveModelPaper(Request $req){

        $modelPaper = $this->modelPaperService->saveModelPaper($req);
        $modelPaperData = $modelPaper->getData(true);

        if($modelPaperData['success'] == 1){
           return response()->json([
            'success' => 1,
           ]) ;
        }

         return response()->json([
            'success' => 0,
            'message' => $modelPaperData['message'],
           ]) ;
    }

    public function saveModelPaperQuestion(Request $request){
        $question = $this->questionService->saveModelPaperQuestion($request);
        $questionData = $question->getData(true);

        if($questionData['success'] == 1){
           return response()->json([
            'success' => 1,
           ]) ;
        }

         return response()->json([
            'success' => 0,
            'message' => $questionData['message'],
           ]) ;
    }

    public function saveQuestionScheme(Request $request){
        $scheme = $this->questionService->saveQuestionPairingScheme($request);
        $schemeData = $scheme->getData();
        
        return response()->json([
            'success' => $schemeData->success,
            'message' => $schemeData->message,
            'duplicateData' => $schemeData->duplicateData
        ]);
    }

    
     

    public function generateModelPaper(Request $req)
    {
        try{

        
        $subject_id = $req->subject_id;
        $modelPaper = $this->modelPaperService->getModelPaper($req, $subject_id);
        $modelPaperData = $modelPaper->getData(true);
        /*

        $data = $this->userService->getUserClass($req);
        $classData = $data->getData(true);
        $class_id = $classData['data']['class_id'] ?? null;

        $statement = DB::getPDO()->prepare('CALL GenerateModelPapersBySubject(?, ?)');
        $statement->execute([$class_id, $subject_id]);

        $paperParts = $statement->fetchAll(DB::getPDO()::FETCH_ASSOC);

        $statement->nextRowset();
        $dd = $statement->fetchAll(DB::getPDO()::FETCH_ASSOC);

        $statement->closeCursor();
*/

       

        return response()->json([
            'success' => 1,
            'data' => $modelPaperData
            
            
        ], 200);
    }catch (\Illuminate\Database\QueryException $e) {
            // Catch database-specific errors (e.g., the SIGNAL you have in the procedure)
            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            // Catch other general errors
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
        }


    }

    public function getPaperNames(Request $request){
        $paperNames = $this->modelPaperService->getModelPaperNames($request);
        $paperNamesData = $paperNames->getData();

        if($paperNamesData->success){
            return response()->json([
                'success' => 1,
                'data' => $paperNamesData->data,
            ]);
        }
        else{
            return response()->json([
                'success' => 1,
                'message' => $paperNamesData->message,
            ]);
        }

        
    }

    public function getPaperUnits($id){
        try{
            $units = $this->questionService->getUnits($id);
            $unitsData = $units->getData(true);
            if($unitsData['success']){
                return response()->json([
                'success' => 1,
                'data' => $unitsData['data'],
            ]);
            }

             return response()->json([
                'success' => 0,
                'message' => $unitsData->message,
            ]);

        }catch (\Illuminate\Database\QueryException $e) {
            // Catch database-specific errors (e.g., the SIGNAL you have in the procedure)
            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            // Catch other general errors
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
}
