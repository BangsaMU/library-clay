<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LibraryClay Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('mud-library-clay', function () {
    // dd(config());
    $value = config('LibraryClayConfig.main.APP_CODE');
    echo 'Hello from the library-clay package!' . json_encode($value);
});

Route::get('mud-view', function () {
    return view('library-clay::mud');
});

Route::get('getIp', function () {
    return view('library-clay::mud');
});


// ─── TEST MAIL ROUTES (diagnosa email) ───────────────────────────

Route::middleware(['web','auth'])->prefix('test-mail')->name('test-mail.')->group(function () {
    Route::get('diagnose', [\Bangsamu\LibraryClay\Controllers\LibraryClayMailController::class, 'diagnose'])->name('diagnose');
    Route::get('send-sync', [\Bangsamu\LibraryClay\Controllers\LibraryClayMailController::class, 'testSendSync'])->name('send-sync');
    Route::get('send-env', [\Bangsamu\LibraryClay\Controllers\LibraryClayMailController::class, 'testSendEnv'])->name('send-env');
});

Route::middleware(['web'])->group(function () {

});


