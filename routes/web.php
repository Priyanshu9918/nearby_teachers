<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AdminSeoController;
use App\Http\Controllers\AdminWorkspaceController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\InternationalSeoController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\ParentalConsentController;
use App\Http\Controllers\WebsiteController;
use App\Http\Middleware\RequireAdminSession;
use App\Http\Middleware\RequireDeveloperSession;
use App\Support\RecordId;
use App\Support\SupportTicketId;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::get('/parental-consent/{token}', [ParentalConsentController::class, 'show'])
    ->middleware(ThrottleRequests::class.':30,1')->name('parental-consent.show');
Route::post('/parental-consent/{token}', [ParentalConsentController::class, 'decide'])
    ->middleware(ThrottleRequests::class.':20,1')->name('parental-consent.decide');

Route::get('/health', fn () => response()->json(['ok' => true]));

Route::get('/', [WebsiteController::class, 'home'])->name('website.home');
Route::get('/site', [WebsiteController::class, 'directory'])->name('website.directory');
Route::get('/site/institute/{slug}', [WebsiteController::class, 'institute'])->name('website.institute');
Route::get('/teacher/{slug}', [InternationalSeoController::class, 'teacher'])->name('website.teacher');
Route::get('/institute/{slug}', [InternationalSeoController::class, 'institute'])->name('website.institute-seo');
Route::get('/my-webpage/{type}/{slug}', [InternationalSeoController::class, 'myWebpage'])
    ->whereIn('type', ['teacher', 'institute'])->name('website.my-webpage');
Route::get('/teachers/{slug}', [InternationalSeoController::class, 'teacher'])->name('website.teacher-old-overview');
Route::get('/teachers/{slug}/profile', [InternationalSeoController::class, 'teacher'])->name('website.teacher-profile');
Route::get('/site/teacher/{slug}', [InternationalSeoController::class, 'teacher'])->name('website.teacher-legacy');
Route::get('/site/teacher-profile/{slug}', [InternationalSeoController::class, 'teacher'])->name('website.teacher-profile-legacy');
Route::get('/site/feed/{city}', [WebsiteController::class, 'feed'])->name('website.feed');
Route::get('/site/city/{city}', [WebsiteController::class, 'city'])->name('website.city');
Route::get('/location-lookup', [WebsiteController::class, 'locationLookup'])->name('website.location-lookup');
Route::post('/location-lookup/reverse', [LocationLookupController::class, 'reverse'])
    ->middleware(ThrottleRequests::class.':30,1')
    ->name('website.location-lookup.reverse');
Route::get('/download', [WebsiteController::class, 'download'])
    ->middleware(ThrottleRequests::class.':60,1')
    ->name('download');
Route::get('/sitemap.xml', [InternationalSeoController::class, 'sitemap'])->name('website.sitemap');
Route::get('/directory', [InternationalSeoController::class, 'hub'])->name('seo.hub');
Route::get('/sitemaps/{kind}-{number}.xml', [InternationalSeoController::class, 'sitemapPart'])->whereIn('kind', ['teachers', 'institutes', 'directory', 'locations'])->where('number', '[1-9][0-9]{0,6}')->name('seo.sitemap-part');
Route::get('/robots.txt', [InternationalSeoController::class, 'robots'])->name('website.robots');

// Hierarchical location pages must be checked before the legacy country/city/subject routes.
Route::get('/{country}/{first}/{second}/{third}', [InternationalSeoController::class, 'geoLevelFour'])
    ->where('country', '[a-z]{2}')->where('first', '[a-z0-9][a-z0-9-]{0,179}')
    ->where('second', '[a-z0-9][a-z0-9-]{0,179}')->where('third', '[a-z0-9][a-z0-9-]{0,229}')
    ->name('seo.location.subject');
Route::get('/{country}/{language}/{district}/{village}/{subject}', [InternationalSeoController::class, 'localizedGeoLevelFive'])
    ->where('country', '[a-z]{2}')->where('language', '[a-z]{2}')
    ->where('district', '[a-z0-9][a-z0-9-]{0,179}')->where('village', '[a-z0-9][a-z0-9-]{0,179}')
    ->where('subject', '[a-z0-9][a-z0-9-]{0,229}')->name('seo.localized.location.subject');
Route::get('/{country}/{district}/{village}', [InternationalSeoController::class, 'geoLevelThree'])
    ->where('country', '[a-z]{2}')->where('district', '[a-z0-9][a-z0-9-]{0,179}')
    ->where('village', '[a-z0-9][a-z0-9-]{0,179}')->name('seo.location.village');
Route::get('/{country}/{segment}', [InternationalSeoController::class, 'geoLevelTwo'])
    ->where('country', '[a-z]{2}')->where('segment', '[a-z0-9][a-z0-9-]{0,179}')->name('seo.location.district');
Route::get('/{country}', [InternationalSeoController::class, 'geoCountry'])
    ->where('country', '[a-z]{2}')->name('seo.location.country');

Route::get('/admin/login', [AdminController::class, 'login'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'authenticate'])
    ->middleware(ThrottleRequests::class.':6,1')
    ->name('admin.authenticate');

Route::get('/developer/login', [DeveloperController::class, 'login'])->name('developer.login');
Route::post('/developer/login', [DeveloperController::class, 'authenticate'])
    ->middleware(ThrottleRequests::class.':6,1')
    ->name('developer.authenticate');

Route::middleware(RequireDeveloperSession::class)->prefix('developer')->name('developer.')->group(function () {
    Route::post('/logout', [DeveloperController::class, 'logout'])->name('logout');
    Route::get('/', [DeveloperController::class, 'portal'])->name('portal');
    Route::get('/socket/health', [DeveloperController::class, 'socketHealth'])
        ->middleware(ThrottleRequests::class.':30,1')->name('socket.health');
    Route::post('/configuration', [DeveloperController::class, 'updateSettings'])
        ->middleware(ThrottleRequests::class.':10,1')
        ->name('configuration.update');
});

Route::middleware(RequireAdminSession::class)->prefix('admin')->name('admin.')->group(function () {
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    Route::get('/', [AdminController::class, 'home'])->name('home');

    Route::get('/seo/subjects/{id?}', [AdminSeoController::class, 'index'])->where('id', RecordId::ROUTE_PATTERN)->name('seo.edit');
    Route::post('/seo/subjects', [AdminSeoController::class, 'save'])->name('seo.save');
    Route::post('/seo/sync', [AdminSeoController::class, 'sync'])->name('seo.sync');

    Route::get('/dashboard', [AdminReportController::class, 'dashboard'])->name('dashboard');
    Route::get('/reports/users/{id}', [AdminReportController::class, 'user'])->where('id', RecordId::ROUTE_PATTERN)->name('reports.user');
    Route::get('/reports/conversations/{id}', [AdminReportController::class, 'conversation'])->where('id', RecordId::ROUTE_PATTERN)->name('reports.conversation');
    Route::get('/reports/safety/{id}/screenshot', [AdminReportController::class, 'screenshot'])
        ->where('id', '[A-Fa-f0-9-]{1,36}')->name('reports.safety-screenshot');
    Route::get('/reports/export-all', [AdminReportController::class, 'exportAll'])->name('reports.export-all');
    Route::get('/reports/{report}', [AdminReportController::class, 'index'])->name('reports.index');

    Route::get('/support/contact-details', [AdminSupportController::class, 'contactDetails'])->name('support.contact');
    Route::put('/support/contact-details', [AdminSupportController::class, 'updateContactDetails'])->name('support.contact.update');
    Route::patch('/support/tickets/{id}/status', [AdminSupportController::class, 'updateTicketStatus'])
        ->where('id', SupportTicketId::ROUTE_PATTERN)->name('support.tickets.status');

    Route::get('/moderation/photos', [AdminWorkspaceController::class, 'photos'])->name('moderation.photos');
    Route::patch('/moderation/photos/{userId}', [AdminWorkspaceController::class, 'reviewPhoto'])
        ->where('userId', RecordId::ROUTE_PATTERN)->name('moderation.photos.review');
    Route::get('/moderation/bsl-centres', [AdminWorkspaceController::class, 'bslCentres'])->name('moderation.bsl');
    Route::patch('/moderation/bsl-centres/{userId}', [AdminWorkspaceController::class, 'setBslVerified'])
        ->where('userId', RecordId::ROUTE_PATTERN)->name('moderation.bsl.update');
    Route::get('/moderation/suspended-users', [AdminWorkspaceController::class, 'suspendedUsers'])->name('moderation.suspended');
    Route::post('/moderation/users/{userId}/suspend', [AdminWorkspaceController::class, 'suspendUser'])
        ->where('userId', RecordId::ROUTE_PATTERN)->name('moderation.users.suspend');
    Route::post('/moderation/users/{userId}/unsuspend', [AdminWorkspaceController::class, 'unsuspendUser'])
        ->where('userId', RecordId::ROUTE_PATTERN)->name('moderation.users.unsuspend');
    Route::get('/moderation/possible-duplicates', [AdminWorkspaceController::class, 'possibleDuplicates'])->name('moderation.duplicates');
    Route::patch('/moderation/safety-reports/{reportId}/status', [AdminWorkspaceController::class, 'updateSafetyReport'])
        ->where('reportId', RecordId::ROUTE_PATTERN)->name('moderation.safety.update');

    Route::get('/{section}/create', [AdminController::class, 'create'])->name('create');
    Route::post('/{section}', [AdminController::class, 'store'])->name('store');
    Route::get('/{section}/{id}/edit', [AdminController::class, 'edit'])->where('id', RecordId::ROUTE_PATTERN)->name('edit');
    Route::put('/{section}/{id}', [AdminController::class, 'update'])->where('id', RecordId::ROUTE_PATTERN)->name('update');
    Route::delete('/{section}/{id}', [AdminController::class, 'destroy'])->where('id', RecordId::ROUTE_PATTERN)->name('destroy');
    Route::get('/{section}', [AdminController::class, 'index'])->name('index');
});

Route::get('/{country}/{language}/{city}/{slug}', [InternationalSeoController::class, 'localizedListing'])->where('country', '[a-z]{2}')->where('language', '[a-z]{2}')->name('seo.localized');
Route::get('/{country}/{city}/{slug}', [InternationalSeoController::class, 'listing'])->where('country', '[a-z]{2}')->name('seo.listing');
