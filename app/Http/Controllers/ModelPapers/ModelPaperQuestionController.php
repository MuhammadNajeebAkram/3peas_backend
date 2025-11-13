<?php

namespace App\Http\Controllers\ModelPapers;

use App\Http\Controllers\Controller;
use App\Http\Services\ModelPaperQuestionService;
use App\Http\Services\ModelPaperService;
use Illuminate\Http\Request;

class ModelPaperQuestionController extends Controller
{
    //
     protected $modelPaperService;
      protected $questionService;

    public function __construct(ModelPaperService $modelPaperService, ModelPaperQuestionService $questionService)
    {
       
        $this->modelPaperService = $modelPaperService;
        $this->questionService = $questionService;
    }

    public function activateModelPaperQuestion(Request $request){
        $status = $request->input('activate');
        $id = $request->id;
        $result = $this->questionService->activateModelPaperQuestion($id, $status);
        $resultData = $result->getData();

        if($resultData->success == 1){
            return response()->json([
                'success' => 1,
            ]);
        }

         return response()->json([
                'success' => 1,
                'message' => $resultData->message,
            ]);

    }

    public function updateModelPaperQuestion(Request $request ){
        $result = $this->questionService->updateModelPaperQuestion($request);
        $resultData = $result->getData();

       
            return response()->json([
                'success' => 1,
                'message' => $resultData->message,
            ]);
        

    }
}
