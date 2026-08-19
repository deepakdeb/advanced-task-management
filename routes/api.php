<?php

use App\Http\Controllers\API\AuthenticationController;
use App\Http\Controllers\API\TaskController;

Route::post('register', [AuthenticationController::class, 'register']);
Route::post('login', [AuthenticationController::class, 'login']);
Route::post('auth/register', [AuthenticationController::class, 'register']);
Route::post('auth/login', [AuthenticationController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [AuthenticationController::class, 'userInfo']);
    Route::post('logout', [AuthenticationController::class, 'logOut']);
    Route::post('auth/logout', [AuthenticationController::class, 'logOut']);
    Route::apiResource('tasks', TaskController::class)->only(['index', 'store', 'show']);
    Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel']);
    Route::post('tasks/{task}/retry', [TaskController::class, 'retry']);
});
