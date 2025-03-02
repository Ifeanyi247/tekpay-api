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
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\NotificationController; // Added this line
use App\Services\NotificationService;
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

// Webhook endpoints (no auth required)
Route::post('/virtual-account/webhook', [VirtualAccountCreation::class, 'handleWebhook']);
Route::post('/transfer/webhook', [BankController::class, 'handleTransferWebhook'])->name('api.flutterwave.transfer.webhook');

// VTpass Webhook Routes
Route::post('/vtpass/webhook', [CallbackController::class, 'handleTransactionUpdate']);

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

// no auth reset pin

Route::post('/send-pin-change-otp', [AuthController::class, 'sendPinChangeOtpNoAuth']);
Route::post('/verify-pin-change-otp', [AuthController::class, 'verifyPinChangeOtpNoAuth']);
Route::post('/change-transaction-pin', [AuthController::class, 'changeTransactionPinNoAuth']);
Route::post('/resend-pin-change-otp', [AuthController::class, 'resendPinChangeOtpNoAuth']);


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
        Route::get('/transfers', [UserController::class, 'getTransferTransactions']);
        Route::get('/referrals', [UserController::class, 'getReferralStats']);
        Route::post('/generate-referral-codes', [UserController::class, 'generateReferralCodes']);
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

    // Notification routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    });

    // Device token routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/device-token', [DeviceTokenController::class, 'store']);
        Route::delete('/device-token', [DeviceTokenController::class, 'destroy']);
    });

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::delete('/delete-account', [AuthController::class, 'deleteAccount']);

    // Test Routes (Remove in production)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/test/notification', function (Request $request) {
            try {
                $user = auth()->user();
                $notificationService = app(NotificationService::class);

                Log::info('Test notification request', [
                    'user_id' => $user->id,
                    'data' => $request->all()
                ]);

                $testTransaction = (object)[
                    'id' => 'TEST_' . time(),
                    'transaction_id' => 'TEST_' . time(),
                    'reference' => 'TEST_REF_' . time(),
                    'amount' => 1000,
                    'type' => 'test_transaction',
                    'status' => 'success'
                ];

                $result = $notificationService->notifyTransaction(
                    $user->id,
                    $testTransaction
                );

                Log::info('Test notification sent', [
                    'user_id' => $user->id,
                    'result' => $result
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Test notification sent successfully',
                    'result' => $result
                ]);
            } catch (\Exception $e) {
                Log::error('Test notification failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Failed to send test notification: ' . $e->getMessage()
                ], 500);
            }
        });
    });
});
