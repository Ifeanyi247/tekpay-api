<?php

namespace App\Http\Controllers\FlutterWave;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BvnController extends Controller
{
    private $baseUrl = 'https://api.flutterwave.com/v3';

    /**
     * Verify BVN using Flutterwave API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyBvn(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'bvn' => 'required|string|size:11',
                'firstname' => 'required|string',
                'lastname' => 'required|string',
            ]);

            $callback_url = "https://webhook.site/71b7c5ec-3e68-4040-83a2-5a93489bbb4e";

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Make API request to Flutterwave
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.flutterwave.secret_key')
            ])->post($this->baseUrl . '/bvn/verifications', [
                'bvn' => $request->bvn,
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'callback_url' => $callback_url
            ]);

            // Log the response for debugging
            Log::info('Flutterwave BVN verification response', [
                'status_code' => $response->status(),
                'response' => $response->json()
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'status' => false,
                    'message' => 'BVN verification failed',
                    'error' => $response->json()
                ], $response->status());
            }

            return response()->json([
                'status' => true,
                'message' => 'BVN verification initiated successfully',
                'data' => $response->json()
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying BVN: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Error processing BVN verification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
