<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\WebsiteController;
use App\Http\Middleware\RequireAdminSession;
use App\Http\Middleware\RequireDeveloperSession;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['ok' => true]));

Route::get('/', [WebsiteController::class, 'home'])->name('website.home');
Route::get('/site', [WebsiteController::class, 'directory'])->name('website.directory');
Route::get('/site/institute/{slug}', [WebsiteController::class, 'institute'])->name('website.institute');
Route::get('/site/teacher/{slug}', [WebsiteController::class, 'teacher'])->name('website.teacher');
Route::get('/site/feed/{city}', [WebsiteController::class, 'feed'])->name('website.feed');
Route::get('/site/city/{city}', [WebsiteController::class, 'city'])->name('website.city');
Route::get('/location-lookup', [WebsiteController::class, 'locationLookup'])->name('website.location-lookup');
Route::post('/location-lookup/reverse', [LocationLookupController::class, 'reverse'])
    ->middleware(ThrottleRequests::class.':30,1')
    ->name('website.location-lookup.reverse');
Route::get('/download', [WebsiteController::class, 'download'])->name('download');
Route::get('/sitemap.xml', [WebsiteController::class, 'sitemap'])->name('website.sitemap');
Route::get('/robots.txt', [WebsiteController::class, 'robots'])->name('website.robots');

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
    Route::post('/configuration', [DeveloperController::class, 'updateSettings'])
        ->middleware(ThrottleRequests::class.':10,1')
        ->name('configuration.update');
});

Route::middleware(RequireAdminSession::class)->prefix('admin')->name('admin.')->group(function () {
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    Route::get('/', [AdminController::class, 'home'])->name('home');

    Route::get('/dashboard', [AdminReportController::class, 'dashboard'])->name('dashboard');
    Route::get('/reports/users/{id}', [AdminReportController::class, 'user'])->whereUuid('id')->name('reports.user');
    Route::get('/reports/conversations/{id}', [AdminReportController::class, 'conversation'])->whereUuid('id')->name('reports.conversation');
    Route::get('/reports/{report}', [AdminReportController::class, 'index'])->name('reports.index');

    Route::get('/{section}/create', [AdminController::class, 'create'])->name('create');
    Route::post('/{section}', [AdminController::class, 'store'])->name('store');
    Route::get('/{section}/{id}/edit', [AdminController::class, 'edit'])->name('edit');
    Route::put('/{section}/{id}', [AdminController::class, 'update'])->name('update');
    Route::delete('/{section}/{id}', [AdminController::class, 'destroy'])->name('destroy');
    Route::get('/{section}', [AdminController::class, 'index'])->name('index');
});
