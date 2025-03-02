<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Client;
use Google\Service\FirebaseCloudMessaging;

class PushNotificationService
{
    protected $projectId;
    protected $accessToken;

    public function __construct()
    {
        $this->projectId = config('services.fcm.project_id');
        $this->refreshAccessToken();
    }

    protected function refreshAccessToken()
    {
        try {
            $client = new Client();
            $client->setAuthConfig(storage_path('app/firebase-service-account.json'));
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $this->accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];
        } catch (\Exception $e) {
            Log::error('FCM Auth Error: ' . $e->getMessage());
        }
    }

    public function sendToUser($userId, $title, $body, $data = [])
    {
        $deviceTokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('device_token')
            ->toArray();

        if (empty($deviceTokens)) {
            return false;
        }

        return $this->sendToTokens($deviceTokens, $title, $body, $data);
    }

    public function sendToTokens($tokens, $title, $body, $data = [])
    {
        if (empty($this->accessToken)) {
            $this->refreshAccessToken();
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
        
        $message = [
            'message' => [
                'token' => $tokens[0], // Send to first token
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default'
                    ]
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default'
                        ]
                    ]
                ]
            ]
        ];

        try {
            // Send to each token (FCM v1 only supports one token per request)
            foreach ($tokens as $token) {
                $message['message']['token'] = $token;
                
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ])->post($url, $message);

                if (!$response->successful()) {
                    $error = $response->json();
                    if (isset($error['error']['status']) && 
                        in_array($error['error']['status'], ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                        // Remove invalid token
                        DeviceToken::where('device_token', $token)->delete();
                    }
                    Log::error('FCM Error', [
                        'token' => $token,
                        'error' => $response->body()
                    ]);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('FCM Send Error: ' . $e->getMessage());
            return false;
        }
    }
}
