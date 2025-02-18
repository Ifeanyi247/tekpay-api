<?php

namespace App\Http\Controllers\Monnify;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Monnify\AuthTokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BankVirtualAccountController extends Controller
{
    private $baseUrl;
    private $authTokenController;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'), '/');
        $this->authTokenController = new AuthTokenController();
    }

    private function generateWalletReference()
    {
        // Get current timestamp in Africa/Lagos timezone
        $lagosTime = now()->timezone('Africa/Lagos');
        return 'WAL' . $lagosTime->format('YmdHis') . strtoupper(Str::random(8));
    }

    public function createWallet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'walletName' => 'required|string',
            // 'bvn' => 'required|string|size:11',
            // 'bvnDateOfBirth' => 'required|date_format:Y-m-d'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'requestSuccessful' => false,
                'responseMessage' => 'Validation error',
                'responseCode' => '99',
                'responseBody' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $customerName = $user->first_name . ' ' . $user->last_name;

            // Generate unique wallet reference
            $walletReference = $this->generateWalletReference();

            // Get access token
            $tokenResponse = $this->authTokenController->getAccessToken();
            $tokenData = json_decode($tokenResponse->getContent(), true);

            if (!$tokenData['status']) {
                return response()->json([
                    'requestSuccessful' => false,
                    'responseMessage' => 'Failed to get authentication token',
                    'responseCode' => '99',
                    'responseBody' => $tokenData['message']
                ], 500);
            }

            // Prepare request payload
            $payload = [
                'walletReference' => $walletReference,
                'walletName' => $request->walletName,
                'customerName' => $customerName,
                'bvnDetails' => [
                    'bvn' => $request->bvn,
                    'bvnDateOfBirth' => $request->bvnDateOfBirth
                ],
                'customerEmail' => $user->email
            ];

            // Make API call to create wallet
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $tokenData['data']['accessToken'],
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/api/v1/disbursements/wallet', $payload);

            $responseData = $response->json();
            Log::info('Monnify Create Wallet Response:', $responseData);

            if ($response->successful()) {
                return response()->json([
                    'requestSuccessful' => true,
                    'responseMessage' => 'success',
                    'responseCode' => '0',
                    'responseBody' => [
                        'walletName' => $request->walletName,
                        'walletReference' => $walletReference,
                        'customerName' => $customerName,
                        'customerEmail' => $user->email,
                        'feeBearer' => $responseData['responseBody']['feeBearer'] ?? 'SELF',
                        'bvnDetails' => [
                            'bvn' => $request->bvn,
                            'bvnDateOfBirth' => $request->bvnDateOfBirth
                        ],
                        'accountNumber' => $responseData['responseBody']['accountNumber'] ?? null,
                        'accountName' => $responseData['responseBody']['accountName'] ?? $customerName
                    ]
                ]);
            }

            return response()->json([
                'requestSuccessful' => false,
                'responseMessage' => $responseData['responseMessage'] ?? 'Failed to create wallet',
                'responseCode' => $responseData['responseCode'] ?? '99',
                'responseBody' => $responseData['responseBody'] ?? null
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Wallet Creation Error: ' . $e->getMessage());
            return response()->json([
                'requestSuccessful' => false,
                'responseMessage' => 'Failed to create wallet',
                'responseCode' => '99',
                'responseBody' => $e->getMessage()
            ], 500);
        }
    }
}
