<?php

namespace App\Http\Controllers\Monnify;

use App\Http\Controllers\Controller;
use App\Models\Kyc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class KycController extends Controller
{
    private $baseUrl;
    private $authController;

    public function __construct(AuthTokenController $authController)
    {
        $this->baseUrl = rtrim(env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'), '/');
        $this->authController = $authController;
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'gender' => 'required|string|in:male,female,other',
            'date_of_birth' => 'required|date',
            'bvn' => 'required|string|size:11',
            'nin' => 'required|string',
            'state' => 'required|string',
            'local_government' => 'required|string',
            'address' => 'required|string',
            'house_number' => 'required|string',
            'utility_bill_type' => 'required|string',
            'bill_image' => 'required|image|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get access token
            $tokenResponse = $this->authController->getAccessToken();
            $tokenData = json_decode($tokenResponse->getContent(), true);

            if (!isset($tokenData['data']['accessToken'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to get authentication token'
                ], 400);
            }

            $accessToken = $tokenData['data']['accessToken'];

            // Verify BVN
            $bvnPayload = [
                'bvn' => $request->bvn,
                'name' => $request->first_name . ' ' . $request->last_name,
                'dateOfBirth' => date('d-M-Y', strtotime($request->date_of_birth)),
                'mobileNo' => $request->user()->phone_number
            ];

            Log::info('BVN verification request:', [
                'endpoint' => $this->baseUrl . '/api/v1/vas/bvn-details-match',
                'payload' => $bvnPayload
            ]);

            $bvnResponse = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->baseUrl . '/api/v1/vas/bvn-details-match', $bvnPayload);

            $bvnResponseData = $bvnResponse->json();
            Log::info('BVN verification response:', [
                'status' => $bvnResponse->status(),
                'headers' => $bvnResponse->headers(),
                'body' => $bvnResponseData
            ]);

            if (!$bvnResponse->successful() || !$bvnResponseData['requestSuccessful']) {
                Log::error('BVN verification failed', [
                    'status' => $bvnResponse->status(),
                    'response' => $bvnResponseData,
                    'error' => $bvnResponseData['responseMessage'] ?? 'Unknown error'
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'BVN verification failed',
                    'error' => $bvnResponseData['responseMessage'] ?? 'BVN verification failed'
                ], 400);
            }

            // Check BVN match status
            $bvnData = $bvnResponseData['responseBody'];
            $nameMatch = $bvnData['name']['matchStatus'] !== 'NO_MATCH';
            $dobMatch = $bvnData['dateOfBirth'] !== 'NO_MATCH';

            if (!$nameMatch || !$dobMatch) {
                return response()->json([
                    'status' => false,
                    'message' => 'BVN details do not match provided information',
                    'errors' => [
                        'name' => !$nameMatch ? 'Name does not match BVN record' : null,
                        'dateOfBirth' => !$dobMatch ? 'Date of birth does not match BVN record' : null
                    ]
                ], 400);
            }

            // Verify NIN
            $ninResponse = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->baseUrl . '/api/v1/vas/nin-details', [
                    'nin' => $request->nin
                ]);

            Log::info('NIN verification response:', $ninResponse->json());

            if (!$ninResponse->successful() || !$ninResponse->json()['requestSuccessful']) {
                return response()->json([
                    'status' => false,
                    'message' => 'NIN verification failed',
                    'error' => $ninResponse->json()['responseMessage'] ?? 'NIN verification failed'
                ], 400);
            }

            // Store bill image
            $billImage = $request->file('bill_image');
            $billImagePath = $billImage->store('kyc/bills', 'public');

            // Create KYC record
            $kyc = new Kyc([
                'user_id' => $request->user()->id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'gender' => $request->gender,
                'date_of_birth' => $request->date_of_birth,
                'bvn' => $request->bvn,
                'nin' => $request->nin,
                'state' => $request->state,
                'local_government' => $request->local_government,
                'address' => $request->address,
                'house_number' => $request->house_number,
                'utility_bill_type' => $request->utility_bill_type,
                'bill_image' => $billImagePath,
                'bvn_verified' => true,
                'nin_verified' => true
            ]);

            $kyc->save();

            // Update user's profile
            $request->user()->profile->update([
                'kyc_verified' => true
            ]);

            return response()->json([
                'status' => true,
                'message' => 'KYC information saved successfully',
                'data' => $kyc
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing KYC: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing KYC',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
