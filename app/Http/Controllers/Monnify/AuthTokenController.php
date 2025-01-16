<?php

namespace App\Http\Controllers\Monnify;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthTokenController extends Controller
{
    private $baseUrl;
    private $apiKey;
    private $secretKey;
    private $httpClient;
    private $oAuth2Token = '';
    private $oAuth2TokenExpires = '';

    public function __construct()
    {
        $this->baseUrl = rtrim(env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'), '/');
        $this->apiKey = env('MONNIFY_API_KEY');
        $this->secretKey = env('MONNIFY_SECRET_KEY');

        // Debug configuration
        Log::info('Monnify Configuration:', [
            'baseUrl' => $this->baseUrl,
            'apiKey' => $this->apiKey,
            'secretKey' => $this->secretKey
        ]);
    }

    public function withBasicAuth()
    {
        // Create basic auth string manually for debugging
        $authString = base64_encode($this->apiKey . ':' . $this->secretKey);
        Log::info('Basic Auth String: ' . $authString);

        $this->httpClient = Http::withHeaders([
            'Authorization' => 'Basic ' . $authString,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ]);

        return $this->httpClient;
    }

    public function getAccessToken()
    {
        try {
            Log::info('Generating Monnify access token');

            $endpoint = "{$this->baseUrl}/api/v1/auth/login";
            Log::info('Monnify endpoint: ' . $endpoint);

            $response = $this->withBasicAuth()->post($endpoint);

            // Log the raw response for debugging
            Log::info('Raw response:', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['requestSuccessful']) {
                    // Store token and expiry
                    $this->oAuth2Token = $data['responseBody']['accessToken'];
                    $this->oAuth2TokenExpires = time() + $data['responseBody']['expiresIn'] - 60;

                    return response()->json([
                        'status' => true,
                        'message' => 'Access token generated successfully',
                        'data' => [
                            'accessToken' => $this->oAuth2Token,
                            'expiresIn' => $data['responseBody']['expiresIn']
                        ]
                    ]);
                }

                Log::error('Monnify auth failed', [
                    'message' => $data['responseMessage'] ?? 'Unknown error',
                    'code' => $data['responseCode'] ?? 'Unknown code'
                ]);

                return response()->json([
                    'status' => false,
                    'message' => $data['responseMessage'] ?? 'Failed to generate access token'
                ], 400);
            }

            Log::error('Monnify auth request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'headers' => $response->headers()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to connect to Monnify',
                'error' => $response->body()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Error generating Monnify access token', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while generating access token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function withOAuth2()
    {
        if (time() >= $this->oAuth2TokenExpires) {
            $this->getAccessToken();
            $this->httpClient = Http::withToken($this->oAuth2Token);
        }

        return $this->httpClient;
    }
}
