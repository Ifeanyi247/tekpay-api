<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\User;
use App\Models\PasswordHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ForgotPasswordController extends Controller
{
    /**
     * Send password reset OTP to user's email
     */
    public function sendResetOtp(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Generate 4 digit OTP
            $otp = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            // Store OTP in cache with email as key (valid for 10 minutes)
            Cache::put('password_reset_otp_' . $request->email, $otp, 600);

            // Send OTP email
            Mail::to($request->email)->send(new SendOtpMail($otp));

            return response()->json([
                'status' => true,
                'message' => 'Password reset OTP has been sent to your email'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send password reset OTP: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify the OTP sent to user's email
     */
    public function verifyOtp(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'otp' => 'required|string|size:4'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify OTP
            $cached_otp = Cache::get('password_reset_otp_' . $request->email);

            if (!$cached_otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired. Please request a new one.'
                ], 400);
            }

            if ($cached_otp !== $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 400);
            }

            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));

            // Store token in cache (valid for 10 minutes)
            Cache::put('password_reset_token_' . $request->email, $reset_token, 600);

            // Clear OTP from cache since it's been verified
            Cache::forget('password_reset_otp_' . $request->email);

            return response()->json([
                'status' => true,
                'message' => 'OTP verified successfully',
                'data' => [
                    'reset_token' => $reset_token
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to verify OTP: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to verify OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify OTP and reset password
     */
    public function verifyAndResetPassword(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'reset_token' => 'required|string',
                'new_password' => 'required|string|min:6',
                'confirm_password' => 'required|same:new_password'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify reset token
            $cached_token = Cache::get('password_reset_token_' . $request->email);

            if (!$cached_token || $cached_token !== $request->reset_token) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired reset token'
                ], 400);
            }

            // Get user
            $user = User::where('email', $request->email)->first();

            // Check if new password is same as current password
            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'New password cannot be the same as your current password'
                ], 400);
            }

            // Check password history (last 3 passwords)
            $previousPasswords = PasswordHistory::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->take(3)
                ->get();

            foreach ($previousPasswords as $oldPassword) {
                if (Hash::check($request->new_password, $oldPassword->password)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Password has been used recently. Please choose a different password.'
                    ], 400);
                }
            }

            // Store current password in history before updating
            PasswordHistory::create([
                'user_id' => $user->id,
                'password' => $user->password
            ]);

            // Update password
            $user->password = Hash::make($request->new_password);
            $user->save();

            // Clear reset token from cache
            Cache::forget('password_reset_token_' . $request->email);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Password has been reset successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reset password: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to reset password',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
