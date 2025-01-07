<?php

namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Traits\VTPassResponseHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AirtimeController extends Controller
{
    use VTPassResponseHandler;

    private $baseUrl = 'https://sandbox.vtpass.com/api';

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
            // Generate unique request ID with GMT+1 timezone including hour
            $lagosTime = Carbon::now('Africa/Lagos');
            $requestId = $lagosTime->format('YmdH') . Str::random(8);

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

                // Return appropriate response based on status code
                if ($this->isSuccess($data['code'])) {
                    return response()->json([
                        'status' => true,
                        'message' => $this->getTransactionStatus($transactions),
                        'data' => [
                            'requestId' => $data['requestId'],
                            'transactionId' => $transactions['transactionId'],
                            'amount' => $data['amount'],
                            'transaction_date' => $data['transaction_date'],
                            'phone' => $transactions['phone'],
                            'network' => $request->serviceID,
                            'status' => $transactions['status'],
                            'product_name' => $transactions['product_name'],
                            'commission' => $transactions['commission'],
                            'total_amount' => $transactions['total_amount']
                        ]
                    ]);
                }

                if ($this->isProcessing($data['code'])) {
                    return response()->json([
                        'status' => true,
                        'message' => $responseInfo['message'],
                        'data' => [
                            'requestId' => $data['requestId'],
                            'shouldRequery' => true
                        ]
                    ]);
                }

                return response()->json([
                    'status' => false,
                    'message' => $responseInfo['message'],
                    'data' => $data
                ], 400);
            }

            Log::error('VTPass Error Response:', ['body' => $response->body()]);
            return response()->json([
                'status' => false,
                'message' => 'Failed to process request',
                'error' => $response->body()
            ], $response->status());
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
}
