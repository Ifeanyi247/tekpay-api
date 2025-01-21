<?php

namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Traits\VTPassResponseHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TvController extends Controller
{
    use VTPassResponseHandler;

    private $baseUrl = 'https://sandbox.vtpass.com/api';

    public function getTvVariations($serviceID)
    {
        $validator = Validator::make(['serviceID' => $serviceID], [
            'serviceID' => 'required|string|in:dstv,gotv,startimes'
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
                Log::info('VTPass TV Variations Response:', $data);

                if ($data['response_description'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'TV variations retrieved successfully',
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
                'message' => 'Failed to fetch TV variations',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error fetching TV variations: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching TV variations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifySmartcard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billersCode' => 'required|string',
            'serviceID' => 'required|string|in:dstv,gotv,startimes'
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
                Log::info('VTPass Smartcard Verification Response:', $data);

                if ($data['code'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'Smartcard verified successfully',
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
                'message' => 'Failed to verify smartcard',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error verifying smartcard: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while verifying smartcard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function purchaseSubscription(Request $request)
    {
        $rules = [
            'billersCode' => 'required|string',
            'serviceID' => 'required|string|in:dstv,gotv,startimes',
            'amount' => 'required|numeric',
            'phone' => 'required|string',
        ];

        // Add conditional validation rules
        if (in_array($request->serviceID, ['dstv', 'gotv'])) {
            $rules['subscription_type'] = 'required|string|in:change,renew';
            $rules['variation_code'] = 'required_if:subscription_type,change|string';
            $rules['quantity'] = 'sometimes|integer|min:1';
        } else {
            $rules['variation_code'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

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

            if ($profile->wallet < $request->amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient balance'
                ], 400);
            }

            // Generate unique request ID with GMT+1 timezone (Africa/Lagos)
            $lagosTime = Carbon::now('Africa/Lagos');
            $requestId = $lagosTime->format('YmdHi') . '_' . (string) Str::uuid();

            // Base payload for all services
            $payload = [
                'request_id' => $requestId,
                'serviceID' => $request->serviceID,
                'billersCode' => $request->billersCode,
                'amount' => $request->amount,
                'phone' => $request->phone
            ];

            // Add additional fields for DSTV and GOTV
            if (in_array($request->serviceID, ['dstv', 'gotv'])) {
                $payload['subscription_type'] = $request->subscription_type;
                if ($request->subscription_type === 'change') {
                    $payload['variation_code'] = $request->variation_code;
                    if ($request->has('quantity')) {
                        $payload['quantity'] = $request->quantity;
                    }
                }
            } else {
                $payload['variation_code'] = $request->variation_code;
            }

            $response = Http::withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'secret-key' => env('VT_PASS_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/pay', $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass TV Subscription Response:', $data);

                if ($data['code'] === '000') {
                    // Start database transaction
                    DB::beginTransaction();
                    try {
                        // Update user balance
                        $profile->wallet -= $request->amount;
                        $profile->save();

                        // Get transaction details from response
                        $txn = $data['content']['transactions'];

                        // Set product name based on service type
                        if (in_array($request->serviceID, ['dstv', 'gotv'])) {
                            $productName = $request->subscription_type === 'change'
                                ? "TV Subscription - {$request->serviceID} (Change)"
                                : "TV Subscription - {$request->serviceID} (Renewal)";
                        } else {
                            $productName = "TV Subscription - {$request->serviceID}";
                        }

                        // Create transaction record
                        Transaction::create([
                            'user_id' => $user->id,
                            'request_id' => $requestId,
                            'transaction_id' => $txn['transactionId'],
                            'reference' => $requestId,
                            'amount' => $request->amount,
                            'commission' => $txn['commission'] ?? 0,
                            'total_amount' => $txn['total_amount'] ?? $request->amount,
                            'type' => 'TV Subscription - ' . $request->serviceID,
                            'status' => 'success',
                            'service_id' => $request->serviceID,
                            'phone' => $request->phone,
                            'product_name' => $productName,
                            'platform' => $txn['platform'] ?? 'api',
                            'channel' => $txn['channel'] ?? 'api',
                            'method' => $txn['method'] ?? 'api',
                            'response_code' => $data['code'],
                            'response_message' => $data['response_description'],
                            'transaction_date' => now()
                        ]);

                        DB::commit();

                        return response()->json([
                            'status' => true,
                            'message' => 'TV subscription successful',
                            'data' => $data['content']['transactions']
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
                'message' => 'Failed to process TV subscription',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error processing TV subscription: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing TV subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
