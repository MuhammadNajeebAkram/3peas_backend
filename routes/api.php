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
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\TopicsController;
use App\Http\Controllers\QuestionTypesController;
use App\Http\Controllers\QuestionsController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);


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

Route::get('/get_all_books', [BooksController::class, 'getAllBooks']);
Route::get('/get_books', [BooksController::class, 'getBooks']);
Route::post('/add_books', [BooksController::class, 'saveBook']);
Route::post('/update_books', [BooksController::class, 'editBook']);
Route::post('/activate_book', [BooksController::class, 'activateBook']);

Route::get('/get_all_units', [UnitsController::class, 'getAllUnits']);
Route::get('/get_units_of_book/{book_id}', [UnitsController::class, 'getUnitsOfBook']);
Route::post('/add_units', [UnitsController::class, 'saveUnit']);
Route::post('/update_units', [UnitsController::class, 'editUnit']);
Route::post('/activate_unit', [UnitsController::class, 'activateUnit']);

Route::get('/get_all_topics', [TopicsController::class, 'getAllTopics']);
Route::post('/add_topics', [TopicsController::class, 'saveTopic']);
Route::post('/update_topics', [TopicsController::class, 'editTopic']);
Route::post('/activate_topic', [TopicsController::class, 'activateTopic']);

Route::get('/get_all_question_types', [QuestionTypesController::class, 'getAllTypes']);
Route::post('/add_question_types', [QuestionTypesController::class, 'saveType']);
Route::post('/update_question_type', [QuestionTypesController::class, 'editType']);
Route::post('/activate_question_type', [QuestionTypesController::class, 'activateType']);

Route::get('/get_all_questions', [QuestionsController::class, 'getAllQuestions']);

