<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|unique:users,username',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone_number' => 'required|string|unique:users,phone_number',
            'password' => 'required|string|min:6',
            'confirm_password' => 'required|same:password',
            'referred_by' => 'nullable|string|exists:users,username'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate OTP
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Store OTP in cache with email as key (valid for 10 minutes)
        Cache::put('registration_otp_' . $request->email, [
            'otp' => $otp,
            'user_data' => $request->all()
        ], 600);

        // Send OTP email
        try {
            Mail::to($request->email)->send(new SendOtpMail($otp));

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

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string|size:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cached_data = Cache::get('registration_otp_' . $request->email);

        if (!$cached_data || $cached_data['otp'] !== $request->otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }

        // Generate token for PIN creation
        $pin_token = bin2hex(random_bytes(32));

        // Store verified user data in cache with new token (valid for 10 minutes)
        Cache::put('pin_token_' . $pin_token, [
            'email' => $request->email,
            'user_data' => $cached_data['user_data']
        ], 600);

        // Clear OTP data
        Cache::forget('registration_otp_' . $request->email);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully. Please create your PIN to complete registration.',
            'pin_token' => $pin_token
        ]);
    }

    public function createPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pin_token' => 'required|string',
            'pin_code' => 'required|string|size:4|confirmed|regex:/^[0-9]+$/',
            'pin_code_confirmation' => 'required|string|size:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cached_data = Cache::get('pin_token_' . $request->pin_token);

        if (!$cached_data) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token. Please verify your email again.'
            ], 400);
        }

        try {
            $user_data = $cached_data['user_data'];

            // Create user
            $user = User::create([
                'username' => $user_data['username'],
                'first_name' => $user_data['first_name'],
                'last_name' => $user_data['last_name'],
                'email' => $user_data['email'],
                'phone_number' => $user_data['phone_number'],
                'password' => Hash::make($user_data['password']),
                'referred_by' => $user_data['referred_by'] ?? null
            ]);

            // Create profile with pin
            $profile = Profile::create([
                'user_id' => $user->id,
                'pin_code' => (int) $request->pin_code,
                'wallet' => 0,
                'profile_url' => 'https://ui-avatars.com/api/?name=' . urlencode($user->first_name . ' ' . $user->last_name)
            ]);

            // Load the profile relationship
            $user->load('profile');

            // Clear cached data
            Cache::forget('pin_token_' . $request->pin_token);

            // Generate API token for immediate login
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => true,
                'message' => 'Registration completed successfully',
                'data' => [
                    'user' => $user,
                    'profile' => $profile,
                    'token' => $token
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Registration failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Store user ID in cache for PIN verification
        $login_token = bin2hex(random_bytes(32));
        Cache::put('login_' . $login_token, $user->id, 300); // Valid for 5 minutes

        return response()->json([
            'status' => true,
            'message' => 'First step authentication successful. Please verify your PIN.',
            'login_token' => $login_token,
            'username' => $user->username,
            'profile_url' => $user->profile->profile_url,
            'pin_code' => $user->profile->pin_code
        ]);
    }

    public function verifyPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login_token' => 'required|string',
            'pin_code' => 'required|string|size:4|regex:/^[0-9]+$/'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user_id = Cache::get('login_' . $request->login_token);

        if (!$user_id) {
            return response()->json([
                'status' => false,
                'message' => "Time's up. Please login again."
            ], 401);
        }

        $user = User::with('profile')->find($user_id);
        $profile = $user->profile;

        if (!$profile || $profile->pin_code !== (int) $request->pin_code) {
            Cache::forget('login_' . $request->login_token);
            return response()->json([
                'status' => false,
                'message' => 'Invalid PIN'
            ], 401);
        }

        // Clear login token
        Cache::forget('login_' . $request->login_token);

        // $user->profile->update(['wallet' => 20000]);

        // Create API token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'profile' => $profile,
                'token' => $token
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Logged out successfully'
        ]);
    }
}
