<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Bills\AirtimeController;
use App\Http\Controllers\Bills\DataController;
use App\Http\Controllers\Bills\EducationController;
use App\Http\Controllers\Bills\ElectricityController;
use App\Http\Controllers\Bills\TvController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Monnify\AuthTokenController;
use App\Http\Controllers\Monnify\BankVirtualAccountController;
use App\Http\Controllers\Monnify\KycController;
use App\Http\Controllers\VTpass\CallbackController;
use App\Http\Controllers\FlutterWave\BvnController;
use App\Http\Controllers\FlutterWave\BankController;
use App\Http\Controllers\FlutterWave\VirtualAccountCreation;
use App\Http\Controllers\Auth\ForgotPasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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

// route to run migration
Route::get('/migrate', function () {
    Artisan::call('migrate');
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/create-pin', [AuthController::class, 'createPin']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-pin', [AuthController::class, 'verifyPin']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetOtp']);
    Route::post('/verify-reset-otp', [ForgotPasswordController::class, 'verifyOtp']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'verifyAndResetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('user')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('/transactions', [UserController::class, 'transactions']);
        Route::post('/profile', [UserController::class, 'updateProfile']);
        Route::post('/change-password', [UserController::class, 'changePassword']);
        Route::post('/send-pin-change-otp', [UserController::class, 'sendPinChangeOtp']);
        Route::post('/verify-pin-change-otp', [UserController::class, 'verifyPinChangeOtp']);
        Route::post('/change-transaction-pin', [UserController::class, 'changeTransactionPin']);
        Route::post('/resend-pin-change-otp', [UserController::class, 'resendPinChangeOtp']);
    });

    // Monnify Routes
    Route::prefix('monnify')->middleware('auth:sanctum')->group(function () {
        Route::get('auth/token', [AuthTokenController::class, 'getAccessToken']);
        Route::post('kyc', [KycController::class, 'store']);
        Route::post('/create-wallet', [BankVirtualAccountController::class, 'createWallet']);
    });

    // Flutterwave Routes
    Route::prefix('flutterwave')->group(function () {
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/bvn/verify', [BvnController::class, 'verifyBvn']);
            Route::post('/virtual-account/create', [VirtualAccountCreation::class, 'createVirtualAccount']);
            Route::post('/bank/verify-account', [BankController::class, 'verifyAccount']);
            Route::get('/banks/nigeria', [BankController::class, 'getNigerianBanks']);
            Route::post('/transfer', [BankController::class, 'initiateTransfer']);
        });
        
        // Webhook endpoints (no auth required)
        Route::post('/virtual-account/webhook', [VirtualAccountCreation::class, 'handleWebhook']);
        Route::post('/transfer/webhook', [BankController::class, 'handleTransferWebhook'])->name('api.flutterwave.transfer.webhook');
    });

    // VTpass Webhook Routes
    Route::post('/vtpass/webhook', [CallbackController::class, 'handleTransactionUpdate']);

    // Bills Payment Routes
    Route::prefix('bills')->middleware('auth:sanctum')->group(function () {

        // get services
        Route::get('/services/{identifier}', [AirtimeController::class, 'getDataServices']);


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
