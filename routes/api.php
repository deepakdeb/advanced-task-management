<?php

use App\Http\Controllers\API\AuthenticationController;
use App\Http\Controllers\API\TaskController;

Route::post('register', [AuthenticationController::class, 'register'])->name('register');
Route::post('login', [AuthenticationController::class, 'login'])->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [AuthenticationController::class, 'userInfo']);
    Route::post('logout', [AuthenticationController::class, 'logOut'])->name('logout');
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->middleware('throttle:5,1')->name('tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel']);
    Route::post('tasks/{task}/retry', [TaskController::class, 'retry']);
});
