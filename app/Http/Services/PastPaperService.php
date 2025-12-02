<?php
namespace App\Http\Services;

use App\Models\ExamBoard;
use App\Models\PastPaper;
use App\Models\Subject;
use App\Models\UserClass;
use Illuminate\Support\Facades\Log;

class PastPaperService {
    
    public function getBoardData(){

        try{
           $subjects = Subject::where('activate', 1)->select('id', 'subject_name')->get();
        $classes = UserClass::where('activate', 1)->select('id', 'class_name')->get();
        $boards = ExamBoard::where('activate', 1)->select('id', 'board_name')->get();

            return response()->json([
                'success' => 1,
                'data' => [
                    'subjects' => $subjects,
                'classes' => $classes,
                'boards' => $boards,
                ]
                ]);

        }catch (\Exception $e) {
        Log::error('General exception in getBoardData', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }

}
public function searchResult($request){
    try{
        $subject = $request->input('subject');
        $class_id = $request->input('class_id');
        $board = $request->input('board');
        $year = $request->input('year');

        $query = PastPaper::query()->where('activate', 1);
        $query->when($subject, fn($q) => $q->where('subject_id', $subject));
        $query->when($class_id, fn($q) => $q->where('class_id', $class_id));
        $query->when($board, fn($q) => $q->where('board_id', $board));
        $query->when($year, fn($q) => $q->where('year', $year));              
        $result = $query->select('paper_name', 'paper_slug')
        ->groupBy('paper_name', 'paper_slug')
        ->get();

        return response()->json([
            'success' => 1,
            'data' => $result
        ]);

    }catch (\Exception $e) {
        Log::error('General exception in searchResult of PastPaperService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
}

public function getPastPaperBySlug($slug){
    try{
        $paper = PastPaper::where('paper_slug', $slug)->get();

        return response()->json([
            'success' => 1,
            'data' => $paper
        ]);

    }catch (\Exception $e) {
        Log::error('General exception in getPastPaperBySlug of PastPaperService', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => 0,
            'message' => 'Unexpected error: ' . $e->getMessage(),
        ], 500);
    }
}

public function getAllSlugs(){
        try{

            $slugs = PastPaper::query()
        ->select('paper_slug')
        ->where('activate', 1)
        ->groupBy('paper_slug')
        ->get();

        $formattedSlugs = $slugs->map(function ($paper) {
            return ['slug' => $paper->paper_slug];
        });

        return response()->json([
            'success' => 1,
            'data' => $formattedSlugs,
        ]);
        }catch (\Exception $e) {
        Log::error('Error fetching all paper slugs for sitemap:', ['error' => $e->getMessage()]);
        return response()->json(['success' => 0, 'message' => $e->getMessage()], 500);
    }
        
    }

}

