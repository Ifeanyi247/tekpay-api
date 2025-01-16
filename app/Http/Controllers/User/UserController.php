<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $user = User::where('id', $request->user()->id)->with('profile')->first();

        return response()->json([
            'status' => true,
            'data' => [
                'user' => $user,
                'profile' => $user->profile
            ]
        ]);
    }

    public function transactions(Request $request)
    {
        $transactions = Transaction::where('user_id', auth()->id())
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => $transactions
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        Log::info('Starting profile update for user: ' . $user->id);

        $validator = Validator::make($request->all(), [
            'username' => [
                'sometimes',
                'string',
                Rule::unique('users')->ignore($user->id)
            ],
            'first_name' => 'sometimes|string',
            'last_name' => 'sometimes|string',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users')->ignore($user->id)
            ],
            'phone_number' => [
                'sometimes',
                'string',
                Rule::unique('users')->ignore($user->id)
            ],
            'profile_image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Update user fields if provided
            $userFields = $request->only([
                'username',
                'first_name',
                'last_name',
                'email',
                'phone_number'
            ]);

            Log::info('Updating user fields', $userFields);

            if (!empty($userFields)) {
                $user->update($userFields);
            }

            // Handle profile image upload
            if ($request->hasFile('profile_image')) {
                Log::info('Profile image uploaded for user: ' . $user->id);

                // Delete old image if exists
                if ($user->profile->profile_url) {
                    $oldPath = str_replace(url('storage'), 'public', $user->profile->profile_url);
                    Storage::delete($oldPath);
                }

                // Store new image
                $path = $request->file('profile_image')->store('public/profile-images');
                Log::info('New profile image path: ' . $path);

                $url = Storage::url($path);
                Log::info('New profile image URL: ' . $url);

                $user->profile->update([
                    'profile_url' => url($url)
                ]);
                $user->load('profile');
            }

            Log::info('Profile updated successfully for user: ' . $user->id);

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $user,
                    'profile' => $user->profile
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating profile for user: ' . $user->id, ['error' => $e->getMessage()]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
