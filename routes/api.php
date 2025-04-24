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
use App\Http\Controllers\CognitiveDomainController;
use App\Http\Middleware\RefreshAuthTokenMiddleware;
use App\Http\Controllers\CurriculumBoardController;
use App\Http\Controllers\StudyGroupController;
use App\Http\Controllers\StudyPlanController;
use App\Http\Controllers\StudySessionController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\TopicContentController;




/*
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
*/


Route::post('login', [AuthController::class, 'login']);

Route::middleware(['auth:api'])->group(function () {

   
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('update-password', [AuthController::class, 'updatePassword']);
    Route::post('register', [AuthController::class, 'register']);

    Route::post('/add_class/{name}', [ClassesController::class, 'saveClass']);
    Route::post('/update_class', [ClassesController::class, 'editClass']);
    Route::post('/activate_class', [ClassesController::class, 'activateClass']);

    Route::get('/get_subjects', [SubjectsController::class, 'getSubjects']);
    Route::get('/get_all_subjects', [SubjectsController::class, 'getAllSubjects']);
    Route::post('/add_subject', [SubjectsController::class, 'saveSubject']);
    Route::post('/update_subject', [SubjectsController::class, 'editSubject']);
    Route::post('/activate_subject', [SubjectsController::class, 'activateSubject']);

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

Route::post('/save-new-topic', [TopicsController::class, 'saveNewTopic']);
Route::get('/get-new-topics-by-unit/{unit_id}', [TopicsController::class, 'getNewTopicsByUnit']);
Route::post('/update-new-topic', [TopicsController::class, 'updateNewTopic']);

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
Route::post('/repeat_question', [QuestionsController::class, 'repeatQuestion']);
Route::post('/activate_question', [QuestionsController::class, 'activateQuestion']);
Route::post('/get_board_questions_by_id', [QuestionsController::class, 'getBoardQuestionsByQuestion']);
Route::post('/activate_board_question', [QuestionsController::class, 'activateBoardQuestion']);
Route::post('/update_board_question', [QuestionsController::class, 'updateBoardQuestion']);


Route::post('/add_news', [NewsController::class, 'saveNews']);
Route::post('/update_news', [NewsController::class, 'editNews']);
Route::post('/activate_news', [NewsController::class, 'activateNews']);
Route::get('/get_all_news_archive', [NewsController::class, 'getActiveAllNewsArchive']);

Route::get('/get_all_news_categories', [NewsCategoryController::class, 'getAllNewsCategory']);
Route::post('/add_news_categories', [NewsCategoryController::class, 'saveCategory']);
Route::post('/update_news_categories', [NewsCategoryController::class, 'editCategory']);
Route::post('/activate_news_category', [NewsCategoryController::class, 'activateCategory']);
Route::get('/get_news_categories', [NewsCategoryController::class, 'getActiveNewsCategory']);


Route::post('/add_blogs', [BlogsController::class, 'saveBlogs']);
Route::post('/update_blogs', [BlogsController::class, 'editBlogs']);
Route::post('/activate_blogs', [BlogsController::class, 'activateBlogs']);
Route::get('/get_all_blogs_archive', [BlogsController::class, 'getActiveAllBlogsArchive']);

Route::get('/get_all_blogs_categories', [BlogsCategoryController::class, 'getAllBlogsCategory']);
Route::post('/add_blogs_categories', [BlogsCategoryController::class, 'saveCategory']);
Route::post('/update_blogs_categories', [BlogsCategoryController::class, 'editCategory']);
Route::post('/activate_blogs_category', [BlogsCategoryController::class, 'activateCategory']);
Route::get('/get_blogs_categories', [BlogsCategoryController::class, 'getActiveBlogsCategory']);

Route::post('/get_curriculum', [CurriculumBoardController::class, 'getCurriculumBoard']);

Route::post('/get_all_study_subjects', [StudyGroupController::class, 'getAllStudySubjects']);

Route::post('/save_offered_subjects', [StudyGroupController::class, 'saveOfferedSubjects']);
Route::post('/save_offered_subjects_group', [StudyGroupController::class, 'saveOfferedGroups']);

Route::post('/search_offered_subjects', [StudyGroupController::class, 'getSelectedOfferedSubjects']);
Route::post('/search_offered_groups', [StudyGroupController::class, 'getSelectedOfferedGroups']);

Route::post('/get-all-study-groups', [StudyGroupController::class, 'getAllStudyGroups']);

Route::post('/get_offered_subjects_by_class_curriculum', [StudyGroupController::class, 'getOfferedStudySubjectsByClassAndCurriculum']);

Route::post('/get_curriculum', [CurriculumBoardController::class, 'getCurriculumBoard']);

Route::post('/get-awaited-web-users', [UserProfileController::class, 'getAwaitedUsers']);
Route::post('/activate-awaited-web-users', [UserProfileController::class, 'activateAwaitedUser']);

Route::get('/get-all-study-plans', [StudyPlanController::class, 'getAllStudyPlans']);
Route::post('/save-study-plan', [StudyPlanController::class, 'saveStudyPlan']);
Route::post('/update-study-plan', [StudyPlanController::class, 'updateStudyPlan']);

Route::post('/save-study-session', [StudySessionController::class, 'saveSession']);
Route::get('/get-study-sessions-by-class-board/{class_id}/{curriculum_id}', [StudySessionController::class, 'getSessionsByClassAndBoard']);

Route::post('/save-topic-content', [TopicContentController::class, 'saveContent']);
Route::get('/get-all-topic-contents', [TopicContentController::class, 'getAllContents']);
Route::get('/get-topic-contents-by-topic/{topic_id}', [TopicContentController::class, 'getContentsByTopic']);

Route::get('/get-cognitive-domain', [CognitiveDomainController::class, 'getDomain']);
    
});

Route::get('/classes', [ClassesController::class, 'getClasses']);
Route::post('/classes', [ClassesController::class, 'getClasses']);

Route::get('/subjects/{id}', [SubjectsController::class, 'getSubjectsByClass']);



Route::get('/boards/{id}/{subject_id}', [BoardsController::class, 'getBoardsByClass_Subjects']);




Route::get('/years/{id}/{subject_id}/{board_id}', [YearsController::class, 'getYearsByBoards_Class_Subjects']);

Route::get('/papers/{id}/{subject_id}/{board_id}/{year}', [PapersController::class, 'getPapersByYears_Boards_Class_Subjects']);


Route::get('/get_all_news', [NewsController::class, 'getAllNews']);
Route::get('/get_all_active_news_title', [NewsController::class, 'getActiveNewsTitle']);
Route::get('/get_top_active_news_title', [NewsController::class, 'getActiveTopNewsTitle']);
Route::get('/get_news_content/{id}', [NewsController::class, 'getNewsContentById']);
Route::get('/get_news_content_by_slug/{slug}', [NewsController::class, 'getNewsContentBySlug']);

Route::get('/get_all_blogs', [BlogsController::class, 'getAllBlogs']);
Route::get('/get_top_active_blog_title', [BlogsController::class, 'getActiveTopBlogTitle']);
Route::get('/get_blogs_content_by_slug/{slug}', [BlogsController::class, 'getBlogsContentBySlug']);


Route::get('/get_test', [QuestionsController::class, 'getTest']);
Route::post('/save_test', [QuestionsController::class, 'saveTest']);
