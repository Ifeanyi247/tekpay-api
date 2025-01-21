<?php

namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Traits\VTPassResponseHandler;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EducationController extends Controller
{
    use VTPassResponseHandler;

    private $baseUrl = 'https://sandbox.vtpass.com/api';

    public function getVariations($serviceID)
    {
        $validator = Validator::make(['serviceID' => $serviceID], [
            'serviceID' => 'required|string|in:waec,jamb'
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
                'public-key' => env('VT_PASS_PUBLIC_KEY'),
                'Content-Type' => 'application/json'
            ])->get('https://sandbox.vtpass.com/api/service-variations', [
                'serviceID' => $serviceID
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass Education Variations Response:', $data);

                if ($data['response_description'] === '000') {
                    $content = $data['content'];
                    // Remove the misspelled key if it exists
                    if (isset($content['varations'])) {
                        unset($content['varations']);
                    }

                    return response()->json([
                        'status' => true,
                        'message' => 'Education variations retrieved successfully',
                        'data' => $content
                    ]);
                }

                $responseMessage = $this->getResponseMessage($data['code']);
                return response()->json([
                    'status' => $responseMessage['status'],
                    'message' => $responseMessage['message']
                ], 400);
            }

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch education variations',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error fetching education variations: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching education variations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function purchaseWaecEducation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'variation_code' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get authenticated user and profile
            $user = $request->user();
            $profile = $user->profile;

            if (!$user->phone_number) {
                return response()->json([
                    'status' => false,
                    'message' => 'Phone number is required'
                ], 400);
            }

            // Generate unique request ID with GMT+1 timezone (Africa/Lagos)
            $lagosTime = Carbon::now('Africa/Lagos');
            $requestId = $lagosTime->format('YmdHi') . '_' . (string) Str::uuid();

            // Prepare payload with mandatory fields
            $payload = [
                'request_id' => $requestId,
                'serviceID' => 'waec',
                'variation_code' => $request->variation_code,
                'phone' => $user->phone_number,
                'quantity' => $request->quantity ?? 1
            ];

            $response = Http::withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'secret-key' => env('VT_PASS_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post('https://sandbox.vtpass.com/api/pay', $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass WAEC Purchase Response:', $data);

                if ($data['code'] === '000') {
                    // Start database transaction
                    DB::beginTransaction();
                    try {
                        // Get transaction details from response
                        $txn = $data['content']['transactions'];

                        // Update user balance
                        $profile->wallet -= $txn['amount'];
                        $profile->save();

                        // Create transaction record
                        Transaction::create([
                            'user_id' => $user->id,
                            'request_id' => $requestId,
                            'transaction_id' => $txn['transactionId'],
                            'reference' => $requestId,
                            'amount' => $txn['amount'],
                            'commission' => $txn['commission'] ?? 0,
                            'total_amount' => $txn['total_amount'] ?? $txn['amount'],
                            'type' => 'WAEC Result Checker',
                            'status' => 'success',
                            'service_id' => 'waec',
                            'phone' => $user->phone_number,
                            'product_name' => $txn['product_name'] ?? 'WAEC Result Checker PIN',
                            'platform' => $txn['platform'] ?? 'api',
                            'channel' => $txn['channel'] ?? 'api',
                            'method' => $txn['method'] ?? 'api',
                            'response_code' => $data['code'],
                            'response_message' => $data['response_description'],
                            'transaction_date' => now(),
                            'purchased_code' => $data['purchased_code'] ?? null,
                            'cards' => $data['cards'] ?? null
                        ]);

                        DB::commit();

                        return response()->json([
                            'status' => true,
                            'message' => 'WAEC Result Checker PIN purchase successful',
                            'data' => [
                                'transaction' => $txn,
                                'purchased_code' => $data['purchased_code'] ?? null,
                                'cards' => $data['cards'] ?? null,
                                'requestId' => $data['requestId'] ?? $requestId,
                                'amount' => $data['amount'] ?? $txn['amount'],
                                'transaction_date' => $data['transaction_date'] ?? now()
                            ]
                        ]);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        throw $e;
                    }
                }

                $responseMessage = $this->getResponseMessage($data['code']);
                return response()->json([
                    'status' => $responseMessage['status'],
                    'message' => $responseMessage['message']
                ], 400);
            }

            return response()->json([
                'status' => false,
                'message' => 'Failed to process WAEC purchase',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error processing WAEC purchase: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing WAEC purchase',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyJambProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billersCode' => 'required|string',
            'type' => 'required|string|in:utme,de'
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
            ])->post('https://sandbox.vtpass.com/api/merchant-verify', [
                'billersCode' => $request->billersCode,
                'serviceID' => 'jamb',
                'type' => $request->type
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass JAMB Profile Verification Response:', $data);

                if ($data['code'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'JAMB Profile verified successfully',
                        'data' => $data['content']
                    ]);
                }

                $responseMessage = $this->getResponseMessage($data['code']);
                return response()->json([
                    'status' => $responseMessage['status'],
                    'message' => $responseMessage['message']
                ], 400);
            }

            return response()->json([
                'status' => false,
                'message' => 'Failed to verify JAMB Profile',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error verifying JAMB Profile: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while verifying JAMB Profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function purchaseJamb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'variation_code' => 'required|string|in:utme,de',
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
            // Get authenticated user and profile
            $user = $request->user();
            $profile = $user->profile;

            if (!$user->phone_number) {
                return response()->json([
                    'status' => false,
                    'message' => 'Phone number is required'
                ], 400);
            }

            // Get the variation details to check price
            $variations = Http::withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'public-key' => env('VT_PASS_PUBLIC_KEY'),
                'Content-Type' => 'application/json'
            ])->get('https://sandbox.vtpass.com/api/service-variations', [
                'serviceID' => 'jamb'
            ])->json();

            if (!isset($variations['content']['variations'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unable to fetch JAMB variations'
                ], 400);
            }

            $variation = collect($variations['content']['variations'])
                ->firstWhere('variation_code', $request->variation_code);

            if (!$variation) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid variation code'
                ], 400);
            }

            $amount = (float) $variation['variation_amount'];

            // Check if user has sufficient balance
            if (!$profile || $profile->wallet < $amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient balance',
                    'data' => [
                        'balance' => $profile ? $profile->wallet : 0,
                        'required' => $amount
                    ]
                ], 400);
            }

            // Generate unique request ID with GMT+1 timezone (Africa/Lagos)
            $lagosTime = Carbon::now('Africa/Lagos');
            $requestId = $lagosTime->format('YmdHi') . '_' . (string) Str::uuid();
            $reference = 'TRX' . $lagosTime->format('YmdHis') . Str::random(6);

            // Prepare payload with mandatory fields
            $payload = [
                'request_id' => $requestId,
                'serviceID' => 'jamb',
                'variation_code' => $request->variation_code,
                'billersCode' => $request->billersCode,
                'phone' => $user->phone_number
            ];

            DB::beginTransaction();
            try {
                // Deduct user's balance
                $profile->decrement('wallet', $amount);

                $response = Http::withHeaders([
                    'api-key' => env('VT_PASS_API_KEY'),
                    'secret-key' => env('VT_PASS_SECRET_KEY'),
                    'Content-Type' => 'application/json'
                ])->post('https://sandbox.vtpass.com/api/pay', $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    Log::info('VTPass JAMB Purchase Response:', $data);

                    if ($data['code'] === '000') {
                        $txn = $data['content']['transactions'];

                        // Create transaction record
                        $transaction = new Transaction([
                            'user_id' => $user->id,
                            'request_id' => $data['requestId'],
                            'transaction_id' => $txn['transactionId'] ?? null,
                            'reference' => $reference,
                            'amount' => $txn['amount'],
                            'commission' => $txn['commission'] ?? 0,
                            'total_amount' => $txn['total_amount'],
                            'type' => 'jamb_purchase',
                            'status' => $txn['status'] ?? 'pending',
                            'service_id' => 'jamb',
                            'phone' => $user->phone_number,
                            'product_name' => $txn['product_name'] ?? 'JAMB PIN',
                            'platform' => $txn['platform'] ?? 'api',
                            'channel' => $txn['channel'] ?? 'api',
                            'method' => $txn['method'] ?? 'api',
                            'response_code' => $data['code'],
                            'response_message' => $data['response_description'],
                            'transaction_date' => $data['transaction_date']['date'] ?? now(),
                            'purchased_code' => $data['purchased_code'] ?? null,
                            'pin' => $data['Pin'] ?? null
                        ]);

                        $transaction->save();
                        DB::commit();

                        return response()->json([
                            'status' => true,
                            'message' => 'JAMB PIN purchase successful',
                            'data' => [
                                'transaction' => $txn,
                                'purchased_code' => $data['purchased_code'] ?? null,
                                'pin' => $data['Pin'] ?? null,
                                'requestId' => $data['requestId'] ?? $requestId,
                                'amount' => $data['amount'] ?? $txn['amount'],
                                'transaction_date' => $data['transaction_date'] ?? now()
                            ]
                        ]);
                    }

                    // If transaction failed, refund the user
                    $profile->increment('balance', $amount);
                    DB::commit();

                    $responseMessage = $this->getResponseMessage($data['code']);
                    return response()->json([
                        'status' => $responseMessage['status'],
                        'message' => $responseMessage['message']
                    ], 400);
                }

                // If request failed, refund the user
                $profile->increment('balance', $amount);
                DB::commit();

                return response()->json([
                    'status' => false,
                    'message' => 'Failed to purchase JAMB PIN',
                    'error' => $response->body()
                ], $response->status());
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error purchasing JAMB PIN: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while purchasing JAMB PIN',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
