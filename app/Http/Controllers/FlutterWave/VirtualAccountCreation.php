<?php

namespace App\Http\Controllers\FlutterWave;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VirtualAccountCreation extends Controller
{
    /**
     * Create a virtual account using Flutterwave API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createVirtualAccount(Request $request)
    {
        try {
            $email = auth()->user()->email;
            $phonenumber = auth()->user()->phone_number;
            $firstname = auth()->user()->first_name;
            $lastname = auth()->user()->last_name;
            $amount = 1000;

            $narration = "Tekpay/" . $firstname . " " . $lastname;

            // Generate unique transaction reference
            $transaction_ref = 'TXN_' . time() . '_' . Str::random(8);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('FLUTTERWAVE_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post('https://api.flutterwave.com/v3/virtual-account-numbers', [
                'email' => $email,
                'tx_ref' => $transaction_ref,
                'phonenumber' => $phonenumber,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'narration' => $narration,
                'amount' => $amount
            ]);

            $responseData = $response->json();
            $responseData['transaction_ref'] = $transaction_ref;

            return response()->json($responseData);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Flutterwave virtual account transaction webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request)
    {
        try {
            $payload = $request->all();

            // Log the incoming webhook payload
            Log::info('Received virtual account webhook', ['payload' => $payload]);

            // Verify if transaction is successful
            if ($payload['status'] !== 'successful') {
                Log::warning('Virtual account transaction not successful', [
                    'status' => $payload['status'],
                    'tx_ref' => $payload['txRef'],
                    'amount' => $payload['amount'],
                    'customer' => $payload['customer'] ?? null,
                    'entity' => $payload['entity'] ?? null
                ]);

                return response()->json(['status' => 'error', 'message' => 'Transaction not successful']);
            }

            // Find user by email
            $user = User::where('email', $payload['customer']['email'])->first();
            if (!$user) {
                Log::error('User not found for virtual account transaction', [
                    'email' => $payload['customer']['email'],
                    'tx_ref' => $payload['txRef']
                ]);
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            // Use database transaction to ensure data consistency
            DB::beginTransaction();
            try {
                // Create transaction record
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'request_id' => $payload['id'],
                    'transaction_id' => $payload['id'],
                    'reference' => $payload['txRef'],
                    'amount' => $payload['amount'],
                    'total_amount' => $payload['charged_amount'],
                    'commission' => $payload['appfee'],
                    'type' => 'deposit',
                    'status' => 'successful',
                    'platform' => 'flutterwave',
                    'channel' => 'virtual_account',
                    'method' => 'bank_transfer',
                    'response_code' => '00',
                    'response_message' => 'Successful',
                    'transaction_date' => $payload['createdAt'],
                    'phone' => $payload['customer']['phone'],
                    'service_id' => "Deposit",
                    'product_name' => "Deposit",
                ]);

                // Update user's wallet balance
                $user->profile->increment('wallet', $payload['amount']);

                DB::commit();

                // Log successful transaction
                Log::info('Virtual account deposit successful', [
                    'user_id' => $user->id,
                    'amount' => $payload['amount'],
                    'reference' => $payload['txRef']
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'data' => $transaction
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Virtual account webhook processing failed', [
                    'error' => $e->getMessage(),
                    'payload' => $payload
                ]);
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Virtual account webhook error', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }
}
