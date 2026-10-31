<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingsController;
use App\Http\Controllers\Api\ChatEventsController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\EnquiriesController;
use App\Http\Controllers\Api\GeographyController;
use App\Http\Controllers\Api\I18nController;
use App\Http\Controllers\Api\MessagesController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ProfilesController;
use App\Http\Controllers\Api\SafetyReportsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SocketIoController;
use App\Http\Controllers\Api\StudentProfilesController;
use App\Http\Controllers\Api\SubjectsController;
use App\Http\Middleware\AttachOptionalApiUser;
use App\Http\Middleware\RequireAdminApi;
use App\Http\Middleware\RequireApiAuthentication;
use App\Http\Middleware\ThrottlePresence;
use App\Support\RecordId;
use Illuminate\Support\Facades\Route;

Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])
    ->middleware('throttle:20,1');
Route::post('/auth/otp/verify', [AuthController::class, 'verifyOtp'])
    ->middleware('throttle:30,1');
Route::post('/auth/google', [AuthController::class, 'googleSignIn'])
    ->middleware('throttle:20,1');
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [PasswordRecoveryController::class, 'forgot'])->middleware('throttle:email-code');
Route::post('/auth/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:20,1');
Route::post('/auth/parental-consent/confirm', [AuthController::class, 'confirmParentalConsent'])->middleware('throttle:20,1');
Route::get('/auth/safety-disclaimer', [AuthController::class, 'safetyDisclaimer']);
Route::get('/i18n/languages', [I18nController::class, 'languages']);
Route::get('/i18n/{lang}', [I18nController::class, 'translations']);
Route::get('/subjects', SubjectsController::class);
Route::prefix('geo')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/countries', [GeographyController::class, 'countries']);
    Route::get('/states', [GeographyController::class, 'states']);
    Route::get('/cities', [GeographyController::class, 'cities']);
});
Route::post('/analytics/page-view', [OperationsController::class, 'recordPageView'])->middleware('throttle:120,1');
Route::get('/reviews/for/{providerId}', [CommunityController::class, 'providerReviews'])->where('providerId', RecordId::ROUTE_PATTERN);
Route::middleware(AttachOptionalApiUser::class)
    ->get('/feed/{postId}/comments', [CommunityController::class, 'comments'])
    ->where('postId', RecordId::ROUTE_PATTERN);
Route::middleware(AttachOptionalApiUser::class)->get('/feed', [CommunityController::class, 'feed']);
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/search/dashboard', [SearchController::class, 'dashboard']);
    Route::get('/search/counts', [SearchController::class, 'counts']);
    Route::get('/search/other-city', [SearchController::class, 'otherCity']);
    Route::get('/search/by-subject/{subjectId}', [SearchController::class, 'bySubject'])->where('subjectId', RecordId::ROUTE_PATTERN);
    Route::get('/search', [SearchController::class, 'nearby']);
});

Route::middleware(AttachOptionalApiUser::class)
    ->get('/profiles/{type}/{id}', [ProfilesController::class, 'show'])
    ->whereIn('type', ['teacher', 'institute'])
    ->where('id', RecordId::ROUTE_PATTERN)
    ->middleware('throttle:60,1');

Route::middleware(RequireApiAuthentication::class)->group(function () {
    Route::post('/presence/update', PresenceController::class)->middleware(ThrottlePresence::class);
    Route::get('/auth/session', [AuthController::class, 'session']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/email/verification/request', [PasswordRecoveryController::class, 'requestVerification'])->middleware('throttle:email-code');
    Route::post('/auth/email/verification/verify', [PasswordRecoveryController::class, 'verifyEmail'])->middleware('throttle:20,1');
    Route::post('/auth/register', [OnboardingController::class, 'complete'])->middleware('throttle:10,1');
    Route::get('/auth/onboarding', [OnboardingController::class, 'show']);
    Route::patch('/auth/onboarding', [OnboardingController::class, 'autosave']);
    Route::post('/auth/onboarding/complete', [OnboardingController::class, 'complete']);
    Route::post('/auth/account-type', [AuthController::class, 'setAccountType']);
    Route::patch('/auth/preferred-language', [AuthController::class, 'setPreferredLanguage']);
    Route::patch('/auth/avatar', [AuthController::class, 'setAvatar']);
    Route::patch('/auth/link-phone', [AuthController::class, 'linkPhone']);
    Route::patch('/auth/link-google', [AuthController::class, 'linkGoogle']);
    Route::post('/auth/date-of-birth', [AuthController::class, 'setDateOfBirth']);
    Route::get('/auth/parental-consent/status', [AuthController::class, 'parentalConsentStatus'])->middleware('throttle:60,1');
    Route::post('/auth/guardian-details', [AuthController::class, 'setGuardianDetails'])->middleware('throttle:10,1');
    Route::post('/auth/acknowledge-safety-disclaimer', [AuthController::class, 'acknowledgeSafetyDisclaimer']);
    Route::get('/student/profile', [StudentProfilesController::class, 'show']);
    Route::patch('/student/profile', [StudentProfilesController::class, 'update']);
    Route::post('/student/profile/photo', [StudentProfilesController::class, 'uploadPhoto'])->middleware('throttle:10,1');
    Route::put('/profiles/me', [ProfilesController::class, 'updateMine']);
    Route::prefix('profiles/me')->group(function (): void {
        Route::get('/', [ProfilesController::class, 'showProvider']);
        Route::patch('/steps/{step}', [ProfilesController::class, 'updateStep'])->whereIn('step', ['1', '2', '3', '4', '5']);
        Route::post('/publish', [ProfilesController::class, 'publishProfile']);
        Route::post('/photo', [ProfilesController::class, 'uploadPhoto'])->middleware('throttle:10,1');
    });
    Route::prefix('teacher/profile')->group(function (): void {
        Route::get('/', [ProfilesController::class, 'showMine']);
        Route::patch('/steps/{step}', [ProfilesController::class, 'updateTeacherStep'])->whereIn('step', ['1', '2', '3', '4', '5']);
        Route::post('/publish', [ProfilesController::class, 'publishTeacherProfile']);
        Route::post('/photo', [ProfilesController::class, 'uploadTeacherPhoto'])->middleware('throttle:10,1');
    });
    Route::get('/profiles/me/stats', [ProfilesController::class, 'stats']);

    Route::post('/bookings', [BookingsController::class, 'create']);
    Route::get('/bookings/incoming', [BookingsController::class, 'incoming']);
    Route::get('/bookings/mine', [BookingsController::class, 'mine']);
    Route::patch('/bookings/{id}/status', [BookingsController::class, 'updateStatus'])->where('id', RecordId::ROUTE_PATTERN);
    Route::get('/messages', [MessagesController::class, 'index']);
    foreach (['send', 'delivered', 'read', 'typing', 'stop_typing', 'delete', 'edit', 'reply', 'reaction', 'history', 'unread_count'] as $chatAction) {
        Route::post('/chat/events/'.$chatAction, [ChatEventsController::class, 'dispatch'])
            ->defaults('event', 'chat:'.$chatAction)->middleware('throttle:chat-send');
    }
    Route::post('/chat/socket/authorize', [SocketIoController::class, 'authorizeSocket'])->middleware('throttle:socket-auth');
    Route::post('/feed/socket/authorize', [SocketIoController::class, 'authorizeFeed'])->middleware('throttle:socket-auth');
    Route::post('/messages/start', [MessagesController::class, 'start']);
    Route::get('/messages/{conversationId}', [MessagesController::class, 'show'])->where('conversationId', RecordId::ROUTE_PATTERN);
    Route::post('/messages/{conversationId}', [MessagesController::class, 'send'])->where('conversationId', RecordId::ROUTE_PATTERN)->middleware('throttle:chat-send');
    Route::post('/reviews', [CommunityController::class, 'createReview']);
    Route::post('/reports', [SafetyReportsController::class, 'store'])->middleware('throttle:10,1');

    // Feed / Posts: shared by learner, teacher and institute accounts.
    // The developer catalog and Postman list these under each role's category.
    Route::post('/feed', [CommunityController::class, 'createPost']);
    Route::post('/feed/{postId}/like', [CommunityController::class, 'like'])->where('postId', RecordId::ROUTE_PATTERN);
    Route::delete('/feed/{postId}/like', [CommunityController::class, 'unlike'])->where('postId', RecordId::ROUTE_PATTERN);
    Route::post('/feed/{postId}/comments', [CommunityController::class, 'createComment'])->where('postId', RecordId::ROUTE_PATTERN);
    Route::post('/feed/comments/{commentId}/like', [CommunityController::class, 'likeComment'])->where('commentId', RecordId::ROUTE_PATTERN);
    Route::delete('/feed/comments/{commentId}/like', [CommunityController::class, 'unlikeComment'])->where('commentId', RecordId::ROUTE_PATTERN);

    Route::post('/safety/reports', [OperationsController::class, 'report'])->middleware('throttle:10,1');
    Route::post('/safety/blocks/{userId}', [OperationsController::class, 'block'])->where('userId', RecordId::ROUTE_PATTERN);
    Route::delete('/safety/blocks/{userId}', [OperationsController::class, 'unblock'])->where('userId', RecordId::ROUTE_PATTERN);
    Route::post('/suggestions', [OperationsController::class, 'suggest']);
    Route::post('/support', [OperationsController::class, 'createTicket']);
    Route::post('/franchise/enquiries', [EnquiriesController::class, 'franchise'])->middleware('throttle:enquiries');
    Route::post('/jobs/applications', [EnquiriesController::class, 'teachingJob'])->middleware('throttle:enquiries');
    Route::get('/support/mine', [OperationsController::class, 'mineTickets']);
    Route::get('/referrals/mine', [OperationsController::class, 'referralMine']);
    Route::post('/referrals/redeem', [OperationsController::class, 'redeemReferral']);

    Route::middleware(RequireAdminApi::class)->group(function () {
        Route::patch('/profiles/{type}/{id}/verify', [ProfilesController::class, 'verify'])
            ->whereIn('type', ['teacher', 'institute'])
            ->where('id', RecordId::ROUTE_PATTERN);
        Route::patch('/profiles/institute/{id}/type', [ProfilesController::class, 'classifyInstitute'])->where('id', RecordId::ROUTE_PATTERN);
        Route::patch('/profiles/institute/{id}/bsl-verified', [ProfilesController::class, 'setBslVerified'])->where('id', RecordId::ROUTE_PATTERN);
        Route::get('/safety/reports', [OperationsController::class, 'reports']);
        Route::patch('/safety/reports/{id}/status', [OperationsController::class, 'updateReport'])->where('id', RecordId::ROUTE_PATTERN);
        Route::get('/suggestions', [OperationsController::class, 'suggestions']);
        Route::patch('/suggestions/{id}/status', [OperationsController::class, 'updateSuggestion'])->where('id', RecordId::ROUTE_PATTERN);
        Route::get('/support', [OperationsController::class, 'tickets']);
        Route::patch('/support/{id}/status', [OperationsController::class, 'updateTicket'])->where('id', RecordId::ROUTE_PATTERN);
        Route::get('/referrals/top', [OperationsController::class, 'topReferrals']);
        Route::get('/analytics/summary', [OperationsController::class, 'analyticsSummary']);

        Route::get('/moderation/photos/pending', [ModerationController::class, 'pendingPhotos']);
        Route::patch('/moderation/photos/{userId}', [ModerationController::class, 'reviewPhoto'])->where('userId', RecordId::ROUTE_PATTERN);
        Route::get('/moderation/conversations', [ModerationController::class, 'conversations']);
        Route::get('/moderation/conversations/{id}/messages', [ModerationController::class, 'conversationMessages'])->where('id', RecordId::ROUTE_PATTERN);
        Route::get('/moderation/bookings', [ModerationController::class, 'bookings']);
        Route::get('/moderation/contact-stats', [ModerationController::class, 'contactStats']);
        Route::post('/moderation/users/{userId}/suspend', [ModerationController::class, 'suspend'])->where('userId', RecordId::ROUTE_PATTERN);
        Route::post('/moderation/users/{userId}/unsuspend', [ModerationController::class, 'unsuspend'])->where('userId', RecordId::ROUTE_PATTERN);
        Route::get('/moderation/users/suspended', [ModerationController::class, 'suspendedUsers']);
    });
});
