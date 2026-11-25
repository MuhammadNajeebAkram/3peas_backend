<?php

use App\Http\Controllers\AnswerRatingController;
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
use App\Http\Controllers\Authentication\WebUserAuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\TopicsController;
use App\Http\Controllers\QuestionTypesController;
use App\Http\Controllers\QuestionsController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsCategoryController;
use App\Http\Controllers\BlogsController;
use App\Http\Controllers\BlogsCategoryController;
use App\Http\Middleware\RefreshAuthTokenMiddleware;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\InstituteController;
use App\Http\Controllers\StudentActivityController;
use App\Http\Controllers\CurriculumBoardController;
use App\Http\Controllers\ContactInquiryController;
use App\Http\Controllers\HeardAboutController;
use App\Http\Controllers\StudyPlanController;
use App\Http\Controllers\StudyGroupController;
use App\Http\Controllers\UserPaymentSlipController;
use App\Http\Controllers\CompleteBookQuestionsController;
use App\Http\Controllers\BoardQuestionsController;
use App\Http\Controllers\ChapterQuestionsController;
use App\Http\Controllers\ExamTestController;
use App\Http\Controllers\DashboardItemController;
use App\Http\Controllers\TopicContentController;
use App\Http\Controllers\CognitiveDomainController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\ModelPapers\ModelPaperController;
use App\Http\Controllers\OfferedClassesController;
use App\Http\Controllers\OfferedProgramController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentAccountController;
use App\Http\Controllers\UserPaymentController;
use App\Http\Controllers\NewsTickerController;
use App\Http\Controllers\Stats\StudentSubjectProgressSummaryController;
use App\Http\Controllers\Stats\StudentUnitProgressSummaryController;
use App\Http\Controllers\Stats\StudentStudyTimeController;
use App\Http\Controllers\Tests\PracticeSessionController;
use App\Http\Controllers\Tests\TestController;
use App\Http\Controllers\Tests\AssessmentConfigController;
use App\Http\Middleware\EnsurePaymentVerified;
use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
use Illuminate\Auth\Events\PasswordReset;
use App\Http\Controllers\WorkshopController;


/*
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
*/

Route::post('/contact/inquiry', [ContactInquiryController::class, 'submit'])->middleware('throttle:20,1');

Route::prefix('auth')->group(function () {
    Route::post('/teacher/register', [\App\Http\Controllers\TeacherAccountController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/teacher/google-login', [\App\Http\Controllers\TeacherAccountController::class, 'google'])->middleware('throttle:10,1');
    
    Route::post('google-login', [WebUserAuthController::class, 'googleLogin']);
    Route::post('/lms-login', [WebUserAuthController::class, 'login']);

    Route::get('/get-offered-classes', [OfferedClassesController::class, 'getOfferedClasses']); 
    Route::get('/get-offered-boards', [OfferedClassesController::class, 'getOfferedBoards']);

    Route::post('/register_user', [WebUserAuthController::class, 'registerWebUser']);

    // LMS authentication routes use the `web_api` guard and a separate JWT cookie.
    Route::middleware([
        AttachJwtFromCookie::class . ':lms',
        AuthenticateJwtCookieGuard::class . ':lms',
    ])->group(function () {
        Route::GET('me', [WebUserAuthController::class, 'me']);
        Route::get('/teacher/profile', [\App\Http\Controllers\TeacherAccountController::class, 'show']);
        Route::post('/teacher/profile', [\App\Http\Controllers\TeacherAccountController::class, 'update']);
        Route::get('/teacher/dashboard', [\App\Http\Controllers\TeacherAccountController::class, 'dashboard']);
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage'])->prefix('teacher/classes')->group(function () {
            Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassController::class, 'store'])->middleware('throttle:20,1');
            Route::get('/{id}', [\App\Http\Controllers\Teacher\TeacherClassController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [\App\Http\Controllers\Teacher\TeacherClassController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [\App\Http\Controllers\Teacher\TeacherClassController::class, 'destroy'])->whereNumber('id');
        });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:students.enroll'])->prefix('teacher/classes/{classId}')->whereNumber('classId')->group(function () {
            Route::get('/students', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'students']);
            Route::get('/enrollment-requests', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'requests']);
            Route::get('/enrollment-history', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'history']);
            Route::get('/enrollments/{enrollmentId}', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'show'])->whereNumber('enrollmentId');
            Route::post('/enrollments/{enrollmentId}/approve', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'approve'])->whereNumber('enrollmentId');
            Route::post('/enrollments/{enrollmentId}/reject', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'reject'])->whereNumber('enrollmentId');
            Route::post('/enrollments/{enrollmentId}/remove', [\App\Http\Controllers\Teacher\TeacherClassEnrollmentController::class, 'remove'])->whereNumber('enrollmentId');
        });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage'])->prefix('teacher/classes/{classId}/resources')->whereNumber('classId')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'overview']);
            Route::get('/textbooks', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'textbooks']);
            Route::get('/textbooks/{bookId}', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'textbookDetail'])->whereNumber('bookId');
            Route::get('/units', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'units']);
            Route::get('/topics/{topicId}/content', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'topicContent'])->whereNumber('topicId');
            Route::get('/slos', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'slos']);
            Route::get('/past-papers', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'pastPapers']);
            Route::get('/past-papers/{source}/{identifier}', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'pastPaperDetail'])
                ->whereIn('source', ['structured', 'catalog'])
                ->where('identifier', '[A-Za-z0-9\-]+');
            Route::get('/past-papers/{source}/{identifier}/pages/{pageId}/file', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'streamPastPaperPage'])
                ->whereIn('source', ['structured', 'catalog'])
                ->where('identifier', '[A-Za-z0-9\-]+')
                ->whereNumber('pageId');
            Route::get('/model-papers', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'modelPapers']);
            Route::get('/model-papers/{paperId}', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'modelPaperDetail'])->whereNumber('paperId');
        });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage', 'teacher.feature:question_bank.access'])->prefix('teacher/classes/{classId}/resources')->whereNumber('classId')->group(function () {
            Route::get('/questions', [\App\Http\Controllers\Teacher\TeacherClassResourceController::class, 'questions']);
        });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage', 'teacher.feature:tests.create'])->prefix('teacher/classes/{classId}/tests')->whereNumber('classId')->group(function () {
            Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'index']);
            Route::get('/{testId}', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'show'])->whereNumber('testId');
            Route::delete('/{testId}', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'destroy'])->whereNumber('testId');
            Route::post('/{testId}/publish', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'publish'])->whereNumber('testId');
            Route::post('/{testId}/close', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'closeTest'])->whereNumber('testId');
            Route::post('/{testId}/archive', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'archiveTest'])->whereNumber('testId');
            Route::get('/{testId}/assignments', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'listAssignments'])->whereNumber('testId');
            Route::get('/{testId}/assignments/{assignmentId}', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'showAssignment'])
                ->whereNumber(['testId', 'assignmentId']);
            Route::get('/{testId}/assignments/{assignmentId}/attempts/{attemptId}', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'showStudentAttempt'])
                ->whereNumber(['testId', 'assignmentId', 'attemptId']);

            Route::middleware('teacher.feature:question_bank.access')->group(function () {
                Route::get('/meta', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'meta']);
                Route::get('/question-bank', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'questionBank']);
                Route::get('/stimulus-groups/{groupId}', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'showStimulusGroup'])->whereNumber('groupId');
                Route::post('/auto-select', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'autoSelect']);
                Route::post('/question-review', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'reviewQuestions']);
                Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'store']);
                Route::put('/{testId}', [\App\Http\Controllers\Teacher\TeacherClassTestController::class, 'update'])->whereNumber('testId');
            });
        });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage', 'teacher.feature:tests.assign'])
            ->prefix('teacher/classes/{classId}/tests/{testId}/assignments')
            ->whereNumber(['classId', 'testId'])
            ->group(function () {
                Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'assignTest']);
                Route::post('/{assignmentId}/release-results', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'releaseResults'])->whereNumber('assignmentId');
                Route::post('/{assignmentId}/close', [\App\Http\Controllers\Teacher\TeacherClassTestDeliveryController::class, 'closeAssignment'])->whereNumber('assignmentId');
            });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage', 'teacher.feature:announcements.manage'])
            ->prefix('teacher/classes/{classId}/announcements')
            ->whereNumber('classId')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'store']);
                Route::get('/{announcementId}', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'show'])->whereNumber('announcementId');
                Route::put('/{announcementId}', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'update'])->whereNumber('announcementId');
                Route::post('/{announcementId}/publish', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'publish'])->whereNumber('announcementId');
                Route::post('/{announcementId}/pin', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'pin'])->whereNumber('announcementId');
                Route::post('/{announcementId}/archive', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'archive'])->whereNumber('announcementId');
                Route::delete('/{announcementId}', [\App\Http\Controllers\Teacher\TeacherClassAnnouncementController::class, 'destroy'])->whereNumber('announcementId');
            });
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage', 'teacher.feature:announcements.manage'])
            ->prefix('teacher/classes/{classId}/discussions')
            ->whereNumber('classId')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'store']);
                Route::get('/reports', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'reports']);
                Route::post('/reports/{reportId}/resolve', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'resolveReport'])->whereNumber('reportId');
                Route::get('/{discussionId}', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'show'])->whereNumber('discussionId');
                Route::put('/{discussionId}', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'update'])->whereNumber('discussionId');
                Route::delete('/{discussionId}', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'destroy'])->whereNumber('discussionId');
                Route::post('/{discussionId}/pin', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'pin'])->whereNumber('discussionId');
                Route::post('/{discussionId}/closed', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'setClosed'])->whereNumber('discussionId');
                Route::post('/{discussionId}/replies', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'storeReply'])->whereNumber('discussionId');
                Route::put('/{discussionId}/replies/{replyId}', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'updateReply'])->whereNumber(['discussionId', 'replyId']);
                Route::delete('/{discussionId}/replies/{replyId}', [\App\Http\Controllers\Teacher\TeacherClassDiscussionController::class, 'destroyReply'])->whereNumber(['discussionId', 'replyId']);
            });
        Route::prefix('student/announcements')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassAnnouncementController::class, 'index']);
            Route::get('/{announcementId}', [\App\Http\Controllers\Student\StudentClassAnnouncementController::class, 'show'])->whereNumber('announcementId');
            Route::post('/{announcementId}/read', [\App\Http\Controllers\Student\StudentClassAnnouncementController::class, 'markRead'])->whereNumber('announcementId');
        });
        Route::prefix('student/classes/{classId}/discussions')->whereNumber('classId')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'store']);
            Route::get('/{discussionId}', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'show'])->whereNumber('discussionId');
            Route::put('/{discussionId}', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'update'])->whereNumber('discussionId');
            Route::delete('/{discussionId}', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'destroy'])->whereNumber('discussionId');
            Route::post('/{discussionId}/replies', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'storeReply'])->whereNumber('discussionId');
            Route::put('/{discussionId}/replies/{replyId}', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'updateReply'])->whereNumber(['discussionId', 'replyId']);
            Route::delete('/{discussionId}/replies/{replyId}', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'destroyReply'])->whereNumber(['discussionId', 'replyId']);
            Route::post('/{discussionId}/report', [\App\Http\Controllers\Student\StudentClassDiscussionController::class, 'report'])->whereNumber('discussionId');
        });
        Route::prefix('messages')->group(function () {
            Route::get('/recipients', [\App\Http\Controllers\PrivateMessageController::class, 'recipients']);
            Route::get('/conversations', [\App\Http\Controllers\PrivateMessageController::class, 'index']);
            Route::post('/conversations', [\App\Http\Controllers\PrivateMessageController::class, 'storeConversation']);
            Route::get('/conversations/{conversationId}/messages', [\App\Http\Controllers\PrivateMessageController::class, 'messages'])->whereNumber('conversationId');
            Route::post('/conversations/{conversationId}/messages', [\App\Http\Controllers\PrivateMessageController::class, 'sendMessage'])->whereNumber('conversationId')->middleware('throttle:60,1');
            Route::post('/conversations/{conversationId}/read', [\App\Http\Controllers\PrivateMessageController::class, 'markRead'])->whereNumber('conversationId');
        });
        Route::prefix('student/tests')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassTestController::class, 'index']);
            Route::get('/{assignmentId}', [\App\Http\Controllers\Student\StudentClassTestController::class, 'show'])->whereNumber('assignmentId');
            Route::post('/{assignmentId}/start', [\App\Http\Controllers\Student\StudentClassTestController::class, 'start'])->whereNumber('assignmentId');
            Route::get('/{assignmentId}/attempts/{attemptId}', [\App\Http\Controllers\Student\StudentClassTestController::class, 'attemptDetail'])->whereNumber(['assignmentId', 'attemptId']);
            Route::put('/{assignmentId}/attempts/{attemptId}/answers', [\App\Http\Controllers\Student\StudentClassTestController::class, 'saveAnswers'])->whereNumber(['assignmentId', 'attemptId']);
            Route::post('/{assignmentId}/attempts/{attemptId}/submit', [\App\Http\Controllers\Student\StudentClassTestController::class, 'submit'])->whereNumber(['assignmentId', 'attemptId']);
        });
        Route::prefix('student/classes')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassEnrollmentController::class, 'index']);
            Route::post('/join', [\App\Http\Controllers\Student\StudentClassEnrollmentController::class, 'join'])->middleware('throttle:20,1');
            Route::get('/{enrollmentId}', [\App\Http\Controllers\Student\StudentClassEnrollmentController::class, 'show'])->whereNumber('enrollmentId');
            Route::post('/{enrollmentId}/leave', [\App\Http\Controllers\Student\StudentClassEnrollmentController::class, 'leave'])->whereNumber('enrollmentId');
        });

        // Question Reporting for Student & Teacher Portals
        Route::prefix('question-reports')->group(function () {
            Route::post('/', [\App\Http\Controllers\Student\QuestionReportController::class, 'store'])->middleware('throttle:30,1');
            Route::get('/my-reports', [\App\Http\Controllers\Student\QuestionReportController::class, 'myReports']);
        });

        // Phase 7F — Teacher Assignments, Calendar & Attendance
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage'])
            ->prefix('teacher/classes/{classId}')
            ->whereNumber('classId')
            ->group(function () {
                Route::prefix('assignments')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'store']);
                    Route::get('/{assignmentId}', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'show'])->whereNumber('assignmentId');
                    Route::post('/{assignmentId}', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'update'])->whereNumber('assignmentId');
                    Route::put('/{assignmentId}', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'update'])->whereNumber('assignmentId');
                    Route::delete('/{assignmentId}', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'destroy'])->whereNumber('assignmentId');
                    Route::post('/{assignmentId}/publish', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'publish'])->whereNumber('assignmentId');
                    Route::post('/{assignmentId}/close', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'close'])->whereNumber('assignmentId');
                    Route::post('/{assignmentId}/reopen', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'reopen'])->whereNumber('assignmentId');
                    Route::post('/{assignmentId}/archive', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'archive'])->whereNumber('assignmentId');
                    Route::delete('/{assignmentId}/attachments/{attachmentId}', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'deleteAttachment'])->whereNumber(['assignmentId', 'attachmentId']);
                    Route::get('/{assignmentId}/attachments/{attachmentId}/download', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'downloadAttachment'])->whereNumber(['assignmentId', 'attachmentId']);
                    Route::get('/{assignmentId}/submissions', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'submissions'])->whereNumber('assignmentId');
                    Route::get('/{assignmentId}/submissions/{studentWebUserId}', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'submissionDetail'])->whereNumber(['assignmentId', 'studentWebUserId']);
                    Route::post('/{assignmentId}/submissions/{studentWebUserId}/evaluate', [\App\Http\Controllers\Teacher\TeacherAssignmentEvaluationController::class, 'evaluate'])->whereNumber(['assignmentId', 'studentWebUserId']);
                    Route::get('/{assignmentId}/submission-attachments/{attachmentId}/download', [\App\Http\Controllers\Teacher\TeacherClassAssignmentController::class, 'downloadSubmissionAttachment'])->whereNumber(['assignmentId', 'attachmentId']);
                });

                Route::prefix('calendar')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'store']);
                    Route::get('/{eventId}', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'show'])->whereNumber('eventId');
                    Route::post('/{eventId}', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'update'])->whereNumber('eventId');
                    Route::put('/{eventId}', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'update'])->whereNumber('eventId');
                    Route::post('/{eventId}/cancel', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'cancel'])->whereNumber('eventId');
                    Route::post('/{eventId}/archive', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'archive'])->whereNumber('eventId');
                    Route::get('/{eventId}/attachment/download', [\App\Http\Controllers\Teacher\TeacherClassCalendarController::class, 'downloadAttachment'])->whereNumber('eventId');
                });

                Route::prefix('attendance')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'index']);
                    Route::post('/', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'store']);
                    Route::get('/{sessionId}', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'show'])->whereNumber('sessionId');
                    Route::post('/{sessionId}/mark', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'mark'])->whereNumber('sessionId');
                    Route::post('/{sessionId}/finalize', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'finalize'])->whereNumber('sessionId');
                    Route::post('/{sessionId}/reopen', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'reopen'])->whereNumber('sessionId');
                    Route::get('/students/{studentWebUserId}/history', [\App\Http\Controllers\Teacher\TeacherClassAttendanceController::class, 'studentHistory'])->whereNumber('studentWebUserId');
                });
            });

        // Phase 7G — Teacher Gradebook & Class Academic Performance
        Route::middleware([\App\Http\Middleware\EnsureTeacherActive::class, 'teacher.feature:classes.manage', 'teacher.feature:reports.basic'])
            ->prefix('teacher/classes/{classId}')
            ->whereNumber('classId')
            ->group(function () {
                Route::get('/gradebook', [\App\Http\Controllers\Teacher\TeacherClassGradebookController::class, 'gradebook']);
                Route::get('/gradebook/config', [\App\Http\Controllers\Teacher\TeacherClassGradebookController::class, 'config']);
                Route::put('/gradebook/config', [\App\Http\Controllers\Teacher\TeacherClassGradebookController::class, 'updateConfig']);
                Route::post('/gradebook/exclusions', [\App\Http\Controllers\Teacher\TeacherClassGradebookController::class, 'toggleExclusion']);
                Route::get('/performance', [\App\Http\Controllers\Teacher\TeacherClassGradebookController::class, 'performance']);
            });

        // Phase 7F — Student Assignments, Calendar & Attendance
        Route::prefix('student/assignments')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'index']);
            Route::get('/{assignmentId}', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'show'])->whereNumber('assignmentId');
            Route::post('/{assignmentId}/draft', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'saveDraft'])->whereNumber('assignmentId');
            Route::post('/{assignmentId}/submit', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'submit'])->whereNumber('assignmentId');
            Route::delete('/{assignmentId}/attachments/{attachmentId}', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'deleteAttachment'])->whereNumber(['assignmentId', 'attachmentId']);
            Route::get('/{assignmentId}/attachments/{attachmentId}/download', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'downloadAssignmentAttachment'])->whereNumber(['assignmentId', 'attachmentId']);
            Route::get('/{assignmentId}/submission-attachments/{attachmentId}/download', [\App\Http\Controllers\Student\StudentClassAssignmentController::class, 'downloadOwnSubmissionAttachment'])->whereNumber(['assignmentId', 'attachmentId']);
        });

        Route::prefix('student/calendar')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassCalendarController::class, 'index']);
            Route::get('/{eventId}/attachment/download', [\App\Http\Controllers\Student\StudentClassCalendarController::class, 'downloadAttachment'])->whereNumber('eventId');
        });

        Route::prefix('student/attendance')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentClassAttendanceController::class, 'index']);
        });

        // Phase 7G — Student Performance & Assessment Results Summary
        Route::prefix('student/performance')->group(function () {
            Route::get('/', [\App\Http\Controllers\Student\StudentPerformanceController::class, 'index']);
            Route::get('/assessments/{assessmentId}', [\App\Http\Controllers\Student\StudentPerformanceController::class, 'showAssessment'])->whereNumber('assessmentId');
        });

        // Phase 1E.2.B — Student Freemium Discovery & Quota APIs
        Route::prefix('freemium')->group(function () {
            Route::get('/entitlements', [\App\Http\Controllers\Api\Student\Freemium\StudentFreemiumAccessController::class, 'entitlements']);
            Route::get('/free-resources', [\App\Http\Controllers\Api\Student\Freemium\StudentFreemiumAccessController::class, 'freeResources']);
            Route::post('/evaluate-access', [\App\Http\Controllers\Api\Student\Freemium\StudentFreemiumAccessController::class, 'evaluateAccess']);
            Route::get('/quota-allowances', [\App\Http\Controllers\Api\Student\Freemium\StudentFreemiumQuotaController::class, 'quotaAllowances']);
            Route::get('/tests/{testId}/eligibility', [\App\Http\Controllers\Api\Student\Freemium\StudentFreemiumQuotaController::class, 'testEligibility'])->whereNumber('testId');
        });

        Route::prefix('notifications')->group(function () {
            Route::get('/', [\App\Http\Controllers\WebUserNotificationController::class, 'index']);
            Route::post('/read-all', [\App\Http\Controllers\WebUserNotificationController::class, 'markAllRead']);
            Route::post('/{id}/read', [\App\Http\Controllers\WebUserNotificationController::class, 'markRead'])->whereNumber('id');
        });
        Route::middleware(\App\Http\Middleware\EnsureTeacherActive::class)->prefix('teacher/subscription')->group(function () {
            Route::get('/', [\App\Http\Controllers\Teacher\TeacherSubscriptionController::class, 'show']);
            Route::get('/features', [\App\Http\Controllers\Teacher\TeacherSubscriptionController::class, 'features']);
            Route::get('/usage', [\App\Http\Controllers\Teacher\TeacherSubscriptionController::class, 'usage']);
        });
        Route::get('/teacher/collections', [\App\Http\Controllers\TeacherSettlementController::class, 'collections']);
        Route::get('/teacher/settlements', [\App\Http\Controllers\TeacherSettlementController::class, 'index']);
        Route::post('/teacher/settlements', [\App\Http\Controllers\TeacherSettlementController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/teacher/settlements/{id}/proof', [\App\Http\Controllers\TeacherSettlementController::class, 'proof'])->whereNumber('id');
        Route::prefix('teacher/finances')->group(function () {
            Route::get('/summary', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'summary']);
            Route::get('/chart', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'chart']);
            Route::get('/payments', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'payments']);
            Route::get('/payments/{id}', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'paymentDetail'])->whereNumber('id');
            Route::get('/settlements/summary', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'settlementSummary']);
            Route::get('/settlements', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'settlements']);
            Route::get('/report', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'report']);
            Route::get('/export', [\App\Http\Controllers\Teacher\TeacherFinancialController::class, 'export'])->middleware('throttle:20,1');
        });
        Route::post('/complete-profile', [WebUserAuthController::class, 'completeProfile']);
        Route::post('/lms-logout', [WebUserAuthController::class, 'logout']);
        Route::get('/get-user-offered-boards', [OfferedClassesController::class, 'getUserOfferedBoards']);
        Route::get('/get-user-subscribed-classes', [OfferedClassesController::class, 'getUserSubscribedClasses']);
        Route::get('/get-user-subscribed-programs', [OfferedProgramController::class, 'getUserSubscribedOfferedProgramsForLMS']);
        Route::get('/get-offered-class-details/{slug}', [OfferedClassesController::class, 'getOfferedClassDetails']);
        Route::get('/get-offered-program-details/{slug}', [OfferedProgramController::class, 'getOfferedProgramDetails']);

        Route::prefix('statistics')->group(function () {
            Route::post('/get-student-study-time', [StudentStudyTimeController::class, 'getStudentStudyTimeForLms']);
            Route::post('/get-subject-preparation-scores', [StudentSubjectProgressSummaryController::class, 'getSubjectPreparationScoresForLms']);
            Route::post('/get-student-subject-performance-overview', [StudentSubjectProgressSummaryController::class, 'getStudentSubjectPerformanceOverviewForLms']);
            Route::post('/get-student-subject-mcq-stats', [StudentSubjectProgressSummaryController::class, 'getStudentSubjectMcqStatsForLms']);
            Route::post('/get-student-weak-units', [StudentSubjectProgressSummaryController::class, 'getStudentWeakUnitsForLms']);
            Route::post('/get-student-weak-topics', [StudentUnitProgressSummaryController::class, 'getStudentWeakTopicsForLms']);
            Route::post('/get-student-unit-performance-overview', [StudentUnitProgressSummaryController::class, 'getStudentUnitPerformanceOverviewForLms']);
            Route::post('/get-student-unit-mcq-stats', [StudentUnitProgressSummaryController::class, 'getStudentUnitMcqStatsForLms']);
        });

        Route::prefix('tests')->group(function () {
            Route::get('/assessment-configuration', [AssessmentConfigController::class, 'getAssessmentConfiguration']);
            Route::post('/assessment-configuration', [AssessmentConfigController::class, 'getAssessmentConfiguration']);
            Route::get('/academic-hierarchy', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getAcademicHierarchy']);
            Route::post('/academic-hierarchy', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getAcademicHierarchy']);
            Route::get('/topics', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getTopics']);
            Route::post('/topics', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getTopics']);
            Route::get('/questions', [\App\Http\Controllers\Tests\QuestionExplorerController::class, 'getQuestions']);
            Route::post('/questions', [\App\Http\Controllers\Tests\QuestionExplorerController::class, 'getQuestions']);
            Route::post('/generate-selected-units-formal-test', [TestController::class, 'generateSelectedUnitsFormalTestForLms']);
            Route::post('/generate-full-book-formal-test', [TestController::class, 'generateFullBookFormalTestForLms']);
            Route::post('/generate-full-book-mock-test', [TestController::class, 'generateFullBookMockTestForLms']);
            Route::get('/get-recent-attempts', [TestController::class, 'getRecentAttemptsForLms']);
            Route::get('/formal-tests/{testId}', [TestController::class, 'getFormalTestForLms'])->whereNumber('testId');
            Route::post('/save-formal-test-question-progress', [TestController::class, 'saveFormalTestQuestionProgressForLms']);
            Route::post('/submit-formal-test', [TestController::class, 'submitFormalTestForLms']);
            Route::post('/generate-selected-units-practice-session', [PracticeSessionController::class, 'generateSelectedUnitsPracticeSessionForLms']);
            Route::post('/generate-full-book-practice-session', [PracticeSessionController::class, 'generateFullBookPracticeSessionForLms']);
            Route::get('/practice-sessions/{sessionId}', [PracticeSessionController::class, 'getPracticeSessionForLms'])->whereNumber('sessionId');
            Route::post('/practice-sessions/{sessionId}/abandon', [PracticeSessionController::class, 'abandonPracticeSessionForLms'])->whereNumber('sessionId');
            Route::post('/save-practice-session-question-progress', [PracticeSessionController::class, 'savePracticeSessionQuestionProgressForLms']);
            Route::post('/submit-practice-session', [PracticeSessionController::class, 'submitPracticeSessionForLms']);
        });

        Route::prefix('question-explorer')->group(function () {
            Route::get('/academic-hierarchy', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getAcademicHierarchy']);
            Route::post('/academic-hierarchy', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getAcademicHierarchy']);
            Route::get('/topics', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getTopics']);
            Route::post('/topics', [\App\Http\Controllers\Tests\AcademicHierarchyController::class, 'getTopics']);
            Route::get('/questions', [\App\Http\Controllers\Tests\QuestionExplorerController::class, 'getQuestions']);
            Route::post('/questions', [\App\Http\Controllers\Tests\QuestionExplorerController::class, 'getQuestions']);
        });

        Route::prefix('activities')->group(function () {
            Route::get('/get-student-activities', [StudentActivityController::class, 'getStudentActivitiesForLms']);
        });
        
        Route::post('/save-payment-request', [UserPaymentController::class, 'submitPaymentRequest'])->middleware('throttle:10,1');
        Route::get('/subscription-offerings', [UserPaymentController::class, 'offerings']);
        Route::get('/subscription-payment-requests', [UserPaymentController::class, 'mine']);
        Route::get('/subscription-payment-requests/{id}/proof', [\App\Http\Controllers\PaymentEvidenceController::class, 'student'])->whereNumber('id');
        Route::get('/subscription-payment-requests/{id}/receipt', [UserPaymentController::class, 'receipt'])->whereNumber('id');
        Route::get('/payments/methods', [\App\Http\Controllers\TeacherPaymentController::class, 'methods']);
        Route::post('/payments/teacher/lookup', [\App\Http\Controllers\TeacherPaymentController::class, 'lookup'])->middleware('throttle:20,1');
        Route::get('/payments/teacher/requests', [\App\Http\Controllers\TeacherPaymentController::class, 'mine']);
        Route::get('/teacher/payment-requests', [\App\Http\Controllers\TeacherPaymentController::class, 'index']);
        Route::get('/teacher/payment-requests/{id}', [\App\Http\Controllers\TeacherPaymentController::class, 'show'])->whereNumber('id');
        Route::get('/teacher/payment-requests/{id}/proof', [\App\Http\Controllers\PaymentEvidenceController::class, 'teacher'])->whereNumber('id');
        Route::post('/teacher/payment-requests/{id}/approve', [\App\Http\Controllers\TeacherPaymentController::class, 'approve'])->whereNumber('id');
        Route::post('/teacher/payment-requests/{id}/reject', [\App\Http\Controllers\TeacherPaymentController::class, 'reject'])->whereNumber('id');

        Route::prefix('my-classes')->group(function () {
            Route::get('/get-books/{program_id}/{subject_id}', [BooksController::class, 'getBooksByProgram']);
            Route::get('/get-units/{program_id}/{subject_id}', [UnitsController::class, 'getUnitsOfUserSubjectForLMS']);
            Route::get('/get-topics-by-units/{unit_id}/topics', [TopicsController::class, 'getTopicsByUnitForLMS']);
            Route::get('/get-questions-by-topic/{topic_id}/questions', [QuestionsController::class, 'getQuestionsByTopicForLMS']);
        });
        
        Route::prefix('accounts')->group(function () {
            Route::get('/get-bank-accounts', [PaymentAccountController::class, 'getPaymentBankAccounts']);
            
        });

    });

});

Route::prefix('news')->group(function () {
    Route::get('/ticker', [NewsTickerController::class, 'getActiveTickersForWeb']);
    Route::get('/featured', [NewsController::class, 'getPublishableFeturedNewsForWeb']);
    Route::get('/educational', [NewsController::class, 'getPublishedEducationalNewsForWeb']);
    Route::get('/events', [NewsController::class, 'getEventNewsForWeb']);
    Route::get('/videos', [NewsController::class, 'getVideoNewsForWeb']);
    Route::get('/detail/{slug}', [NewsController::class, 'getNewsDetailForWebBySlug']);
});

Route::prefix('lms/workshops')->group(function () {
    Route::get('/', [WorkshopController::class, 'index']);
    Route::get('/{slug}', [WorkshopController::class, 'show']);

    Route::middleware( [AttachJwtFromCookie::class . ':lms',
    AuthenticateJwtCookieGuard::class . ':lms'],)->group(function () {
        Route::post('/{id}/register', [WorkshopController::class, 'register']);
        Route::post('/{id}/unregister', [WorkshopController::class, 'unregister']);
    });
});


Route::post('login', [AuthController::class, 'login']);
Route::post('/web-login', [WebUserAuthController::class, 'login']);
//Route::post('/register_user', [AuthController::class, 'registerWebUser']);
Route::post('/register_user', [WebUserAuthController::class, 'registerWebUser']);

Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);


// Email Verification Routes for JWT
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
->name('verification.verify');




Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth:web_api', 'throttle:6,1'])
    ->name('verification.resend');

    Route::get('/check-email-verification/{email}', [EmailVerificationController::class, 'checkEmailVerification']);

    Route::post('/upload_deposit_slip', [UserPaymentSlipController::class, 'uploadPaymentSlip'])->middleware('auth:web_api');

Route::post('/upload-image-to-s3', [UserPaymentSlipController::class, 'uploadDepositSlipImage'])->middleware('auth:web_api');

Route::middleware(['auth:web_api', 'verified', EnsurePaymentVerified::class, 'studySessionVerified'])->group(function () {

    route::post('/check_status', [App\Http\Controllers\Authentication\WebUserAuthController::class, 'getStatus']);

    


    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('update-password', [AuthController::class, 'updatePassword']);
    Route::post('register', [AuthController::class, 'registerWebUser']);

    Route::post('user_profile_data', [AuthController::class, 'getUserProfile']);
    Route::post('update-user-profile', [AuthController::class, 'updateUserProfile']);

    //Student Dashboard
    Route::post('get-summary-data-by-subject', [DashboardItemController::class, 'getOverallSummary']);
    Route::post('get-subject-leaderboard', [DashboardItemController::class, 'getSubjectLeaderBoard']);
    Route::post('get-test-history', [DashboardItemController::class, 'getTestHistory']);

    //-----------------

    Route::post('/add_class/{name}', [ClassesController::class, 'saveClass']);
    Route::post('/update_class', [ClassesController::class, 'editClass']);
    Route::post('/activate_class', [ClassesController::class, 'activateClass']);
    Route::get('/get-class-of-user', [ClassesController::class, 'getClassOfUser']);

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
Route::post('/get_units_by_user_selected_book', [UnitsController::class, 'getUnitsByUserSelectedBook']);

Route::get('/get_all_topics', [TopicsController::class, 'getAllTopics']);
Route::POST('/get_topics_by_unit', [TopicsController::class, 'getTopicsByUnit']);
Route::post('/add_topics', [TopicsController::class, 'saveTopic']);
Route::post('/update_topics', [TopicsController::class, 'editTopic']);
Route::post('/activate_topic', [TopicsController::class, 'activateTopic']);

Route::get('/get_all_question_types', [QuestionTypesController::class, 'getAllTypes']);
Route::get('/get_activate_question_types', [QuestionTypesController::class, 'getActivateQuestionTypes']);

Route::get('/get_all_questions', [QuestionsController::class, 'getAllQuestions']);
Route::post('/get_questions_by_topic', [QuestionsController::class, 'getQuestionsByTopic']);
Route::post('/get_questions_by_unit', [QuestionsController::class, 'getQuestionsByUnit']);
Route::post('/get_questions_by_book', [QuestionsController::class, 'getQuestionsByBook']);
Route::post('/get_questions_by_board', [QuestionsController::class, 'getQuestionsByBoard']);
Route::post('/get_question_data_by_id', [QuestionsController::class, 'getQuestionDataById']);
Route::post('/get_question_scenario_groups', [QuestionsController::class, 'getQuestionScenarioGroups']);
Route::post('/get_question_scenario_group_by_id', [QuestionsController::class, 'getQuestionScenarioGroupById']);

Route::post('/get-slo-topic-content-questions', [QuestionsController::class, 'getSLOQuestionsByTopic']);


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

Route::post('/get_subjects_by_user', [SubjectsController::class, 'getSubjectsByUser']); 

Route::post('/get_top_mcqs', [CompleteBookQuestionsController::class, 'getTopMCQsQuestions']);
Route::post('/get_random_mcqs', [CompleteBookQuestionsController::class, 'getRandomMCQsQuestions']);
Route::post('/get_top_sqs', [CompleteBookQuestionsController::class, 'getTopSQsQuestions']);
Route::post('/get_random_sqs', [CompleteBookQuestionsController::class, 'getRandomSQsQuestions']);

Route::post('/get_mcqs_of_board', [BoardQuestionsController::class, 'getMCQsQuestions']);
Route::post('/get_exam_questions_of_board', [BoardQuestionsController::class, 'getExamQuestions']);
Route::post('/get_top_mcqs_of_units', [ChapterQuestionsController::class, 'getTopMCQsQuestions']);
Route::post('/get_top_sqs_of_units', [ChapterQuestionsController::class, 'getTopSQsQuestions']);

//Exam Test
Route::post('/get_practice_test_mcqs_by_units', [ExamTestController::class, 'getPracticeMCQsTestOfUnits']);
Route::post('/get_random_test_mcqs_by_units', [ExamTestController::class, 'getRandomMCQsTestOfUnits']);
Route::post('/save-exam-test', [ExamTestController::class, 'saveTestResult']);

Route::get('/get-topic-contents-by-topic/{topic_id}', [TopicContentController::class, 'getContentsByTopic']);

Route::get('/get-cognitive-domain', [CognitiveDomainController::class, 'getDomain']);

Route::post('/save-answer-rating', [AnswerRatingController::class, 'saveRating']);

Route::post('save_feedback', [FeedbackController::class, 'saveFeedback']);

//--------- Model Papers ------------------

Route::post('generate-model-paper', [ModelPaperController::class, 'generateModelPaper']);
Route::get('generate-model-paper-questions/{id}', [ModelPaperController::class, 'getModelPaperSubQuestions']);


    
});

Route::prefix('news')->group(function () {
    Route::get('/ticker', [NewsTickerController::class, 'getActiveTickersForWeb']);
});



    
 
 //Route::get('/get_all_active_news_title', [NewsController::class, 'getPaginatedNewsTitles']);

Route::get('/classes', [ClassesController::class, 'getClasses']);
Route::post('/classes', [ClassesController::class, 'getClasses']);


Route::get('/subjects/{id}', [SubjectsController::class, 'getSubjectsByClass']);



Route::get('/boards/{id}/{subject_id}', [BoardsController::class, 'getBoardsByClass_Subjects']);




Route::get('/years/{id}/{subject_id}/{board_id}', [YearsController::class, 'getYearsByBoards_Class_Subjects']);

Route::get('/papers/{id}/{subject_id}/{board_id}/{year}', [PapersController::class, 'getPapersByYears_Boards_Class_Subjects']);


Route::get('/get_all_news', [NewsController::class, 'getAllNews']);

Route::get('/get_top_active_news_title', [NewsController::class, 'getActiveTopNewsTitle']);
Route::get('/get_news_content/{id}', [NewsController::class, 'getNewsContentById']);
Route::get('/get_news_content_by_slug/{slug}', [NewsController::class, 'getNewsContentBySlug']);

Route::get('/get_all_blogs', [BlogsController::class, 'getAllBlogs']);
Route::get('/get_top_active_blog_title', [BlogsController::class, 'getActiveTopBlogTitle']);
Route::get('/get_blogs_content_by_slug/{slug}', [BlogsController::class, 'getBlogsContentBySlug']);

Route::post('/get_cities', [CityController::class, 'getCities']);
Route::post('/get_institutes', [InstituteController::class, 'getInstitutes']);



Route::post('/get_curriculum', [CurriculumBoardController::class, 'getCurriculumBoard']);

Route::post('/get_heard_about', [HeardAboutController::class, 'getHeardAbout']);

//Route::post('/get_study_plans', [StudyPlanController::class, 'getStudyPlans']);
Route::post('/get_study_plans', [StudyPlanController::class, 'getStudyPlansByClass']);

Route::post('/get_bank_accounts', [BankAccountController::class, 'getBankAccounts']);

Route::post('/get_study_groups', [StudyGroupController::class, 'getStudyGroups']);
Route::post('/get_study_subjects', [StudyGroupController::class, 'getStudySubjects']);
