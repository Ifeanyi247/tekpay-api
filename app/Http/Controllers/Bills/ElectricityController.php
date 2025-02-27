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

class ElectricityController extends Controller
{
    use VTPassResponseHandler;

    private $baseUrl = 'https://vtpass.com/api';

    public function verifyMeter(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billersCode' => 'required|string',
            'serviceID' => 'required|string',
            'type' => 'required|string|in:prepaid,postpaid'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = Http::timeout(-1)->withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'secret-key' => env('VT_PASS_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/merchant-verify', [
                'billersCode' => $request->billersCode,
                'serviceID' => $request->serviceID,
                'type' => $request->type
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass Meter Verification Response:', $data);

                if ($data['code'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'Meter verified successfully',
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
                'message' => 'Failed to verify meter',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error verifying meter: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while verifying meter',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function purchaseElectricity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'billersCode' => 'required|string',
            'serviceID' => 'required|string',
            'variation_code' => 'required|string|in:prepaid,postpaid',
            'amount' => 'required|numeric|min:100',
            'phone' => 'required|string'
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

            if ($profile->wallet < $request->amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient balance'
                ], 400);
            }

            // Generate unique request ID with GMT+1 timezone (Africa/Lagos)
            $lagosTime = Carbon::now('Africa/Lagos');
            $requestId = $lagosTime->format('YmdHi') . '_' . (string) Str::uuid();

            // Prepare payload
            $payload = [
                'request_id' => $requestId,
                'serviceID' => $request->serviceID,
                'billersCode' => $request->billersCode,
                'variation_code' => $request->variation_code,
                'amount' => $request->amount,
                'phone' => $request->phone
            ];

            $response = Http::timeout(-1)->withHeaders([
                'api-key' => env('VT_PASS_API_KEY'),
                'secret-key' => env('VT_PASS_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/pay', $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass Electricity Purchase Response:', $data);

                if ($data['code'] === '000') {
                    // Start database transaction
                    DB::beginTransaction();
                    try {
                        // Update user balance
                        $profile->wallet -= $request->amount;
                        $profile->save();

                        // Get transaction details from response
                        $txn = $data['content']['transactions'];

                        // Create transaction record
                        Transaction::create([
                            'user_id' => $user->id,
                            'request_id' => $requestId,
                            'transaction_id' => $txn['transactionId'],
                            'reference' => $requestId,
                            'amount' => $request->amount,
                            'commission' => $txn['commission'] ?? 0,
                            'total_amount' => $txn['total_amount'] ?? $request->amount,
                            'type' => 'Electricity Bill - ' . $request->serviceID,
                            'status' => 'success',
                            'service_id' => $request->serviceID,
                            'phone' => $request->phone,
                            'product_name' => 'Electricity Bill - ' . strtoupper($request->variation_code),
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
                            'message' => 'Electricity purchase successful',
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
                'message' => 'Failed to process electricity purchase',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error processing electricity purchase: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing electricity purchase',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
