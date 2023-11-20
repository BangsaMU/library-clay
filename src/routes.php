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



Route::middleware(['web'])->group(function () {

});


