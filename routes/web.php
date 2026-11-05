<?php

use App\Http\Controllers\InstitutionEntryController;
use App\Http\Middleware\InstitutionSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Reviewed candidate only: no production/staging entry, including cached routes.
if (app()->environment(['local', 'testing'])) {
    Route::middleware(InstitutionSession::class.':public')->group(function (): void {
        Route::get('/institution/login', [InstitutionEntryController::class, 'form'])->name('institution.login');
        Route::post('/institution/login', [InstitutionEntryController::class, 'login']);
        Route::post('/institution/recovery', [InstitutionEntryController::class, 'recovery'])->name('institution.recovery');
        Route::get('/institution/{organization}/reset/{account}/{token}', [InstitutionEntryController::class, 'resetForm'])->name('institution.reset');
        Route::post('/institution/{organization}/reset/{account}/{token}', [InstitutionEntryController::class, 'reset']);
    });
    Route::middleware(InstitutionSession::class)->group(function (): void {
        Route::get('/institution/{organization}/account', [InstitutionEntryController::class, 'account'])->name('institution.account');
        Route::post('/institution/{organization}/logout', [InstitutionEntryController::class, 'logout'])->name('institution.logout');
    });
}
