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
        $validator = Validator::make($request->all(), [
            'billersCode' => 'required|string',
            'phone' => 'required|string',
            'serviceID' => 'required|string|in:dstv,gotv,startimes',
            'subscription_type' => 'required|string|in:change,renew',
            'amount' => 'required|numeric',
            'variation_code' => 'required_if:subscription_type,change|string',
            'quantity' => 'sometimes|integer|min:1'
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

            if ($profile->balance < $request->amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient balance'
                ], 400);
            }

            // Generate unique request ID
            $requestId = 'TV' . Str::random(15) . time();

            // Prepare payload
            $payload = [
                'request_id' => $requestId,
                'serviceID' => $request->serviceID,
                'billersCode' => $request->billersCode,
                'amount' => $request->amount,
                'phone' => $request->phone,
                'subscription_type' => $request->subscription_type
            ];

            // Add variation_code and quantity for bouquet change
            if ($request->subscription_type === 'change') {
                $payload['variation_code'] = $request->variation_code;
                if ($request->has('quantity')) {
                    $payload['quantity'] = $request->quantity;
                }
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
                        $profile->balance -= $request->amount;
                        $profile->save();

                        // Create transaction record
                        Transaction::create([
                            'user_id' => $user->id,
                            'amount' => $request->amount,
                            'type' => 'debit',
                            'status' => 'success',
                            'reference' => $requestId,
                            'description' => "TV Subscription - {$request->serviceID} ({$request->subscription_type})",
                            'meta' => json_encode($data)
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
