<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

    public function updateProfile(Request $request)
    {
        $user = $request->user();

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

            if (!empty($userFields)) {
                $user->update($userFields);
            }

            // Handle profile image upload
            if ($request->hasFile('profile_image')) {
                // Delete old image if exists
                if ($user->profile->profile_url) {
                    $oldPath = str_replace(url('storage'), 'public', $user->profile->profile_url);
                    Storage::delete($oldPath);
                }

                // Store new image
                $path = $request->file('profile_image')->store('public/profile-images');
                $url = Storage::url($path);

                $user->profile->update([
                    'profile_url' => url($url)
                ]);
                $user->load('profile');
            } else {
                $user->profile->update([
                    'profile_url' => 'https://ui-avatars.com/api/?name=' . urlencode($user->first_name . ' ' . $user->last_name)
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $user,
                    'profile' => $user->profile
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
