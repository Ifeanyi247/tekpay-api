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
        Log::info('Fetching device tokens for user', [
            'user_id' => $userId
        ]);

        $deviceTokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('device_token')
            ->toArray();

        if (empty($deviceTokens)) {
            Log::warning('No active device tokens found for user', [
                'user_id' => $userId
            ]);
            return false;
        }

        Log::info('Found device tokens for user', [
            'user_id' => $userId,
            'token_count' => count($deviceTokens)
        ]);

        return $this->sendToTokens($deviceTokens, $title, $body, $data);
    }

    public function sendToTokens($tokens, $title, $body, $data = [])
    {
        if (empty($this->accessToken)) {
            Log::info('Refreshing FCM access token');
            $this->refreshAccessToken();
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $message = [
            'message' => [
                'token' => $tokens[0],
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                'data' => array_merge($data, [
                    'title' => $title,
                    'body' => $body,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                ]),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'high_importance_channel',
                        'default_sound' => true,
                        'default_vibrate_timings' => true,
                        'default_light_settings' => true
                    ]
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10'
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1
                        ]
                    ]
                ]
            ]
        ];

        try {
            $successCount = 0;
            $failureCount = 0;
            $errors = [];

            // Send to each token (FCM v1 only supports one token per request)
            foreach ($tokens as $token) {
                Log::info('Sending push notification', [
                    'token' => substr($token, 0, 6) . '...', // Only log first 6 chars for security
                    'title' => $title,
                    'body' => $body
                ]);

                $message['message']['token'] = $token;

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ])->post($url, $message);

                if (!$response->successful()) {
                    $error = $response->json();
                    $failureCount++;

                    Log::error('FCM Error', [
                        'token' => substr($token, 0, 6) . '...',
                        'error' => $error,
                        'status' => $response->status()
                    ]);

                    if (
                        isset($error['error']['status']) &&
                        in_array($error['error']['status'], ['UNREGISTERED', 'INVALID_ARGUMENT'])
                    ) {
                        Log::info('Removing invalid device token', [
                            'token' => substr($token, 0, 6) . '...'
                        ]);
                        // Remove invalid token
                        DeviceToken::where('device_token', $token)->delete();
                    }

                    $errors[] = [
                        'token' => substr($token, 0, 6) . '...',
                        'error' => $error
                    ];
                } else {
                    $successCount++;
                    Log::info('Push notification sent successfully', [
                        'token' => substr($token, 0, 6) . '...'
                    ]);
                }
            }

            Log::info('Push notification batch complete', [
                'total_tokens' => count($tokens),
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'errors' => $errors
            ]);

            return $successCount > 0;
        } catch (\Exception $e) {
            Log::error('FCM Send Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
