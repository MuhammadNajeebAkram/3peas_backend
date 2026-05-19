<?php
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminAuth\AuthController as AdminAuthController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\BoardsController;
use App\Http\Controllers\YearsController;
use App\Http\Controllers\PapersController;
use App\Http\Controllers\ExamSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Authentication\WebUserAuthController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\TopicsController;
use App\Http\Controllers\QuestionTypesController;
use App\Http\Controllers\QuestionsController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsCategoryController;
use App\Http\Controllers\NewsTickerController;
use App\Http\Controllers\BlogsController;
use App\Http\Controllers\BlogsCategoryController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CognitiveDomainController;
use App\Http\Middleware\RefreshAuthTokenMiddleware;
use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use App\Http\Controllers\CurriculumBoardController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\HeardAboutController;
use App\Http\Controllers\InstituteController;
use App\Http\Controllers\ModelPapers\ModelPaperController;
use App\Http\Controllers\ModelPapers\ModelPaperQuestionController;
use App\Http\Controllers\OfferedClassesController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\StudyGroupController;
use App\Http\Controllers\StudyPlanController;
use App\Http\Controllers\StudySessionController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\TopicContentController;
use App\Http\Controllers\OfferedProgramController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Middleware\CheckFrontendApiKey;

/*
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
*/


Route::post('login', [AdminAuthController::class, 'login']);

// Admin authentication routes use the `api` guard and a dedicated JWT cookie.
Route::prefix('admin/auth')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);

    Route::middleware([AttachJwtFromCookie::class . ':admin', AuthenticateJwtCookieGuard::class . ':admin'])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::post('media/presign', [NewsController::class, 'presignMediaUpload'])->middleware('permission:admin.media.presign');

        Route::prefix('roles')->group(function () {
            Route::get('/all', [RolePermissionController::class, 'getRoles'])->middleware('permission:roles.view');
            Route::post('/add', [RolePermissionController::class, 'saveRole'])->middleware('permission:roles.create');
            Route::post('/update/{id}', [RolePermissionController::class, 'updateRole'])->middleware('permission:roles.update');
            Route::delete('/delete/{id}', [RolePermissionController::class, 'deleteRole'])->middleware('permission:roles.delete');
            Route::post('/{id}/permissions', [RolePermissionController::class, 'syncRolePermissions'])->middleware('permission:roles.assign-permissions');
            Route::post('/{id}/permission-scopes', [RolePermissionController::class, 'syncRolePermissionScopes'])->middleware('permission:roles.assign-permission-scopes');
        });

        Route::prefix('permissions')->group(function () {
            Route::get('/all', [RolePermissionController::class, 'getPermissions'])->middleware('permission:permissions.view');
        });

        Route::prefix('admin-user')->group(function () {
            Route::get('/all', [AdminUserController::class, 'getAdminUsers'])->middleware('permission:admin-users.view');
            Route::post('/add', [AdminUserController::class, 'saveAdminUser'])->middleware('permission:admin-users.create');
            Route::post('/update/{id}', [AdminUserController::class, 'updateAdminUser'])->middleware('permission:admin-users.update');
            Route::post('/activate', [AdminUserController::class, 'activateAdminUser'])->middleware('permission:admin-users.activate');
        });

        Route::prefix('admin-users')->group(function () {
            Route::get('/all', [AdminUserController::class, 'getAdminUsers'])->middleware('permission:admin-users.view');
            Route::post('/add', [AdminUserController::class, 'saveAdminUser'])->middleware('permission:admin-users.create');
            Route::post('/update/{id}', [AdminUserController::class, 'updateAdminUser'])->middleware('permission:admin-users.update');
            Route::post('/activate', [AdminUserController::class, 'activateAdminUser'])->middleware('permission:admin-users.activate');
        });

        Route::prefix('web-user')->group(function () {
            Route::get('/all', [WebUserAuthController::class, 'getAllUsersDataByAdmin'])->middleware('permission:web-users.view');
            Route::post('/create', [WebUserAuthController::class, 'saveUserDataByAdmin'])->middleware('permission:web-users.create');
            Route::post('/update', [WebUserAuthController::class, 'updateUserDataByAdmin'])->middleware('permission:web-users.update');
            Route::post('/subscription/approve', [WebUserAuthController::class, 'approveStudentSubscriptionByAdmin'])->middleware('permission:web-users.approve-subscription');
            Route::get('/user/{id}', [WebUserAuthController::class, 'getUserDataByAdmin'])->middleware('permission:web-users.view');

        });
       
       
        Route::prefix('news')->group(function () {
            Route::get('/all', [NewsController::class, 'getAllNewsForAdmin'])->middleware('permission:news.view');
            Route::post('/add', [NewsController::class, 'saveNewsFromAdmin'])->middleware('permission:news.create');
            Route::get('/{id}', [NewsController::class, 'getNewsForAdminById'])->middleware('permission:news.view');
            Route::post('/update/{id}', [NewsController::class, 'updateNewsFromAdmin'])->middleware('permission:news.update');
            Route::post('/activate', [NewsController::class, 'activateNews'])->middleware('permission:news.activate');

            Route::prefix('ticker')->group(function () {
                Route::get('/all', [NewsTickerController::class, 'getAllTickersForAdmin'])->middleware('permission:news-tickers.view');
                Route::get('/news-options', [NewsTickerController::class, 'getNewsOptionsForTicker'])->middleware('permission:news.view');
                Route::post('/add', [NewsTickerController::class, 'saveTickerForAdmin'])->middleware('permission:news-tickers.create');
                Route::post('/update/{id}', [NewsTickerController::class, 'updateTickerForAdmin'])->middleware('permission:news-tickers.update');
                Route::delete('/delete/{id}', [NewsTickerController::class, 'deleteTickerForAdmin'])->middleware('permission:news-tickers.delete');
            });
            
            Route::prefix('category')->group(function () {
                Route::get('/all', [NewsCategoryController::class, 'getAllNewsCategoryForAdmin'])->middleware('permission:news-categories.view');
                Route::post('/add', [NewsCategoryController::class, 'saveNewsCategoryForAdmin'])->middleware('permission:news-categories.create');
                Route::post('/update/{id}', [NewsCategoryController::class, 'updateNewsCategoryForAdmin'])->middleware('permission:news-categories.update');
                Route::delete('/delete/{id}', [NewsCategoryController::class, 'deleteNewsCategoryForAdmin'])->middleware('permission:news-categories.delete');
                Route::get('/active', [NewsCategoryController::class, 'getActiveNewsCategoryForAdmin'])->middleware('permission:news-categories.view');
            });
            
        });

        Route::prefix('class')->group(function () {
            Route::get('/all', [ClassesController::class, 'getClassesForAdmin'])->middleware('permission:classes.view');
            Route::get('/active', [ClassesController::class, 'getActiveClassesForAdmin'])->middleware('permission:classes.view');
            Route::post('/add', [ClassesController::class, 'saveClassForAdmin'])->middleware('permission:classes.create');
            Route::post('/update/{id}', [ClassesController::class, 'updateClassForAdmin'])->middleware('permission:classes.update');
        });

        Route::prefix('curriculum-board')->group(function () {
            Route::get('/all', [CurriculumBoardController::class, 'getAllCurriculumBoardsForAdmin'])->middleware('permission:curriculum-boards.view');
            Route::get('/active', [CurriculumBoardController::class, 'getActiveCurriculumBoardsForAdmin'])->middleware('permission:curriculum-boards.view');
            Route::post('/add', [CurriculumBoardController::class, 'saveCurriculumBoardForAdmin'])->middleware('permission:curriculum-boards.create');
            Route::post('/update/{id}', [CurriculumBoardController::class, 'updateCurriculumBoardForAdmin'])->middleware('permission:curriculum-boards.update');
            Route::post('/activate', [CurriculumBoardController::class, 'activateCurriculumBoardForAdmin'])->middleware('permission:curriculum-boards.activate');
        });

        Route::prefix('subject')->group(function () {
            Route::get('/all', [SubjectsController::class, 'getSubjectsForAdmin'])->middleware('permission:subjects.view');
            Route::get('/active', [SubjectsController::class, 'getActiveSubjectsForAdmin'])->middleware('permission:subjects.view');
            Route::post('/add', [SubjectsController::class, 'saveSubjectForAdmin'])->middleware('permission:subjects.create');
            Route::post('/update/{id}', [SubjectsController::class, 'updateSubjectForAdmin'])->middleware('permission:subjects.update');
            Route::post('/activate', [SubjectsController::class, 'activateSubjectForAdmin'])->middleware('permission:subjects.activate');
        });

        Route::prefix('book')->group(function () {
            Route::get('/all', [BooksController::class, 'getBooksForAdmin'])->middleware('permission:books.view');
            Route::get('/active', [BooksController::class, 'getActiveBooksForAdmin'])->middleware('permission:books.view');
            Route::post('/add', [BooksController::class, 'saveBookForAdmin'])->middleware('permission:books.create');
            Route::post('/update/{id}', [BooksController::class, 'updateBookForAdmin'])->middleware('permission:books.update');
            Route::post('/activate', [BooksController::class, 'activateBookForAdmin'])->middleware('permission:books.activate');
        });

        Route::prefix('book-unit')->group(function () {
            Route::get('/all', [UnitsController::class, 'getUnitsForAdmin'])->middleware('permission:units.view');
            Route::get('/active', [UnitsController::class, 'getActiveUnitsForAdmin'])->middleware('permission:units.view');
            Route::post('/add', [UnitsController::class, 'saveUnitForAdmin'])->middleware('permission:units.create');
            Route::post('/update/{id}', [UnitsController::class, 'updateUnitForAdmin'])->middleware('permission:units.update');
            Route::post('/activate', [UnitsController::class, 'activateUnitForAdmin'])->middleware('permission:units.activate');
        });

        Route::prefix('topic')->group(function () {
            Route::get('/all', [TopicsController::class, 'getTopicsForAdmin'])->middleware('permission:topics.view');
            Route::get('/active', [TopicsController::class, 'getActiveTopicsForAdmin'])->middleware('permission:topics.view');
            Route::post('/add', [TopicsController::class, 'saveTopicForAdmin'])->middleware('permission:topics.create');
            Route::post('/update/{id}', [TopicsController::class, 'updateTopicForAdmin'])->middleware('permission:topics.update');
            Route::post('/activate', [TopicsController::class, 'activateTopicForAdmin'])->middleware('permission:topics.activate');
        });

        Route::prefix('board')->group(function () {
            Route::get('/active', [BoardsController::class, 'getBoards'])->middleware('permission:exam-boards.view');
        });

        Route::prefix('exam-board')->group(function () {
            Route::get('/all', [BoardsController::class, 'getAllBoards'])->middleware('permission:exam-boards.view');
            Route::post('/add', [BoardsController::class, 'saveBoard'])->middleware('permission:exam-boards.create');
            Route::post('/update', [BoardsController::class, 'editBoard'])->middleware('permission:exam-boards.update');
            Route::post('/activate', [BoardsController::class, 'activateBoard'])->middleware('permission:exam-boards.activate');
        });

        Route::prefix('question-type')->group(function () {
            Route::get('/all', [QuestionTypesController::class, 'getAllTypes'])->middleware('permission:question-types.view');
        });

        Route::prefix('cognitive-domain')->group(function () {
            Route::get('/all', [CognitiveDomainController::class, 'getDomain'])->middleware('permission:cognitive-domains.view');
        });

        Route::prefix('topic-content')->group(function () {
            Route::get('/all', [TopicContentController::class, 'getAllContents'])->middleware('permission:topic-content.view');
            Route::get('/by-topic/{topic_id}', [TopicContentController::class, 'getContentsByTopic'])->middleware('permission:topic-content.view');
            Route::post('/add', [TopicContentController::class, 'saveContent'])->middleware('permission:topic-content.create');
            Route::post('/update/{id}', [TopicContentController::class, 'updateContent'])->middleware('permission:topic-content.update');
            Route::post('/activate', [TopicContentController::class, 'activateTopicContent'])->middleware('permission:topic-content.activate');

            Route::prefix('structure')->group(function () {
                Route::get('/by-topic/{topic_id}', [TopicContentController::class, 'getTopicContentStructures'])->middleware('permission:topic-content.view');
                Route::post('/add', [TopicContentController::class, 'saveTopicContentStructure'])->middleware('permission:topic-content.update');
                Route::post('/sync', [TopicContentController::class, 'syncTopicContentStructures'])->middleware('permission:topic-content.update');
                Route::delete('/delete/{id}', [TopicContentController::class, 'deleteTopicContentStructure'])->middleware('permission:topic-content.update');
            });
        });

        Route::prefix('question')->group(function () {
            Route::post('/filter', [QuestionsController::class, 'getQuestionsByFilters'])->middleware('permission:questions.view');
            Route::post('/detail', [QuestionsController::class, 'getQuestionDataById'])->middleware('permission:questions.view');
            Route::post('/save', [QuestionsController::class, 'saveQuestion'])->middleware('permission:questions.create');
            Route::post('/update', [QuestionsController::class, 'updateQuestion'])->middleware('permission:questions.update');
            Route::post('/activate', [QuestionsController::class, 'activateQuestion'])->middleware('permission:questions.activate');
        });

        Route::prefix('offered-classes')->group(function () {
            Route::get('/all', [OfferedClassesController::class, 'getAllOfferedClassesForAdmin'])->middleware('permission:offered-classes.view');
            Route::get('/active', [OfferedClassesController::class, 'getActiveOfferedClassesForAdmin'])->middleware('permission:offered-classes.view');
            Route::post('/add', [OfferedClassesController::class, 'saveOfferedClassForAdmin'])->middleware('permission:offered-classes.create');
            Route::post('/update/{id}', [OfferedClassesController::class, 'updateOfferedClassForAdmin'])->middleware('permission:offered-classes.update');
            Route::post('/activate', [OfferedClassesController::class, 'activateOfferedClassForAdmin'])->middleware('permission:offered-classes.activate');
        });
        Route::prefix('offered-programs')->group(function () {
            Route::get('/all', [OfferedProgramController::class, 'getAllOfferedProgramsForAdmin'])->middleware('permission:offered-programs.view');
            Route::get('/active', [OfferedProgramController::class, 'getActiveOfferedProgramsForAdmin'])->middleware('permission:offered-programs.view');
            Route::post('/add', [OfferedProgramController::class, 'saveOfferedProgramForAdmin'])->middleware('permission:offered-programs.create');
             Route::post('/update/{id}', [OfferedProgramController::class, 'updateOfferedProgramForAdmin'])->middleware('permission:offered-programs.update');
            // Route::post('/activate', [OfferedProgramController::class, 'activateOfferedProgramForAdmin']); 
        });

        Route::prefix('cities')->group(function () {
            Route::post('/add', [CityController::class, 'saveCity'])->middleware('permission:locations.cities.create');
            Route::get('/active', [CityController::class, 'getActiveCitiesForAdmin'])->middleware('permission:locations.cities.view');

        });

        Route::prefix('institutes')->group(function () {
            Route::get('/active/{city_id}', [InstituteController::class, 'getActiveInstituteByCityForAdmin'])->middleware('permission:institutes.view');
        });

        Route::prefix('heard-about')->group(function () {
            Route::get('/active', [HeardAboutController::class, 'getActiveHeardAboutForAdmin'])->middleware('permission:heard-about.view');
        });
    });
});




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
Route::post('/get_questions_by_filters', [QuestionsController::class, 'getQuestionsByFilters']);
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


Route::post('/save_news', [NewsController::class, 'saveNews']);
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
Route::post('/activate_topic_content', [TopicContentController::class, 'activateTopicContent']);

Route::get('/get-cognitive-domain', [CognitiveDomainController::class, 'getDomain']);

Route::post('save-province', [ProvinceController::class, 'saveProvince']);
Route::get('get-provinces', [ProvinceController::class, 'getProvinces']);

Route::post('save-division', [DivisionController::class, 'saveDivision']);
Route::get('get-divisions/{id}', [DivisionController::class, 'getDivisions']);

Route::post('save-district', [DistrictController::class, 'saveDistrict']);
Route::get('get-districts/{id}', [DistrictController::class, 'getDistricts']);

Route::post('save-city', [CityController::class, 'saveCity']);
Route::get('get-cities-by-district/{id}', [CityController::class, 'getCitiesByDistrict']);
Route::get('get-cities', [CityController::class, 'getAllCities']);

Route::post('save-institute', [InstituteController::class, 'saveInstitute']);
Route::post('update-institute', [InstituteController::class, 'updateInstitute']);
Route::get('get-institutes-by-city/{id}', [InstituteController::class, 'getInstitutesByCity']);

Route::post('create-web-user', [WebUserAuthController::class, 'saveUserDataByAdmin']);
Route::get('get-web-user-data', [WebUserAuthController::class, 'getUserDataByAdmin']);
Route::get('get-unverified-web-users', [WebUserAuthController::class, 'getUnVerifiedWebUsers']);
Route::post('update-web-user', [WebUserAuthController::class, 'updateUserDataByAdmin']);
Route::post('verified-web-user', [WebUserAuthController::class, 'verifiedUserByAdmin']);

Route::post('get_study_plans', [StudyPlanController::class, 'getStudyPlansByClass']);
Route::post('get_study_groups', [StudyGroupController::class, 'getStudyGroups']);
Route::post('/activate-study-plan', [StudyPlanController::class, 'activateStudyPlan']);

//--------Model Paper -----------
Route::post('save_model_paper', [ModelPaperController::class, 'saveModelPaper']);
Route::post('get_model_paper_names', [ModelPaperController::class, 'getPaperNames']);
Route::post('save_model_paper_question', [ModelPaperController::class, 'saveModelPaperQuestion']);
Route::get('get_model_paper_units/{id}', [ModelPaperController::class, 'getPaperUnits']);
Route::post('save_model_paper_question_scheme', [ModelPaperController::class, 'saveQuestionScheme']);
Route::get('get_questions_for_update/{id}', [ModelPaperController::class, 'getQuestionsForUpdate']);

Route::post('activate_model_paper_question', [ModelPaperQuestionController::class, 'activateModelPaperQuestion']);
Route::post('update_model_paper_question', [ModelPaperQuestionController::class, 'updateModelPaperQuestion']);

    
});

Route::middleware(CheckFrontendApiKey::class)->group(function () {   
    Route::get('/get_all_active_news_title', [NewsController::class, 'getPaginatedNewsTitles']);
    Route::get('/get_detail_news_by_slug/{slug}', [NewsController::class, 'getNewsDetailBySlug']);
    Route::get('/get_board_data', [BoardsController::class, 'getBoardData']);
    Route::get('/get_past_papers_search_result', [BoardsController::class, 'searchResult']);
    Route::get('/get_past_paper_by_slug', [PapersController::class, 'getPastPaperBySlug']);


    Route::get('/get_all_unique_past_papers_slugs', [PapersController::class, 'getAllSlugs']);
    Route::get('/get_all_unique_news_slugs', [NewsController::class, 'getAllSlugs']);

    Route::prefix('news')->group(function () {
        Route::get('/breaking', [NewsController::class, 'getBreakingNews']);
        Route::get('/featured', [NewsController::class, 'getFeaturedNews']);
    });
    

    //
});


Route::get('/classes', [ClassesController::class, 'getClasses']);
Route::post('/classes', [ClassesController::class, 'getClasses']);

Route::get('/subjects/{id}', [SubjectsController::class, 'getSubjectsByClass']);



Route::get('/boards/{id}/{subject_id}', [BoardsController::class, 'getBoardsByClass_Subjects']);




Route::get('/years/{id}/{subject_id}/{board_id}', [YearsController::class, 'getYearsByBoards_Class_Subjects']);

Route::get('/papers/{id}/{subject_id}/{board_id}/{year}', [PapersController::class, 'getPapersByYears_Boards_Class_Subjects']);


Route::get('/get_all_news', [NewsController::class, 'getAllNews']);
//Route::get('/get_all_active_news_title', [NewsController::class, 'getActiveNewsTitle']);
Route::get('/get_top_active_news_title', [NewsController::class, 'getActiveTopNewsTitle']);
Route::get('/get_news_content/{id}', [NewsController::class, 'getNewsContentById']);
Route::get('/get_news_content_by_slug/{slug}', [NewsController::class, 'getNewsContentBySlug']);

Route::get('/get_all_blogs', [BlogsController::class, 'getAllBlogs']);
Route::get('/get_top_active_blog_title', [BlogsController::class, 'getActiveTopBlogTitle']);
Route::get('/get_blogs_content_by_slug/{slug}', [BlogsController::class, 'getBlogsContentBySlug']);


Route::get('/get_test', [QuestionsController::class, 'getTest']);
Route::post('/save_test', [QuestionsController::class, 'saveTest']);


