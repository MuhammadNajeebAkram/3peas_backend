<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EmailVerificationController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test', function () {
    return 'Hello, world!';
});

// Email Verification Routes for JWT
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
->name('verification.verify');


