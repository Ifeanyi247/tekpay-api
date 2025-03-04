<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Models\Profile;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'referral_code' => 'nullable|string|exists:profiles,referral_code'
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
        $userData = $request->all();
        if ($request->has('referral_code')) {
            $userData['referred_by'] = $request->referral_code;
        }

        Cache::put('registration_otp_' . $request->email, [
            'otp' => $otp,
            'user_data' => $userData
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

        $cachedData = Cache::get('pin_token_' . $request->pin_token);

        if (!$cachedData) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token. Please verify your email again.'
            ], 400);
        }

        try {
            $user_data = $cachedData['user_data'];

            // Create user
            $user = User::create([
                'username' => $user_data['username'],
                'first_name' => $user_data['first_name'],
                'last_name' => $user_data['last_name'],
                'email' => $user_data['email'],
                'phone_number' => $user_data['phone_number'],
                'password' => Hash::make($user_data['password'])
            ]);

            // Generate unique referral code
            $referralCode = strtoupper(Str::random(8));
            while (Profile::where('referral_code', $referralCode)->exists()) {
                $referralCode = strtoupper(Str::random(8));
            }

            // Find referrer if username was provided
            $referrerId = null;
            if (!empty($user_data['referred_by'])) {
                $referrerProfile = Profile::where('referral_code', $user_data['referred_by'])->first();
                if ($referrerProfile) {
                    $referrerId = $referrerProfile->user_id;

                    // Update referrer's stats and wallet
                    Profile::where('user_id', $referrerId)->update([
                        'referral_count' => DB::raw('referral_count + 1'),
                        'referral_earnings' => DB::raw('referral_earnings + 10'),
                        'wallet' => DB::raw('wallet + 10')
                    ]);

                    // Create transaction record for referral bonus
                    // generate transaction_id
                    $transactionId = 'REF_' . time() . '_' . Str::random(8);

                    Transaction::create([
                        'user_id' => $referrerId,
                        'request_id' => $transactionId,
                        'transaction_id' => $transactionId,
                        'reference' => 'REF_BONUS_' . $user->id,
                        'amount' => 10,
                        'total_amount' => 10,
                        'type' => 'referral_bonus',
                        'status' => 'success',
                        'platform' => 'system',
                        'channel' => 'referral',
                        'method' => 'system',
                        'service_id' => 'Referral',
                        'product_name' => 'Referral Bonus',
                        'response_code' => '00',
                        'response_message' => 'Referral bonus credited successfully',
                        'phone' => $referrerProfile->user->phone_number
                    ]);
                }
            }

            // Create profile with pin
            Profile::create([
                'profile_url' => "https://ui-avatars.com/api/?name=" . $user->first_name . '+' . $user->last_name,
                'user_id' => $user->id,
                'pin_code' => (int) $request->pin_code,
                'wallet' => 0,
                'referral_code' => $referralCode,
                'referred_by' => $referrerId,
                'referral_count' => 0,
                'referral_earnings' => 0
            ]);

            // Clear pin token from cache
            Cache::forget('pin_token_' . $request->pin_token);

            // Load the profile relationship
            $user->load('profile');

            // Generate API token for immediate login
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => true,
                'message' => 'Registration completed successfully',
                'data' => [
                    'user' => $user,
                    'profile' => $user->profile,
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
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Store user ID in cache for PIN verification
        $loginToken = bin2hex(random_bytes(32));
        Cache::put('login_' . $loginToken, $user->id, 300); // Valid for 5 minutes

        return response()->json([
            'status' => true,
            'message' => 'First step authentication successful. Please verify your PIN.',
            'login_token' => $loginToken,
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

        $userId = Cache::get('login_' . $request->login_token);

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => "Time's up. Please login again."
            ], 401);
        }

        $user = User::with('profile')->find($userId);
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

    /**
     * Delete user account and all associated data
     */
    public function deleteAccount(Request $request)
    {
        try {
            $user = $request->user();

            // // Validate user's password to confirm deletion
            // $validator = Validator::make($request->all(), [
            //     'password' => 'required|string'
            // ]);

            // if ($validator->fails()) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Validation error',
            //         'errors' => $validator->errors()
            //     ], 422);
            // }

            // // Verify password
            // if (!Hash::check($request->password, $user->password)) {
            //     return response()->json([
            //         'status' => false,
            //         'message' => 'Invalid password'
            //     ], 401);
            // }

            DB::beginTransaction();
            try {
                // Delete user's transactions
                Transaction::where('user_id', $user->id)->delete();

                // Delete user's password history
                PasswordHistory::where('user_id', $user->id)->delete();

                // Delete user's profile
                $user->profile()->delete();

                // Delete user's tokens
                $user->tokens()->delete();

                // Finally delete the user
                $user->delete();

                DB::commit();

                return response()->json([
                    'status' => true,
                    'message' => 'Account deleted successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting user account: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendPinChangeOtpNoAuth(Request $request)
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
        $user = User::where('email', $request->email)->first();

        // Generate OTP
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Store OTP in cache with email as key (valid for 10 minutes)
        Cache::put('pin_change_otp_no_auth_' . $user->email, [
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

    public function verifyPinChangeOtpNoAuth(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'otp' => 'required|string|size:4',
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)->first();

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cached_data = Cache::get('pin_change_otp_no_auth_' . $user->email);

        if (!$cached_data || $cached_data['otp'] !== $request->otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }

        // Generate token for PIN change
        $pin_token = bin2hex(random_bytes(32));

        // Store token in cache (valid for 10 minutes)
        Cache::put('pin_change_token_no_auth_' . $pin_token, [
            'user_id' => $user->id
        ], 600);

        // Clear OTP data
        Cache::forget('pin_change_otp_no_auth_' . $user->email);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully',
            'data' => [
                'pin_token' => $pin_token
            ]
        ]);
    }

    public function resendPinChangeOtpNoAuth(Request $request)
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

        $user = User::where('email', $request->email)->first();

        // Check if there's an existing OTP request
        $existing_otp_data = Cache::get('pin_change_otp_no_auth_' . $user->email);

        if ($existing_otp_data) {
            // Generate new OTP
            $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            // Update OTP in cache (reset the 10-minute timer)
            Cache::put('pin_change_otp_no_auth_' . $user->email, [
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

    public function changeTransactionPinNoAuth(Request $request)
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

        $cached_data = Cache::get('pin_change_token_no_auth_' . $request->pin_token);

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
        Cache::forget('pin_change_token_no_auth_' . $request->pin_token);

        return response()->json([
            'status' => true,
            'message' => 'Transaction pin changed successfully'
        ]);
    }

    public function resendRegistrationOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user data exists in cache
        $cached_data = Cache::get('registration_otp_' . $request->email);
        if (!$cached_data || !isset($cached_data['user_data'])) {
            return response()->json([
                'status' => false,
                'message' => 'No pending registration found for this email'
            ], 404);
        }

        // Check if user already exists
        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'User already registered'
            ], 400);
        }

        // Generate new OTP
        $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Update cache with new OTP
        Cache::put('registration_otp_' . $request->email, [
            'otp' => $otp,
            'user_data' => $cached_data['user_data']
        ], 600); // 10 minutes

        // Send OTP email
        try {
            Mail::to($request->email)->send(new SendOtpMail($otp));

            return response()->json([
                'status' => true,
                'message' => 'New OTP has been sent to your email'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resend OTP: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
