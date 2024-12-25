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
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsCategoryController;
use App\Http\Controllers\BlogsController;
use App\Http\Controllers\BlogsCategoryController;

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
Route::POST('/get_books_by_class_subject', [BooksController::class, 'getBooksByClassAndSubject']);
Route::post('/add_books', [BooksController::class, 'saveBook']);
Route::post('/update_books', [BooksController::class, 'editBook']);
Route::post('/activate_book', [BooksController::class, 'activateBook']);

Route::get('/get_all_units', [UnitsController::class, 'getAllUnits']);
Route::get('/get_units_of_book/{book_id}', [UnitsController::class, 'getUnitsOfBook']);
Route::POST('/get_units_by_book', [UnitsController::class, 'getUnitsByBook']);
Route::post('/add_units', [UnitsController::class, 'saveUnit']);
Route::post('/update_units', [UnitsController::class, 'editUnit']);
Route::post('/activate_unit', [UnitsController::class, 'activateUnit']);

Route::get('/get_all_topics', [TopicsController::class, 'getAllTopics']);
Route::POST('/get_topics_by_unit', [TopicsController::class, 'getTopicsByUnit']);
Route::post('/add_topics', [TopicsController::class, 'saveTopic']);
Route::post('/update_topics', [TopicsController::class, 'editTopic']);
Route::post('/activate_topic', [TopicsController::class, 'activateTopic']);

Route::get('/get_all_question_types', [QuestionTypesController::class, 'getAllTypes']);
Route::get('/get_activate_question_types', [QuestionTypesController::class, 'getActivateQuestionTypes']);
Route::post('/add_question_types', [QuestionTypesController::class, 'saveType']);
Route::post('/update_question_type', [QuestionTypesController::class, 'editType']);
Route::post('/activate_question_type', [QuestionTypesController::class, 'activateType']);

Route::get('/get_all_questions', [QuestionsController::class, 'getAllQuestions']);
Route::post('/get_questions_by_topic', [QuestionsController::class, 'getQuestionsByTopic']);
Route::post('/get_questions_by_unit', [QuestionsController::class, 'getQuestionsByUnit']);
Route::post('/get_questions_by_book', [QuestionsController::class, 'getQuestionsByBook']);
Route::post('/get_questions_by_board', [QuestionsController::class, 'getQuestionsByBoard']);
Route::post('/get_question_data_by_id', [QuestionsController::class, 'getQuestionDataById']);
Route::post('/save_question', [QuestionsController::class, 'saveQuestion']);
Route::post('/save_repeat_question', [QuestionsController::class, 'repeatQuestion']);
Route::post('/update_question', [QuestionsController::class, 'updateQuestion']);

Route::get('/get_all_news', [NewsController::class, 'getAllNews']);
Route::get('/get_all_active_news_title', [NewsController::class, 'getActiveNewsTitle']);
Route::get('/get_top_active_news_title', [NewsController::class, 'getActiveTopNewsTitle']);
Route::get('/get_news_content/{id}', [NewsController::class, 'getNewsContentById']);
Route::get('/get_news_content_by_slug/{slug}', [NewsController::class, 'getNewsContentBySlug']);
Route::post('/add_news', [NewsController::class, 'saveNews']);
Route::post('/update_news', [NewsController::class, 'editNews']);
Route::post('/activate_news', [NewsController::class, 'activateNews']);
Route::get('/get_all_news_archive', [NewsController::class, 'getActiveAllNewsArchive']);

Route::get('/get_all_news_categories', [NewsCategoryController::class, 'getAllNewsCategory']);
Route::post('/add_news_categories', [NewsCategoryController::class, 'saveCategory']);
Route::post('/update_news_categories', [NewsCategoryController::class, 'editCategory']);
Route::post('/activate_news_category', [NewsCategoryController::class, 'activateCategory']);
Route::get('/get_news_categories', [NewsCategoryController::class, 'getActiveNewsCategory']);

Route::get('/get_all_blogs', [BlogsController::class, 'getAllBlogs']);
Route::get('/get_top_active_blog_title', [BlogsController::class, 'getActiveTopBlogTitle']);
Route::get('/get_blogs_content_by_slug/{slug}', [BlogsController::class, 'getBlogsContentBySlug']);
Route::post('/add_blogs', [BlogsController::class, 'saveBlogs']);
Route::post('/update_blogs', [BlogsController::class, 'editBlogs']);
Route::post('/activate_blogs', [BlogsController::class, 'activateBlogs']);
Route::get('/get_all_blogs_archive', [BlogsController::class, 'getActiveAllBlogsArchive']);

Route::get('/get_all_blogs_categories', [BlogsCategoryController::class, 'getAllBlogsCategory']);
Route::post('/add_blogs_categories', [BlogsCategoryController::class, 'saveCategory']);
Route::post('/update_blogs_categories', [BlogsCategoryController::class, 'editCategory']);
Route::post('/activate_blogs_category', [BlogsCategoryController::class, 'activateCategory']);
Route::get('/get_blogs_categories', [BlogsCategoryController::class, 'getActiveBlogsCategory']);

