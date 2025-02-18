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

class DataController extends Controller
{
    use VTPassResponseHandler;

    private $baseUrl = 'https://vtpass.com/api';

    public function getDataPlans($serviceID)
    {
        $validator = Validator::make(['serviceID' => $serviceID], [
            'serviceID' => 'required|string|in:mtn-data,glo-data,airtel-data,etisalat-data,glo-sme-data,9mobile-sme-data'
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
                Log::info('VTPass Data Plans Response:', $data);

                if ($data['response_description'] === '000') {
                    return response()->json([
                        'status' => true,
                        'message' => 'Data plans retrieved successfully',
                        'data' => $data['content']
                    ]);
                }

                return response()->json([
                    'status' => false,
                    'message' => 'Failed to retrieve data plans',
                    'data' => $data
                ], 400);
            }

            Log::error('VTPass Data Plans Error:', ['body' => $response->body()]);
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch data plans',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('VTPass Data Plans Exception:', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while fetching data plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function purchaseData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'serviceID' => 'required|string|in:mtn-data,glo-data,airtel-data,etisalat-data',
            'billersCode' => 'required|string',
            'variation_code' => 'required|string',
            'amount' => 'required|numeric|min:50|max:50000'
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

                    $responseInfo = $this->getResponseMessage($data['code']);
                    $transactions = $data['content']['transactions'] ?? [];

                    // Create transaction record
                    $transaction = new Transaction([
                        'user_id' => $user->id,
                        'request_id' => $data['requestId'],
                        'transaction_id' => $transactions['transactionId'] ?? null,
                        'reference' => $reference,
                        'amount' => $request->amount,
                        'commission' => $transactions['commission'] ?? 0,
                        'total_amount' => $transactions['total_amount'] ?? $request->amount,
                        'type' => 'data_purchase',
                        'status' => $transactions['status'] ?? 'pending',
                        'service_id' => $request->serviceID,
                        'phone' => $request->phone,
                        'product_name' => $transactions['product_name'] ?? "Data Bundle",
                        'platform' => $transactions['platform'] ?? 'api',
                        'channel' => $transactions['channel'] ?? 'api',
                        'method' => $transactions['method'] ?? 'api',
                        'response_code' => $data['code'],
                        'response_message' => $responseInfo['message'],
                        'transaction_date' => $data['transaction_date']['date'] ?? now()
                    ]);

                    // Return appropriate response based on status code
                    if ($this->isSuccess($data['code'])) {
                        // Deduct from profile balance
                        $profile->wallet -= $request->amount;
                        if (!$profile->save()) {
                            throw new \Exception('Failed to deduct balance');
                        }

                        $transaction->save();
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
                                'network' => $request->serviceID,
                                'status' => $transactions['status'],
                                'product_name' => $transactions['product_name'],
                                'commission' => $transactions['commission'],
                                'total_amount' => $transactions['total_amount'],
                                'balance' => $profile->wallet,
                                'purchased_code' => $data['purchased_code'] ?? null
                            ]
                        ]);
                    }

                    if ($this->isProcessing($data['code'])) {
                        $transaction->save();
                        DB::commit();

                        return response()->json([
                            'status' => true,
                            'message' => $responseInfo['message'],
                            'data' => [
                                'requestId' => $data['requestId'],
                                'reference' => $reference,
                                'shouldRequery' => true
                            ]
                        ]);
                    }

                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => $responseInfo['message'],
                        'data' => $data
                    ], 400);
                }

                DB::rollBack();
                Log::error('VTPass Error Response:', ['body' => $response->body()]);
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to process request',
                    'error' => $response->body()
                ], $response->status());
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('VTPass Exception:', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
