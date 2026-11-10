<?php

use App\Http\Controllers\Api\AdminAccessController;
use App\Http\Controllers\Api\AdminAccountController;
use App\Http\Controllers\Api\AdminCheapClassController;
use App\Http\Controllers\Api\AdminClassController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminFinanceOperationsController;
use App\Http\Controllers\Api\AdminFinanceReportController;
use App\Http\Controllers\Api\AdminMatchingController;
use App\Http\Controllers\Api\AdminPublicTutorController;
use App\Http\Controllers\Api\AdminRatingController;
use App\Http\Controllers\Api\AdminSettingController;
use App\Http\Controllers\Api\AdminStageFiveController;
use App\Http\Controllers\Api\AdminTeacherScheduleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheapClassController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CurriculumChapterController;
use App\Http\Controllers\Api\CurriculumSubjectController;
use App\Http\Controllers\Api\CurriculumSubjectGroupController;
use App\Http\Controllers\Api\CustomerWalletController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\GuestPackageOrderController;
use App\Http\Controllers\Api\HourlyRateController;
use App\Http\Controllers\Api\LearningCatalogController;
use App\Http\Controllers\Api\LearningProgramController;
use App\Http\Controllers\Api\LearningSessionController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentPinController;
use App\Http\Controllers\Api\PrivateClassJoinController;
use App\Http\Controllers\Api\PrivateParticipantPricingController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProtectedFileController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\PublicMediaController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\ScheduleChangeController;
use App\Http\Controllers\Api\ScheduleRecommendationController;
use App\Http\Controllers\Api\SessionWorkflowController;
use App\Http\Controllers\Api\StageFiveContentController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentPackageController;
use App\Http\Controllers\Api\StudentRefundController;
use App\Http\Controllers\Api\SubjectPageContentController;
use App\Http\Controllers\Api\TeacherCheapClassController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherDocumentController;
use App\Http\Controllers\Api\TeacherOfferController;
use App\Http\Controllers\Api\TeacherOperationsController;
use App\Http\Controllers\Api\TeacherReplacementController;
use App\Http\Controllers\Api\TeacherScheduleController;
use App\Http\Controllers\Api\TutorAvailabilityController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebsiteContentController;
use App\Http\Controllers\Api\WebsiteTutorGalleryController;
use App\Http\Controllers\Api\XenditPaymentController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:auth-login');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:auth-login');
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:auth-login');
Route::post('/auth/google/complete-profile', [GoogleAuthController::class, 'completeProfile'])
    ->middleware(['auth:sanctum', 'throttle:auth-register']);
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:auth-forgot-password');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-reset-password');
Route::post('/payment-pin/reset-with-token', [PaymentPinController::class, 'resetWithEmailToken'])->middleware('throttle:payment-pin-reset-token');
Route::post('/email/verification/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:auth-email-verification-resend');
Route::post('/email/verification/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:auth-email-verification-verify');

Route::get('/learning-catalog', [LearningCatalogController::class, 'index'])->middleware('throttle:public-read');
Route::get('/program-groups', [ProgramController::class, 'index'])->middleware('throttle:public-read');
Route::get('/learning-programs', [LearningProgramController::class, 'index'])->middleware('throttle:public-read');
Route::get('/public-tutors', [WebsiteContentController::class, 'tutors'])->middleware('throttle:public-read');
Route::get('/website-tutor-gallery', [WebsiteTutorGalleryController::class, 'publicIndex'])->middleware('throttle:public-read');
Route::get('/subject-pages/{curriculumSubject}', [SubjectPageContentController::class, 'show'])->whereNumber('curriculumSubject')->middleware('throttle:public-read');
Route::get('/settings/footer', [PublicController::class, 'getFooterSettings'])->middleware('throttle:public-read');
Route::get('/socials', [AdminController::class, 'getSocials'])->middleware('throttle:public-read');
Route::get('/settings/teacher-cover', [AdminSettingController::class, 'getTeacherCover'])->middleware('throttle:public-read');
Route::get('/package-plans', [StudentPackageController::class, 'plans'])->middleware('throttle:public-read');
Route::get('/learning-time-slots', [StudentPackageController::class, 'timeSlots'])->middleware('throttle:public-read');
Route::get('/package-booking-rules', [StudentPackageController::class, 'bookingRules'])->middleware('throttle:public-read');
Route::get('/private-participant-pricing', [PrivateParticipantPricingController::class, 'show'])->middleware('throttle:public-read');
Route::post('/guest/packages/quote', [StudentPackageController::class, 'previewPromotion'])->middleware('throttle:guest-package-quote');
Route::post('/guest/packages', [GuestPackageOrderController::class, 'store'])->middleware('throttle:guest-package-create');
Route::get('/guest/packages/{code}', [GuestPackageOrderController::class, 'show'])->middleware('throttle:guest-package-status');
Route::get('/content/banners', [StageFiveContentController::class, 'banners'])->middleware('throttle:public-read');
Route::get('/content/tutorials', [StageFiveContentController::class, 'tutorials'])->middleware('throttle:public-read');
Route::get('/content/promotions', [StageFiveContentController::class, 'promotions'])->middleware('throttle:public-read');
Route::get('/content/promotions/{promotion}', [StageFiveContentController::class, 'promotion'])->middleware('throttle:public-read');
Route::get('/website-content', [WebsiteContentController::class, 'show'])->middleware('throttle:public-read');
Route::get('/testimonials', [WebsiteContentController::class, 'testimonials'])->middleware('throttle:public-read');
Route::get('/public-media/{path}', PublicMediaController::class)
    ->where('path', '.*')
    ->middleware('throttle:public-media');
Route::post('/webhooks/xendit', [XenditPaymentController::class, 'webhook']);

Route::middleware(['auth:sanctum', 'active.account', 'persistent.session'])->group(function () {
    Route::get('/user', [UserController::class, 'show']);
    Route::post('/guest/packages/{code}/claim', [GuestPackageOrderController::class, 'claim'])->middleware(['role:student', 'throttle:guest-package-claim']);
    Route::get('/student/guest-packages/pending', [GuestPackageOrderController::class, 'pendingForUser'])->middleware('role:student');
    Route::put('/user', [UserController::class, 'update']);
    // Multipart FormData memakai POST + _method=PUT pada frontend agar unggahan
    // berkas tetap terbaca konsisten oleh PHP.
    Route::post('/user', [UserController::class, 'update']);
    Route::put('/user/password', [UserController::class, 'updatePassword'])
        ->middleware('throttle:account-password-change');
    Route::post('/user/set-initial-password', [UserController::class, 'setInitialPassword'])
        ->middleware('throttle:account-password-change');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('throttle:account-password-change');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-batch', [NotificationController::class, 'markManyAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
        ->whereNumber('id');
    Route::get('/teachers/{teacher}/documents/{document}', [TeacherDocumentController::class, 'show'])
        ->where('document', 'cv_file|identity_document|live_selfie|qualification_document|certification_document');
    Route::get('/orders/{order}/payment-proof', [ProtectedFileController::class, 'paymentProof']);
    Route::get('/session-reports/{sessionReport}/evidence', [ProtectedFileController::class, 'reportEvidence']);
    Route::get('/disputes/{bookingDispute}/evidence', [ProtectedFileController::class, 'disputeEvidence']);
    Route::get('/payouts/{payout}/proof', [ProtectedFileController::class, 'payoutProof']);
    Route::get('/refunds/{refund}/proof', [ProtectedFileController::class, 'refundProof']);
    Route::get('/bookings/{booking}/learning-session', [LearningSessionController::class, 'show']);
    Route::get('/session-action/next', [LearningSessionController::class, 'nextAction'])
        ->middleware('throttle:session-action-poll');
    Route::get('/payment-settings/qris', [AdminController::class, 'qrisImage']);
    Route::get('/conversations', [LearningSessionController::class, 'conversations']);
    Route::delete('/conversations/{booking}', [LearningSessionController::class, 'destroyConversation']);
    Route::post('/bookings/{booking}/messages', [LearningSessionController::class, 'storeMessage'])
        ->middleware('throttle:booking-message-send');
    Route::get('/classroom-messages/{classroomMessage}/attachment', [ProtectedFileController::class, 'classroomMessageAttachment']);
    Route::get('/teacher-replacements/{teacherReplacement}/evidence', [ProtectedFileController::class, 'teacherReplacementEvidence'])
        ->middleware('feature:teacher_replacement');
    Route::get('/bookings/{booking}/schedule-options', [ScheduleChangeController::class, 'options'])
        ->middleware('throttle:booking-schedule-options');
    Route::post('/bookings/{booking}/schedule-changes', [ScheduleChangeController::class, 'store'])
        ->middleware('throttle:booking-schedule-change-create');
    Route::post('/bookings/{booking}/schedule-changes/{scheduleChangeRequest}/respond', [ScheduleChangeController::class, 'respond'])
        ->middleware('throttle:booking-schedule-change-respond');

    Route::middleware('role:student')->group(function () {
        Route::get('/student/cheap-classes', [CheapClassController::class, 'index']);
        Route::get('/student/cheap-classes/{cheapClass}', [CheapClassController::class, 'show']);
        Route::post('/student/cheap-classes/{cheapClass}/join', [CheapClassController::class, 'join'])
            ->middleware(['throttle:student-cheap-class-join', 'idempotency']);
        Route::post('/student/cheap-class-enrollments/{cheapClassEnrollment}/cancel', [CheapClassController::class, 'cancel'])
            ->middleware('throttle:student-cheap-class-cancel');
        Route::post('/student/tutor-availability', [TutorAvailabilityController::class, 'check'])
            ->middleware('throttle:student-tutor-availability');
        Route::post('/student/schedule-recommendations', ScheduleRecommendationController::class)
            ->middleware('throttle:student-tutor-availability');
        Route::post('/student/bookings/{booking}/approve', [SessionWorkflowController::class, 'studentApprove']);
        Route::post('/student/bookings/{booking}/dispute', [SessionWorkflowController::class, 'studentDispute']);
        Route::post('/student/bookings/{booking}/teacher-absence', [SessionWorkflowController::class, 'reportTeacherAbsence']);
        Route::post('/student/bookings/{booking}/presence-confirm', [LearningSessionController::class, 'studentConfirmPresence'])
            ->middleware('throttle:student-session-presence-confirm');

        Route::get('/student/orders/{id}/status', [StudentController::class, 'checkOrderStatus']);
        Route::get('/student/wallet', [CustomerWalletController::class, 'show']);
        Route::get('/student/payment-pin/status', [PaymentPinController::class, 'status']);
        Route::post('/student/payment-pin', [PaymentPinController::class, 'set'])->middleware('throttle:student-payment-pin-set');
        Route::post('/student/payment-pin/forgot', [PaymentPinController::class, 'forgot'])->middleware('throttle:student-payment-pin-forgot');
        Route::post('/student/payment-pin/forgot-email', [PaymentPinController::class, 'forgotEmail'])->middleware('throttle:student-payment-pin-forgot-email');
        Route::post('/student/payment-pin/reset/request', [PaymentPinController::class, 'requestReset'])->middleware('throttle:student-payment-pin-reset-request');
        Route::post('/student/payment-pin/reset', [PaymentPinController::class, 'reset'])->middleware('throttle:student-payment-pin-reset');
        Route::post('/student/refunds/{refund}/destination', [StudentRefundController::class, 'selectDestination'])
            ->middleware(['throttle:student-refund-destination', 'idempotency', 'finance.audit:refund_destination_select']);
        Route::post('/student/refunds/{refund}/sync', [StudentRefundController::class, 'sync'])
            ->middleware('throttle:student-refund-sync');
        Route::get('/student/orders/{order}/wallet-quote', [CustomerWalletController::class, 'quote']);
        Route::get('/student/classes', [StudentController::class, 'getMyClasses']);
        Route::get('/student/class-joins', [PrivateClassJoinController::class, 'mine']);
        Route::post('/student/class-joins', [PrivateClassJoinController::class, 'requestJoin'])->middleware('throttle:student-class-join-request');
        Route::get('/student/packages/{learningPackage}/class-joins', [PrivateClassJoinController::class, 'ownerIndex']);
        Route::post('/student/packages/{learningPackage}/class-code', [PrivateClassJoinController::class, 'createCode'])->middleware('throttle:student-class-code-create');
        Route::post('/student/packages/{learningPackage}/class-joins/{classJoin}/decision', [PrivateClassJoinController::class, 'decide'])->middleware('throttle:student-class-join-decision');
        Route::post('/orders/{id}/pay', [OrderController::class, 'pay'])
            ->middleware(['throttle:student-payment-submit', 'idempotency', 'finance.audit:student_payment_submit']);
        Route::post('/orders/{order}/xendit-session', [XenditPaymentController::class, 'createSession'])
            ->middleware(['throttle:student-payment-submit', 'idempotency', 'finance.audit:student_payment_submit']);
        Route::post('/orders/{order}/payment-status-sync', [XenditPaymentController::class, 'sync'])
            ->middleware('throttle:student-payment-status-sync');
        Route::get('/active-order', [OrderController::class, 'getActiveOrder']);
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancelOrder']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/payment-settings', [AdminController::class, 'getPaymentSettings']);
        Route::post('/ratings', [RatingController::class, 'store']);
        Route::get('/student/dashboard-v2', [StudentPackageController::class, 'dashboard']);
        Route::get('/student/packages', [StudentPackageController::class, 'index']);
        Route::get('/student/packages/tutorial-status', [StudentPackageController::class, 'tutorialStatus']);
        Route::post('/student/packages', [StudentPackageController::class, 'store'])
            ->middleware(['throttle:student-package-create', 'idempotency']);
        Route::post('/student/packages/{learningPackage}/retry', [StudentPackageController::class, 'retryMatching'])
            ->middleware('throttle:student-package-retry');
        Route::post('/student/packages/{learningPackage}/reschedule', [StudentPackageController::class, 'reschedule'])
            ->middleware('throttle:student-package-reschedule');
        Route::post('/student/packages/{learningPackage}/cancel', [StudentPackageController::class, 'cancel']);
        Route::post('/student/packages/{learningPackage}/subjects/{packageSubject}/teacher-replacements', [TeacherReplacementController::class, 'store'])
            ->middleware(['feature:teacher_replacement', 'throttle:student-teacher-replacement', 'idempotency']);
        Route::get('/student/teacher-replacements/{teacherReplacement}', [TeacherReplacementController::class, 'show'])
            ->middleware('feature:teacher_replacement');
        Route::post('/student/teacher-replacements/{teacherReplacement}/cancel', [TeacherReplacementController::class, 'cancel'])
            ->middleware(['feature:teacher_replacement', 'throttle:student-teacher-replacement-action', 'idempotency']);
        Route::post('/student/teacher-replacements/{teacherReplacement}/retry', [TeacherReplacementController::class, 'retry'])
            ->middleware(['feature:teacher_replacement', 'throttle:student-teacher-replacement-action', 'idempotency']);
        Route::post('/student/teacher-replacements/{teacherReplacement}/reschedule', [TeacherReplacementController::class, 'reschedule'])
            ->middleware(['feature:teacher_replacement', 'throttle:student-teacher-replacement-action', 'idempotency']);
        Route::post('/student/teacher-replacements/{teacherReplacement}/request-refund', [TeacherReplacementController::class, 'requestRefund'])
            ->middleware(['feature:teacher_replacement', 'throttle:student-teacher-replacement-action', 'idempotency']);
        Route::get('/student/packages/{learningPackage}', [StudentPackageController::class, 'show']);
        Route::get('/student/vouchers', [StudentPackageController::class, 'vouchers']);
        Route::post('/student/promotions/preview', [StudentPackageController::class, 'previewPromotion'])
            ->middleware('throttle:student-promotion-preview');
        Route::post('/student/promotions/{promotion}/claim', [StudentPackageController::class, 'claim'])
            ->middleware('throttle:student-promotion-claim');
        Route::post('/student/packages/quote', [StudentPackageController::class, 'previewPromotion'])
            ->middleware('throttle:student-package-quote');
    });

    Route::prefix('teacher')->middleware('role:teacher')->group(function () {
        Route::get('/cheap-classes', [TeacherCheapClassController::class, 'index']);
        Route::put('/cheap-classes/{cheapClass}/meeting-link', [TeacherCheapClassController::class, 'updateMeetingLink'])
            ->middleware('throttle:teacher-cheap-class-meeting-link');
        Route::post('/cheap-classes/{cheapClass}/start-session', [TeacherCheapClassController::class, 'startSession'])
            ->middleware('throttle:teacher-cheap-class-start-session');
        Route::put('/cheap-classes/{cheapClass}/progress', [TeacherCheapClassController::class, 'updateProgress'])
            ->middleware('throttle:teacher-cheap-class-progress');
        Route::get('/offers', [TeacherOfferController::class, 'index']);
        Route::post('/offers/{teacherOffer}/accept', [TeacherOfferController::class, 'accept'])
            ->middleware('throttle:teacher-offer-action');
        Route::post('/offers/{teacherOffer}/reject', [TeacherOfferController::class, 'reject'])
            ->middleware('throttle:teacher-offer-action');

        Route::get('/dashboard-v2', [TeacherOperationsController::class, 'dashboard']);
        Route::get('/profile', [TeacherController::class, 'getProfile']);
        Route::post('/profile', [TeacherController::class, 'updateProfile']);
        Route::post('/profile/cover', [TeacherController::class, 'updateProfileCover']);
        Route::post('/subjects', [TeacherController::class, 'syncSubjects']);
        Route::post('/bank', [TeacherController::class, 'updateBank'])
            ->middleware(['throttle:teacher-bank-change', 'idempotency', 'finance.audit:teacher_bank_change']);
        Route::get('/salary', [TeacherController::class, 'getSalaryData']);
        Route::get('/payout-requests', [TeacherOperationsController::class, 'payoutRequests']);
        Route::post('/payout-requests', [TeacherOperationsController::class, 'requestPayout'])
            ->middleware(['throttle:teacher-payout-request', 'idempotency', 'finance.audit:teacher_payout_request']);
        Route::get('/performance', [TeacherOperationsController::class, 'performance']);
        Route::get('/classes', [ClassroomController::class, 'index']);
        Route::get('/package-subjects/{packageSubject}/progress', [ClassroomController::class, 'packageSubjectProgress']);
        Route::put('/classes/{id}', [ClassroomController::class, 'update']);
        Route::post('/bookings/{booking}/absence', [SessionWorkflowController::class, 'reportStudentAbsence']);
        Route::post('/bookings/{booking}/emergency', [SessionWorkflowController::class, 'reportEmergency']);
        Route::post('/bookings/{booking}/ready', [LearningSessionController::class, 'teacherReady'])
            ->middleware('throttle:teacher-session-ready');
        Route::post('/bookings/{booking}/check-out', [LearningSessionController::class, 'teacherCheckOut'])
            ->middleware('throttle:teacher-session-checkout');
        Route::post('/bookings/{booking}/progress-reports', [LearningSessionController::class, 'storeProgressReport']);
        Route::get('/schedule', [TeacherScheduleController::class, 'index']);
        Route::post('/schedule', [TeacherScheduleController::class, 'update']);
        Route::get('/schedule-exceptions', [TeacherScheduleController::class, 'exceptions']);
        Route::post('/schedule-exceptions', [TeacherScheduleController::class, 'storeException']);
        Route::delete('/schedule-exceptions/{teacherAvailabilityException}', [TeacherScheduleController::class, 'destroyException']);
    });

    Route::prefix('admin')->middleware(['role:admin', 'admin.audit', 'admin.permission'])->group(function () {
        Route::get('/website-settings', [WebsiteContentController::class, 'adminShow']);
        Route::post('/website-settings', [WebsiteContentController::class, 'update']);
        Route::get('/website-media', [WebsiteContentController::class, 'media']);
        Route::post('/website-media', [WebsiteContentController::class, 'updateMedia']);
        Route::get('/cheap-class-templates/form', [AdminCheapClassController::class, 'form']);
        Route::get('/cheap-class-templates/recurring', [AdminCheapClassController::class, 'recurringTemplates']);
        Route::get('/cheap-class-templates', [AdminCheapClassController::class, 'index']);
        Route::post('/cheap-class-templates', [AdminCheapClassController::class, 'store'])
            ->middleware(['throttle:admin-cheap-class-template-create', 'idempotency']);
        Route::patch('/cheap-class-templates/{cheapClassTemplate}/recurrence', [AdminCheapClassController::class, 'updateRecurrence'])
            ->middleware(['throttle:admin-cheap-class-template-recurrence', 'idempotency']);
        Route::get('/cheap-classes/schedule', [AdminCheapClassController::class, 'schedule']);
        Route::post('/cheap-classes/{cheapClass}/cancel', [AdminCheapClassController::class, 'cancel'])
            ->middleware(['idempotency', 'finance.audit:cheap_class_cancel']);
        Route::delete('/cheap-classes/{cheapClass}', [AdminCheapClassController::class, 'destroy']);
        Route::post('/cheap-classes/{cheapClass}/retry-teacher', [AdminCheapClassController::class, 'retryTeacher'])
            ->middleware('throttle:admin-cheap-class-retry-teacher');
        Route::post('/cheap-classes/{cheapClass}/finalize', [AdminCheapClassController::class, 'finalize']);
        Route::post('/cheap-classes/{cheapClass}/sessions/{session}/verify', [AdminCheapClassController::class, 'verifySessionReport'])
            ->middleware(['throttle:admin-cheap-class-session-review', 'idempotency']);
        Route::post('/cheap-classes/{cheapClass}/sessions/{session}/request-revision', [AdminCheapClassController::class, 'requestSessionReportRevision'])
            ->middleware(['throttle:admin-cheap-class-session-review', 'idempotency']);
        Route::get('/accounts', [AdminAccountController::class, 'index']);
        Route::post('/accounts', [AdminAccountController::class, 'store']);
        Route::put('/accounts/{admin}', [AdminAccountController::class, 'update']);
        Route::delete('/accounts/{admin}', [AdminAccountController::class, 'destroy']);
        Route::patch('/accounts/{admin}/status', [AdminAccountController::class, 'updateStatus']);
        Route::get('/audit-log', [AdminAccessController::class, 'audit']);
        Route::get('/audit-log/integrity', [AdminAccessController::class, 'auditIntegrity']);
        Route::get('/audit-log/{adminAuditLog}', [AdminAccessController::class, 'auditDetail'])
            ->whereNumber('adminAuditLog');

        Route::get('/notifications/recipients', [NotificationController::class, 'recipients']);
        Route::post('/notifications/send', [NotificationController::class, 'send']);

        Route::get('/private-participant-pricing', [PrivateParticipantPricingController::class, 'adminShow']);
        Route::put('/private-participant-pricing', [PrivateParticipantPricingController::class, 'update']);
        Route::get('/hourly-rates', [HourlyRateController::class, 'index']);
        Route::post('/hourly-rates', [HourlyRateController::class, 'store']);
        Route::post('/hourly-rates/defaults', [HourlyRateController::class, 'updateDefaults']);
        Route::delete('/hourly-rates/{hourlyRate}', [HourlyRateController::class, 'destroy']);

        Route::get('/pending-teachers', [AdminController::class, 'getPendingTeachers']);
        Route::get('/public-tutors', [AdminPublicTutorController::class, 'index']);
        Route::get('/website-tutor-gallery', [WebsiteTutorGalleryController::class, 'adminIndex']);
        Route::get('/website-tutor-gallery/candidates', [WebsiteTutorGalleryController::class, 'candidates']);
        Route::post('/website-tutor-gallery/import-legacy', [WebsiteTutorGalleryController::class, 'importLegacy']);
        Route::post('/website-tutor-gallery', [WebsiteTutorGalleryController::class, 'store']);
        Route::post('/website-tutor-gallery/{entry}', [WebsiteTutorGalleryController::class, 'update'])->whereNumber('entry');
        Route::delete('/website-tutor-gallery/{entry}', [WebsiteTutorGalleryController::class, 'destroy'])->whereNumber('entry');
        Route::patch('/public-tutors/{teacherProfile}', [AdminPublicTutorController::class, 'update'])->whereNumber('teacherProfile');
        Route::post('/public-tutors/{teacherProfile}/photo', [AdminPublicTutorController::class, 'updatePhoto'])->whereNumber('teacherProfile');
        Route::patch('/public-tutors/{teacherProfile}/identity', [AdminPublicTutorController::class, 'updateIdentity'])->whereNumber('teacherProfile');
        Route::get('/history-teachers', [AdminController::class, 'getHistoryTeachers']);
        Route::post('/verify-teacher', [AdminController::class, 'verifyTeacher']);
        Route::patch('/teachers/{teacher}/verification-stage', [AdminController::class, 'updateTeacherVerificationStage']);
        Route::get('/users', [AdminController::class, 'getUsers']);
        Route::post('/users/{id}/profile-cover', [AdminController::class, 'updateTeacherProfileCover'])->whereNumber('id');
        Route::delete('/users/{id}/profile-cover', [AdminController::class, 'resetTeacherProfileCover'])->whereNumber('id');
        Route::post('/users/{id}/profile-cover/restore', [AdminController::class, 'restoreTeacherProfileCover'])->whereNumber('id');
        Route::get('/teachers/{teacher}/schedule', [AdminTeacherScheduleController::class, 'show']);
        Route::post('/users/status', [AdminController::class, 'updateUserStatus']);
        Route::get('/orders', [AdminController::class, 'getOrders']);
        Route::get('/pending-payments', [AdminController::class, 'getPendingPayments']);
        Route::get('/finance/payments', [AdminFinanceOperationsController::class, 'payments']);
        Route::get('/finance/report', [AdminFinanceReportController::class, 'index']);
        Route::get('/finance/report.pdf', [AdminFinanceReportController::class, 'pdf']);
        Route::get('/finance/refunds', [AdminFinanceOperationsController::class, 'refunds']);
        Route::post('/verify-payment', [AdminController::class, 'verifyPayment'])
            ->middleware(['idempotency', 'finance.audit:payment_verification']);

        Route::get('/finance', [AdminController::class, 'getFinanceData']);

        Route::get('/commission-setting', [AdminController::class, 'getCommissionSetting']);
        Route::post('/commission-setting', [AdminController::class, 'updateCommissionSetting'])
            ->middleware(['idempotency', 'finance.audit:commission_change']);
        Route::get('/dashboard-stats', [AdminController::class, 'getDashboardStats']);
        Route::get('/tutor-searches', [AdminMatchingController::class, 'index']);
        Route::get('/tutor-searches/{bookingRequest}/candidates', [AdminMatchingController::class, 'candidates']);
        Route::get('/tutor-searches/{bookingRequest}', [AdminMatchingController::class, 'show']);
        Route::post('/tutor-searches/{bookingRequest}/synchronize', [AdminMatchingController::class, 'synchronize'])
            ->middleware('throttle:admin-matching-synchronize');
        Route::post('/tutor-searches/{bookingRequest}/expand-radius', [AdminMatchingController::class, 'expandRadius'])
            ->middleware('throttle:admin-matching-expand-radius');
        Route::post('/tutor-searches/{bookingRequest}/assign-teacher', [AdminMatchingController::class, 'assignTeacher'])
            ->middleware('throttle:admin-matching-assign-teacher');
        Route::get('/cases', [SessionWorkflowController::class, 'adminCases']);
        Route::get('/teacher-replacements', [TeacherReplacementController::class, 'adminIndex'])
            ->middleware('feature:teacher_replacement');
        Route::get('/teacher-replacements/{teacherReplacement}', [TeacherReplacementController::class, 'adminShow'])
            ->middleware('feature:teacher_replacement');
        Route::post('/teacher-replacements/{teacherReplacement}/approve', [TeacherReplacementController::class, 'approve'])
            ->middleware(['feature:teacher_replacement', 'throttle:admin-teacher-replacement-review', 'idempotency']);
        Route::post('/teacher-replacements/{teacherReplacement}/reject', [TeacherReplacementController::class, 'reject'])
            ->middleware(['feature:teacher_replacement', 'throttle:admin-teacher-replacement-review', 'idempotency']);
        Route::post('/disputes/{bookingDispute}/resolve', [SessionWorkflowController::class, 'resolveDispute']);
        Route::post('/session-reports/{sessionReport}/resolve', [SessionWorkflowController::class, 'resolveReport']);
        Route::post('/bookings/{booking}/completion-review', [SessionWorkflowController::class, 'resolveCompletionReview']);
        Route::post('/refunds/{refund}/complete', [SessionWorkflowController::class, 'completeRefund'])
            ->middleware(['idempotency', 'finance.audit:refund_complete']);
        Route::post('/refunds/{refund}/reconcile', [AdminFinanceOperationsController::class, 'reconcileRefund'])
            ->middleware(['throttle:admin-refund-reconcile', 'finance.audit:refund_reconcile']);
        Route::post('/refunds/{refund}/retry', [AdminFinanceOperationsController::class, 'retryRefund'])
            ->middleware(['throttle:admin-refund-retry', 'idempotency', 'finance.audit:refund_retry']);

        Route::post('/settings/footer', [AdminController::class, 'updateFooterSettings']);
        Route::get('/admin-socials', [AdminController::class, 'getAdminSocials']);
        Route::post('/socials', [AdminController::class, 'storeSocial']);
        Route::put('/socials/{id}', [AdminController::class, 'updateSocial']);
        Route::post('/socials/reorder', [AdminController::class, 'reorderSocials']);
        Route::delete('/socials/{id}', [AdminController::class, 'deleteSocial']);
        Route::get('/classes', [AdminClassController::class, 'index']);
        Route::get('/classes/{id}', [AdminClassController::class, 'show']);
        Route::post('/settings/teacher-cover', [AdminSettingController::class, 'updateTeacherCover']);
        Route::get('/settings/teacher-cover/status', [AdminSettingController::class, 'teacherCoverStatus']);
        Route::post('/settings/teacher-cover/apply-default', [AdminSettingController::class, 'applyDefaultTeacherCover']);
        Route::get('/ratings', [AdminRatingController::class, 'index']);
        Route::delete('/ratings/{id}', [AdminRatingController::class, 'destroy']);

        Route::get('/notes', [NoteController::class, 'index']);
        Route::post('/notes', [NoteController::class, 'store']);
        Route::put('/notes/{id}', [NoteController::class, 'update']);
        Route::delete('/notes/{id}', [NoteController::class, 'destroy']);
        Route::get('/program-groups', [ProgramController::class, 'adminIndex']);
        Route::get('/learning-programs', [LearningProgramController::class, 'adminIndex']);
        Route::post('/learning-programs', [LearningProgramController::class, 'store']);
        Route::put('/learning-programs/{learningProgram}', [LearningProgramController::class, 'update']);
        Route::delete('/learning-programs/{learningProgram}', [LearningProgramController::class, 'deactivate']);
        Route::post('/program-groups', [ProgramController::class, 'store']);
        Route::put('/program-groups/{program}', [ProgramController::class, 'update']);
        Route::delete('/program-groups/{program}', [ProgramController::class, 'deactivate']);
        Route::get('/subjects/{curriculumSubject}/page', [SubjectPageContentController::class, 'adminShow']);
        Route::put('/subjects/{curriculumSubject}/page', [SubjectPageContentController::class, 'update']);
        Route::post('/subjects/{curriculumSubject}/page/hero', [SubjectPageContentController::class, 'updateHero']);
        Route::delete('/subjects/{curriculumSubject}/page/hero', [SubjectPageContentController::class, 'removeHero']);
        Route::get('/subject-groups', [CurriculumSubjectGroupController::class, 'index']);
        Route::post('/subject-groups', [CurriculumSubjectGroupController::class, 'store']);
        Route::put('/subject-groups/{id}', [CurriculumSubjectGroupController::class, 'update'])->whereNumber('id');
        Route::delete('/subject-groups/{id}', [CurriculumSubjectGroupController::class, 'destroy'])->whereNumber('id');
        Route::get('/subjects', [CurriculumSubjectController::class, 'index']);
        Route::post('/subjects', [CurriculumSubjectController::class, 'store']);
        Route::put('/subjects/{curriculumSubject}', [CurriculumSubjectController::class, 'update']);
        Route::delete('/subjects/{curriculumSubject}', [CurriculumSubjectController::class, 'destroy']);
        Route::get('/chapters', [CurriculumChapterController::class, 'index']);
        Route::post('/chapters', [CurriculumChapterController::class, 'store']);
        Route::put('/chapters/{curriculumChapter}', [CurriculumChapterController::class, 'update']);
        Route::delete('/chapters/{curriculumChapter}', [CurriculumChapterController::class, 'destroy']);

        Route::get('/stage-five/plans', [AdminStageFiveController::class, 'plans']);
        Route::post('/stage-five/plans', [AdminStageFiveController::class, 'storePlan']);
        Route::put('/stage-five/plans/{packagePlan}', [AdminStageFiveController::class, 'updatePlan']);
        Route::delete('/stage-five/plans/{packagePlan}', [AdminStageFiveController::class, 'deletePlan']);
        Route::get('/stage-five/time-slots', [AdminStageFiveController::class, 'timeSlots']);
        Route::post('/stage-five/time-slots', [AdminStageFiveController::class, 'storeTimeSlot']);
        Route::put('/stage-five/time-slots/{learningTimeSlot}', [AdminStageFiveController::class, 'updateTimeSlot']);
        Route::delete('/stage-five/time-slots/{learningTimeSlot}', [AdminStageFiveController::class, 'deleteTimeSlot']);
        Route::get('/stage-five/promotions', [AdminStageFiveController::class, 'promotions']);
        Route::post('/stage-five/promotions', [AdminStageFiveController::class, 'storePromotion']);
        Route::put('/stage-five/promotions/{promotion}', [AdminStageFiveController::class, 'updatePromotion']);
        Route::delete('/stage-five/promotions/{promotion}', [AdminStageFiveController::class, 'deletePromotion']);
        Route::get('/stage-five/banners', [AdminStageFiveController::class, 'banners']);
        Route::post('/stage-five/banners', [AdminStageFiveController::class, 'storeBanner']);
        Route::post('/stage-five/banners/{dynamicBanner}', [AdminStageFiveController::class, 'updateBanner']);
        Route::delete('/stage-five/banners/{dynamicBanner}', [AdminStageFiveController::class, 'deleteBanner']);
        Route::get('/stage-five/tutorials', [AdminStageFiveController::class, 'tutorials']);
        Route::post('/stage-five/tutorials', [AdminStageFiveController::class, 'storeTutorial']);
        Route::put('/stage-five/tutorials/{tutorial}', [AdminStageFiveController::class, 'updateTutorial']);
        Route::delete('/stage-five/tutorials/{tutorial}', [AdminStageFiveController::class, 'deleteTutorial']);
        Route::post('/stage-five/tutorial-steps/{tutorialStep}/image', [AdminStageFiveController::class, 'uploadTutorialStepImage']);
    });
});
