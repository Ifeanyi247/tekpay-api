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
use Illuminate\Support\Str;
use Carbon\Carbon;

class AirtimeController extends Controller
{
    use VTPassResponseHandler;

    private $baseUrl = 'https://vtpass.com/api';

    public function purchaseAirtime(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'amount' => 'required|numeric|min:50|max:50000',
            'serviceID' => 'required|string|in:mtn,glo,airtel,etisalat'
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
                        'balance' => $profile ? $profile->balance : 0,
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
                        'type' => 'airtime_purchase',
                        'status' => $transactions['status'] ?? 'pending',
                        'service_id' => $request->serviceID,
                        'phone' => $request->phone,
                        'product_name' => $transactions['product_name'] ?? "{$request->serviceID} Airtime",
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
                                'balance' => $profile->balance
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

    public function checkTransactionStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'request_id' => 'required|string'
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
            ])->post($this->baseUrl . '/requery', [
                'request_id' => $request->request_id
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('VTPass Requery Response:', $data);

                $responseInfo = $this->getResponseMessage($data['code']);
                $transactions = $data['content']['transactions'] ?? [];

                // Update transaction record if exists
                $transaction = Transaction::where('request_id', $request->request_id)->first();
                if ($transaction && $this->isSuccess($data['code'])) {
                    $transaction->update([
                        'transaction_id' => $transactions['transactionId'] ?? $transaction->transaction_id,
                        'status' => $transactions['status'] ?? $transaction->status,
                        'response_code' => $data['code'],
                        'response_message' => $responseInfo['message']
                    ]);
                }

                if ($this->isSuccess($data['code'])) {
                    return response()->json([
                        'status' => true,
                        'message' => $this->getTransactionStatus($transactions),
                        'data' => [
                            'requestId' => $data['requestId'],
                            'transactionId' => $transactions['transactionId'],
                            'amount' => $data['amount'],
                            'transaction_date' => $data['transaction_date'],
                            'status' => $transactions['status'],
                            'product_name' => $transactions['product_name'],
                            'phone' => $transactions['phone'],
                            'email' => $transactions['email'],
                            'commission' => $transactions['commission'],
                            'total_amount' => $transactions['total_amount'],
                            'channel' => $transactions['channel'],
                            'platform' => $transactions['platform'],
                            'method' => $transactions['method']
                        ]
                    ]);
                }

                return response()->json([
                    'status' => false,
                    'message' => $responseInfo['message'],
                    'data' => $data
                ], 400);
            }

            Log::error('VTPass Requery Error:', ['body' => $response->body()]);
            return response()->json([
                'status' => false,
                'message' => 'Failed to check transaction status',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('VTPass Requery Exception:', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while checking transaction status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available data service IDs from VTpass
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDataServices($identifier)
    {
        try {
            $response = Http::get($this->baseUrl . '/services', [
                'identifier' => $identifier
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Fetched data services successfully',
                'data' => $response->json()
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching data services: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch data services',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
