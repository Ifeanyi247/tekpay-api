<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Device token registration attempt', [
            'user_id' => auth()->id(),
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'device_token' => 'required|string',
            'device_type' => 'required|in:android,ios,web'
        ]);

        try {
            $deviceToken = DeviceToken::updateOrCreate(
                [
                    'user_id' => auth()->id(),
                    'device_token' => $validated['device_token']
                ],
                [
                    'device_type' => $validated['device_type'],
                    'is_active' => true
                ]
            );

            Log::info('Device token registered successfully', [
                'user_id' => auth()->id(),
                'device_token_id' => $deviceToken->id,
                'device_type' => $deviceToken->device_type
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Device token registered successfully',
                'data' => $deviceToken
            ]);
        } catch (\Exception $e) {
            Log::error('Device token registration failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to register device token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request)
    {
        Log::info('Device token removal attempt', [
            'user_id' => auth()->id(),
            'request_data' => $request->all()
        ]);

        $validated = $request->validate([
            'device_token' => 'required|string'
        ]);

        try {
            $deviceToken = DeviceToken::where('user_id', auth()->id())
                ->where('device_token', $validated['device_token'])
                ->first();

            if ($deviceToken) {
                $deviceToken->delete();

                Log::info('Device token removed successfully', [
                    'user_id' => auth()->id(),
                    'device_token_id' => $deviceToken->id
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Device token removed successfully'
                ]);
            } else {
                Log::warning('Device token not found', [
                    'user_id' => auth()->id(),
                    'device_token' => $validated['device_token']
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Device token not found'
                ], 404);
            }
        } catch (\Exception $e) {
            Log::error('Device token removal failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to remove device token',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
