<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Bills\AirtimeController;
use App\Http\Controllers\Bills\DataController;
use App\Http\Controllers\Bills\EducationController;
use App\Http\Controllers\Bills\ElectricityController;
use App\Http\Controllers\Bills\TvController;
use App\Http\Controllers\User\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/create-pin', [AuthController::class, 'createPin']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-pin', [AuthController::class, 'verifyPin']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('user')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/transactions', [UserController::class, 'transactions']);
        Route::post('/profile', [UserController::class, 'updateProfile']);
    });



    // Bills Payment Routes
    Route::prefix('bills')->middleware('auth:sanctum')->group(function () {
        Route::post('/status', [AirtimeController::class, 'checkTransactionStatus']);
        Route::prefix('airtime')->group(function () {
            Route::post('/', [AirtimeController::class, 'purchaseAirtime']);
        });

        Route::prefix('data')->group(function () {
            Route::get('/plans/{serviceID}', [DataController::class, 'getDataPlans']);
            Route::post('/purchase', [DataController::class, 'purchaseData']);
        });

        Route::prefix('tv')->group(function () {
            Route::get('/variations/{serviceID}', [TvController::class, 'getTvVariations']);
            Route::post('/verify', [TvController::class, 'verifySmartcard']);
            Route::post('/purchase', [TvController::class, 'purchaseSubscription']);
        });

        Route::prefix('electricity')->group(function () {
            Route::post('/verify', [ElectricityController::class, 'verifyMeter']);
            Route::post('/purchase', [ElectricityController::class, 'purchaseElectricity']);
        });

        Route::prefix('education')->group(function () {
            Route::get('/variations/{serviceID}', [EducationController::class, 'getVariations']);
            Route::post('/waec/purchase', [EducationController::class, 'purchaseWaecEducation']);
            Route::post('/jamb/verify', [EducationController::class, 'verifyJambProfile']);
            Route::post('/jamb/purchase', [EducationController::class, 'purchaseJamb']);
        });
    });
});
