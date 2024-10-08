<?php
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\BoardsController;
use App\Http\Controllers\YearsController;
use App\Http\Controllers\PapersController;
use App\Http\Controllers\ExamSessionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/classes', [ClassesController::class, 'getClasses']);
Route::post('/add_class/{name}', [ClassesController::class, 'saveClass']);
Route::post('/update_class', [ClassesController::class, 'editClass']);
Route::post('/activate_class', [ClassesController::class, 'activateClass']);

Route::get('/subjects/{id}', [SubjectsController::class, 'getSubjectsByClass']);
Route::get('/get_subjects', [SubjectsController::class, 'getSubjects']);
Route::get('/get_all_subjects', [SubjectsController::class, 'getAllSubjects']);
Route::post('/add_subject', [SubjectsController::class, 'saveSubject']);
Route::post('/update_subject', [SubjectsController::class, 'editSubject']);
Route::post('/activate_subject', [SubjectsController::class, 'activateSubject']);


Route::get('/boards/{id}/{subject_id}', [BoardsController::class, 'getBoardsByClass_Subjects']);
Route::get('/get_boards', [BoardsController::class, 'getBoards']);
Route::get('/get_all_boards', [BoardsController::class, 'getAllBoards']);
Route::post('/add_board', [BoardsController::class, 'saveBoard']);
Route::post('/update_board', [BoardsController::class, 'editBoard']);
Route::post('/activate_board', [BoardsController::class, 'activateBoard']);

Route::get('/exam_sessions', [ExamSessionController::class, 'getAllExamSessions']);
Route::get('/get_exam_sessions', [ExamSessionController::class, 'getExamSessions']);
Route::post('/add_exam_session', [ExamSessionController::class, 'saveSession']);
Route::post('/update_exam_session', [ExamSessionController::class, 'editSession']);
Route::post('/activate_exam_session', [ExamSessionController::class, 'activateSession']);

Route::get('/years/{id}/{subject_id}/{board_id}', [YearsController::class, 'getYearsByBoards_Class_Subjects']);

Route::get('/papers/{id}/{subject_id}/{board_id}/{year}', [PapersController::class, 'getPapersByYears_Boards_Class_Subjects']);
Route::get('/get_papers', [PapersController::class, 'getPastPapers']);
Route::post('/add_papers', [PapersController::class, 'savePastPapers']);
Route::post('/update_papers', [PapersController::class, 'updatePastPapers']);

