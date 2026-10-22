<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingsController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\I18nController;
use App\Http\Controllers\Api\MessagesController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\PasswordRecoveryController;
use App\Http\Controllers\Api\ProfilesController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SocketIoController;
use App\Http\Controllers\Api\SubjectsController;
use App\Http\Middleware\AttachOptionalApiUser;
use App\Http\Middleware\RequireAdminApi;
use App\Http\Middleware\RequireApiAuthentication;
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
Route::post('/auth/parental-consent/confirm', [AuthController::class, 'confirmParentalConsent']);
Route::get('/auth/safety-disclaimer', [AuthController::class, 'safetyDisclaimer']);
Route::get('/i18n/languages', [I18nController::class, 'languages']);
Route::get('/i18n/{lang}', [I18nController::class, 'translations']);
Route::get('/subjects', SubjectsController::class);
Route::post('/analytics/page-view', [OperationsController::class, 'recordPageView'])->middleware('throttle:120,1');
Route::get('/reviews/for/{providerId}', [CommunityController::class, 'providerReviews'])->whereUuid('providerId');
Route::get('/feed/{postId}/comments', [CommunityController::class, 'comments'])->whereUuid('postId');
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/search/dashboard', [SearchController::class, 'dashboard']);
    Route::get('/search/counts', [SearchController::class, 'counts']);
    Route::get('/search/other-city', [SearchController::class, 'otherCity']);
    Route::get('/search/by-subject/{subjectId}', [SearchController::class, 'bySubject'])->whereNumber('subjectId');
    Route::get('/search', [SearchController::class, 'nearby']);
});

Route::middleware(AttachOptionalApiUser::class)
    ->get('/profiles/{type}/{id}', [ProfilesController::class, 'show'])
    ->whereIn('type', ['teacher', 'institute'])
    ->whereUuid('id')
    ->middleware('throttle:60,1');

Route::middleware(RequireApiAuthentication::class)->group(function () {
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
    Route::post('/auth/acknowledge-safety-disclaimer', [AuthController::class, 'acknowledgeSafetyDisclaimer']);
    Route::put('/profiles/me', [ProfilesController::class, 'updateMine']);
    Route::get('/profiles/me/stats', [ProfilesController::class, 'stats']);

    Route::post('/bookings', [BookingsController::class, 'create']);
    Route::get('/bookings/incoming', [BookingsController::class, 'incoming']);
    Route::get('/bookings/mine', [BookingsController::class, 'mine']);
    Route::patch('/bookings/{id}/status', [BookingsController::class, 'updateStatus'])->whereUuid('id');
    Route::get('/messages', [MessagesController::class, 'index']);
    Route::post('/chat/socket/authorize', [SocketIoController::class, 'authorizeSocket'])->middleware('throttle:socket-auth');
    Route::post('/messages/start', [MessagesController::class, 'start']);
    Route::get('/messages/{conversationId}', [MessagesController::class, 'show'])->whereUuid('conversationId');
    Route::post('/messages/{conversationId}', [MessagesController::class, 'send'])->whereUuid('conversationId')->middleware('throttle:chat-send');
    Route::post('/reviews', [CommunityController::class, 'createReview']);

    Route::get('/feed', [CommunityController::class, 'feed']);
    Route::post('/feed', [CommunityController::class, 'createPost']);
    Route::post('/feed/{postId}/like', [CommunityController::class, 'like'])->whereUuid('postId');
    Route::delete('/feed/{postId}/like', [CommunityController::class, 'unlike'])->whereUuid('postId');
    Route::post('/feed/{postId}/comments', [CommunityController::class, 'createComment'])->whereUuid('postId');

    Route::post('/safety/reports', [OperationsController::class, 'report']);
    Route::post('/safety/blocks/{userId}', [OperationsController::class, 'block'])->whereUuid('userId');
    Route::delete('/safety/blocks/{userId}', [OperationsController::class, 'unblock'])->whereUuid('userId');
    Route::post('/suggestions', [OperationsController::class, 'suggest']);
    Route::post('/support', [OperationsController::class, 'createTicket']);
    Route::get('/support/mine', [OperationsController::class, 'mineTickets']);
    Route::get('/referrals/mine', [OperationsController::class, 'referralMine']);
    Route::post('/referrals/redeem', [OperationsController::class, 'redeemReferral']);

    Route::middleware(RequireAdminApi::class)->group(function () {
        Route::patch('/profiles/{type}/{id}/verify', [ProfilesController::class, 'verify'])
            ->whereIn('type', ['teacher', 'institute'])
            ->whereUuid('id');
        Route::patch('/profiles/institute/{id}/type', [ProfilesController::class, 'classifyInstitute'])->whereUuid('id');
        Route::patch('/profiles/institute/{id}/bsl-verified', [ProfilesController::class, 'setBslVerified'])->whereUuid('id');
        Route::get('/safety/reports', [OperationsController::class, 'reports']);
        Route::patch('/safety/reports/{id}/status', [OperationsController::class, 'updateReport'])->whereUuid('id');
        Route::get('/suggestions', [OperationsController::class, 'suggestions']);
        Route::patch('/suggestions/{id}/status', [OperationsController::class, 'updateSuggestion'])->whereUuid('id');
        Route::get('/support', [OperationsController::class, 'tickets']);
        Route::patch('/support/{id}/status', [OperationsController::class, 'updateTicket'])->whereUuid('id');
        Route::get('/referrals/top', [OperationsController::class, 'topReferrals']);
        Route::get('/analytics/summary', [OperationsController::class, 'analyticsSummary']);

        Route::get('/moderation/photos/pending', [ModerationController::class, 'pendingPhotos']);
        Route::patch('/moderation/photos/{userId}', [ModerationController::class, 'reviewPhoto'])->whereUuid('userId');
        Route::get('/moderation/conversations', [ModerationController::class, 'conversations']);
        Route::get('/moderation/conversations/{id}/messages', [ModerationController::class, 'conversationMessages'])->whereUuid('id');
        Route::get('/moderation/bookings', [ModerationController::class, 'bookings']);
        Route::get('/moderation/contact-stats', [ModerationController::class, 'contactStats']);
        Route::post('/moderation/users/{userId}/suspend', [ModerationController::class, 'suspend'])->whereUuid('userId');
        Route::post('/moderation/users/{userId}/unsuspend', [ModerationController::class, 'unsuspend'])->whereUuid('userId');
        Route::get('/moderation/users/suspended', [ModerationController::class, 'suspendedUsers']);
    });
});
