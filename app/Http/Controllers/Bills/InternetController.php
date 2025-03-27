<?php

namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\NotificationService;
use App\Traits\VTPassResponseHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Str;

class InternetController extends Controller
{
    use VTPassResponseHandler;

    protected $notificationService;
    private $baseUrl = 'https://vtpass.com/api';
    // private $baseUrl = 'https://sandbox.vtpass.com/api';

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function getInternetPlans($serviceID)
    {
        $validator = Validator::make(['serviceID' => $serviceID], [
            'serviceID' => 'required|string|in:smile-direct,spectranet'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = Http::withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'secret-key' => env('VT_PASS_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->get($this->baseUrl . '/service-variations', [
                'serviceID' => $serviceID
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass Internet Plans Response:', $data);

                if ($data['response_description'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'Internet plans retrieved successfully',
                        'data' => $data['content']
                    ]);
                }

                return response()->json([
                    'status' => false,
                    'message' => 'Failed to retrieve internet plans',
                    'data' => $data
                ], 400);
            }

            Log::error('VTPass Internet Plans Error:', ['body' => $response->body()]);
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch internet plans',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('VTPass Internet Plans Exception:', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching internet plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifySmileEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billersCode' => 'required|string|email',
            'serviceID' => 'required|string|in:smile-direct'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = Http::withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'secret-key' => env('VT_PASS_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/merchant-verify', [
                'billersCode' => $request->billersCode,
                'serviceID' => $request->serviceID
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass Smile Email Verification Response:', $data);

                if ($data['code'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'Email verified successfully',
                        'data' => $data['content']
                    ]);
                }

                return response()->json([
                    'status' => false,
                    'message' => 'Failed to verify email',
                    'data' => $data
                ], 400);
            }

            Log::error('VTPass Smile Email Verification Error:', ['body' => $response->body()]);
            return response()->json([
                'status' => false,
                'message' => 'Failed to verify email',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Smile Email Verification Exception:', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while verifying email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function purchaseInternet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'serviceID' => 'required|string|in:smile-direct,spectranet',
            'variation_code' => 'required|string',
            'amount' => 'required|numeric|min:100',
            'billersCode' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check profile balance
            $user = $request->user();
            $profile = $user->profile;

            if (!$profile || (int) $profile->wallet < (int) $request->amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient balance',
                    'data' => [
                        'balance' => $profile ? $profile->wallet : 0,
                        'required' => $request->amount
                    ]
                ], 400);
            }

            // Generate unique request ID with GMT+1 timezone (Africa/Lagos)
            $lagosTime = Carbon::now('Africa/Lagos');
            $requestId = $lagosTime->format('YmdHi') . '_' . (string) Str::uuid();
            $reference = 'TRX' . $lagosTime->format('YmdHis') . Str::random(6);

            DB::beginTransaction();
            try {
                $response = Http::withHeaders([
                    'api-key' => env('VT_PASS_API_KEY'),
                    'secret-key' => env('VT_PASS_SECRET_KEY'),
                    'Content-Type' => 'application/json'
                ])->post($this->baseUrl . '/pay', [
                    'request_id' => $requestId,
                    'serviceID' => $request->serviceID,
                    'billersCode' => $request->billersCode,
                    'variation_code' => $request->variation_code,
                    'amount' => $request->amount,
                    'phone' => $request->phone
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    Log::info('VTPass Response:', $data);

                    $responseInfo = $this->getResponseMessage($data['response_description']);
                    $transactions = $data['content']['transactions'] ?? [];

                    // Only proceed if transaction is successful
                    if ($this->isSuccess($data['response_description'])) {
                        // Create transaction record
                        $transaction = Transaction::create([
                            'user_id' => $user->id,
                            'request_id' => $data['requestId'] ?? $requestId,
                            'transaction_id' => $transactions['transactionId'] ?? null,
                            'reference' => $reference,
                            'amount' => $request->amount,
                            'commission' => $transactions['commission'] ?? 0,
                            'total_amount' => $transactions['total_amount'] ?? $request->amount,
                            'type' => 'internet',
                            'status' => $transactions['status'] ?? 'delivered',
                            'service_id' => $request->serviceID,
                            'phone' => $request->phone,
                            'product_name' => $transactions['product_name'] ?? 'Internet Bundle',
                            'platform' => $transactions['platform'] ?? 'api',
                            'channel' => $transactions['channel'] ?? 'api',
                            'method' => $transactions['method'] ?? 'api',
                            'response_code' => $data['response_description'],
                            'response_message' => $responseInfo['message'],
                            'transaction_date' => $data['transaction_date'] ?? now(),
                            'purchased_code' => $data['purchased_code'] ?? null
                        ]);

                        // Deduct from profile balance
                        $profile->wallet -= $request->amount;
                        if (!$profile->save()) {
                            DB::rollBack();
                            throw new \Exception('Failed to deduct balance');
                        }

                        // Send notification
                        $this->notificationService->notifyTransaction($user->id, $transaction);

                        DB::commit();

                        return response()->json([
                            'status' => true,
                            'message' => $this->getTransactionStatus($transactions),
                            'data' => [
                                'requestId' => $data['requestId'],
                                'transactionId' => $transactions['transactionId'],
                                'reference' => $reference,
                                'amount' => $data['amount'],
                                'transaction_date' => $data['transaction_date'],
                                'phone' => $transactions['phone'],
                                'service' => $request->serviceID,
                                'status' => $transactions['status'],
                                'product_name' => $transactions['product_name'],
                                'commission' => $transactions['commission'],
                                'total_amount' => $transactions['total_amount'],
                                'balance' => $profile->wallet,
                                'purchased_code' => $data['purchased_code'] ?? null
                            ]
                        ]);
                    }

                    // If not successful, return error without saving to DB
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => $responseInfo['message'],
                        'data' => $data
                    ], 400);
                }

                DB::rollBack();
                Log::error('VTPass Internet Purchase Error:', ['body' => $response->body()]);
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to process internet purchase',
                    'error' => $response->body()
                ], $response->status());
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Internet Purchase Exception:', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing your request',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
