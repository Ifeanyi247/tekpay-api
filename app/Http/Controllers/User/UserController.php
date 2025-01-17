<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Mail\SendOtpMail;
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

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect'
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    public function changeTransactionPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pin_token' => 'required|string',
            'new_pin' => 'required|digits:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cached_data = Cache::get('pin_change_token_' . $request->pin_token);

        if (!$cached_data) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token'
            ], 400);
        }

        $user = User::find($cached_data['user_id']);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->profile->pin_code = $request->new_pin;
        $user->profile->save();

        // Clear token
        Cache::forget('pin_change_token_' . $request->pin_token);

        return response()->json([
            'status' => true,
            'message' => 'Transaction pin changed successfully'
        ]);
    }

    public function sendPinChangeOtp(Request $request)
    {

        // validate email
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
        $user = $request->user();

        // Generate OTP
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Store OTP in cache with email as key (valid for 10 minutes)
        Cache::put('pin_change_otp_' . $user->email, [
            'otp' => $otp,
            'user_id' => $user->id
        ], 600);

        // Send OTP email
        try {
            Mail::to($user->email)->send(new SendOtpMail($otp));

            return response()->json([
                'status' => true,
                'message' => 'OTP has been sent to your email'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send OTP: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyPinChangeOtp(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'otp' => 'required|string|size:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cached_data = Cache::get('pin_change_otp_' . $user->email);

        if (!$cached_data || $cached_data['otp'] !== $request->otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }

        // Generate token for PIN change
        $pin_token = bin2hex(random_bytes(32));

        // Store token in cache (valid for 10 minutes)
        Cache::put('pin_change_token_' . $pin_token, [
            'user_id' => $user->id
        ], 600);

        // Clear OTP data
        Cache::forget('pin_change_otp_' . $user->email);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully',
            'data' => [
                'pin_token' => $pin_token
            ]
        ]);
    }

    public function resendPinChangeOtp(Request $request)
    {
        // validate email
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Check if there's an existing OTP request
        $existing_otp_data = Cache::get('pin_change_otp_' . $user->email);

        if ($existing_otp_data) {
            // Generate new OTP
            $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            // Update OTP in cache (reset the 10-minute timer)
            Cache::put('pin_change_otp_' . $user->email, [
                'otp' => $otp,
                'user_id' => $user->id
            ], 600);

            // Send new OTP email
            try {
                Mail::to($user->email)->send(new SendOtpMail($otp));

                return response()->json([
                    'status' => true,
                    'message' => 'New OTP has been sent to your email'
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send OTP: ' . $e->getMessage());
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to send new OTP',
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        return response()->json([
            'status' => false,
            'message' => 'No active OTP request found. Please initiate a new PIN change request.'
        ], 400);
    }
}
