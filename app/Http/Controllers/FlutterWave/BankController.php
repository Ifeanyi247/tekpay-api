<?php

namespace App\Http\Controllers\FlutterWave;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransferTransaction;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankController extends Controller
{
    /**
     * Get list of Nigerian banks from Flutterwave
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNigerianBanks()
    {
        try {
            // Try to get banks from cache first (cache for 24 hours)
            $banks = Cache::remember('nigerian_banks', 86400, function () {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('FLUTTERWAVE_SECRET_KEY'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->get('https://api.flutterwave.com/v3/banks/NG');

                if (!$response->successful()) {
                    Log::error('Failed to fetch Nigerian banks', [
                        'status' => $response->status(),
                        'response' => $response->json()
                    ]);
                    throw new \Exception($response->json()['message'] ?? 'Failed to fetch banks');
                }

                return $response->json()['data'];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Banks retrieved successfully',
                'data' => $banks
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching Nigerian banks', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify bank account details using Flutterwave API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyAccount(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'account_number' => 'required|string|size:10',
                'account_bank' => 'required|string'
            ]);

            // Make API request to Flutterwave
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('FLUTTERWAVE_SECRET_KEY'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post('https://api.flutterwave.com/v3/accounts/resolve', [
                'account_number' => $request->account_number,
                'account_bank' => $request->account_bank
            ]);

            // Log the response for debugging
            Log::info('Bank account verification response', [
                'account_number' => $request->account_number,
                'account_bank' => $request->account_bank,
                'response' => $response->json()
            ]);

            // Check if request was successful
            if (!$response->successful()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $response->json()['message'] ?? 'Failed to verify account',
                    'data' => $response->json()
                ], $response->status());
            }

            // Return successful response
            return response()->json([
                'status' => 'success',
                'message' => 'Account verified successfully',
                'data' => $response->json()['data']
            ]);
        } catch (\Exception $e) {
            Log::error('Bank account verification failed', [
                'error' => $e->getMessage(),
                'account_number' => $request->account_number ?? null,
                'account_bank' => $request->account_bank ?? null
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initiate a bank transfer using Flutterwave API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiateTransfer(Request $request)
    {
        // Start database transaction
        DB::beginTransaction();

        try {
            // Validate request
            $request->validate([
                'account_bank' => 'required|string',
                'bank' => 'required|string',
                'account_number' => 'required|string|size:10',
                'account_name' => 'required|string|max:100',
                'amount' => 'required|numeric|min:100',
                'narration' => 'required|string|max:100',
            ]);

            $user = auth()->user();

            // Check if user has sufficient balance
            if ($user->profile->wallet < $request->amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient wallet balance'
                ], 400);
            }

            // Generate unique reference
            $reference = 'TRF_' . time() . '_' . Str::random(8);

            // Prepare transfer payload
            $transferData = [
                'account_bank' => $request->account_bank,
                'account_number' => $request->account_number,
                'amount' => $request->amount,
                'narration' => $request->narration,
                'currency' => 'NGN',
                'reference' => $reference,
                // 'callback_url' => route('api.flutterwave.transfer.webhook'),
                'debit_currency' => 'NGN'
            ];

            // Create pending transaction
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'request_id' => $reference,
                'transaction_id' => null, // Will be updated in webhook
                'reference' => $reference,
                'amount' => $request->amount,
                'total_amount' => $request->amount,
                'type' => 'Transfer',
                'status' => 'success',
                'platform' => 'flutterwave',
                'channel' => 'bank_transfer',
                'method' => 'bank_transfer',
                'service_id' => 'Bank Transfer',
                'product_name' => 'Bank Transfer - ' . $request->account_name,
                'response_code' => '00',
                'response_message' => 'Pending',
                'phone' => $user->phone_number
            ]);

            // Make API request to Flutterwave
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('FLUTTERWAVE_SECRET_KEY'),
                'Content-Type' => 'application/json'
            ])->post('https://api.flutterwave.com/v3/transfers', $transferData);

            // Log the response for debugging
            Log::info('Bank transfer initiation response', [
                'user_id' => $user->id,
                'reference' => $reference,
                'response' => $response->json() ?? 'No response received',
                'status_code' => $response->status(),
                'raw_response' => $response->body()
            ]);

            if (!$response->successful() || empty($response->json())) {
                // Rollback transaction if API call fails
                DB::rollBack();

                $errorMessage = $response->json()['message'] ?? 'Failed to connect to Flutterwave. Please try again later.';

                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage,
                    'data' => [
                        'status_code' => $response->status(),
                        'response' => $response->json() ?? 'No response received'
                    ]
                ], $response->status() ?: 500);
            }

            // Deduct from user's wallet
            $user->profile->decrement('wallet', $request->amount);

            // Update transaction with Flutterwave's response
            $transaction->update([
                'transaction_id' => $response->json()['data']['id'] ?? null,
                'response_message' => 'Transfer initiated successfully'
            ]);

            // create transfertransaction data
            TransferTransaction::create([
                'user_id' => $user->id,
                'account_name' => $request->account_name,
                'account_number' => $request->account_number,
                'amount' => $request->amount,
                'account_bank' => $request->bank,
                'account_code' => $request->account_bank,
            ]);

            // Commit the transaction
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Transfer initiated successfully',
                'data' => [
                    'reference' => $reference,
                    'transfer_details' => $response->json()['data']
                ]
            ]);
        } catch (\Exception $e) {
            // Rollback transaction on any error
            DB::rollBack();

            Log::error('Bank transfer initiation failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle transfer webhook from Flutterwave
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleTransferWebhook(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('Received transfer webhook', ['payload' => $payload]);

            // Find the transaction
            $transaction = Transaction::where('reference', $payload['reference'])->first();

            if (!$transaction) {
                Log::error('Transfer webhook: Transaction not found', [
                    'reference' => $payload['reference']
                ]);
                return response()->json(['status' => 'error', 'message' => 'Transaction not found']);
            }

            // Update transaction status based on webhook status
            $status = strtolower($payload['status']);
            $transaction->update([
                'status' => $status,
                'response_message' => $payload['complete_message'] ?? $payload['status']
            ]);

            // If transfer failed, refund the user
            if (in_array($status, ['failed', 'reversed'])) {
                $user = User::find($transaction->user_id);
                $user->profile->increment('wallet', $transaction->amount);

                Log::info('Refunded failed transfer', [
                    'user_id' => $user->id,
                    'amount' => $transaction->amount,
                    'reference' => $transaction->reference
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Transfer webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Search for a user by email or username
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'search' => 'required|string|min:3',
            ]);

            $search = $validated['search'];

            $user = User::where(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%")
                    ->orWhere('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%");
            })
                ->where('id', '!=', auth()->id()) // Exclude current user
                ->select(['id', 'first_name', 'last_name', 'email']) // Only select necessary fields
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'User found successfully',
                'data' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error searching for user', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while searching for user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transfer money to another user in the app
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function inAppTransfer(Request $request)
    {
        try {
            $validated = $request->validate([
                'recipient_id' => 'required|exists:users,id',
                'amount' => 'required|numeric|min:100',
                'narration' => 'nullable|string|max:100'
            ]);

            $sender = auth()->user();
            $recipient = User::findOrFail($validated['recipient_id']);
            $amount = $validated['amount'];
            $narration = $validated['narration'] ?? 'In-app transfer';

            // Check if sender has sufficient balance
            if ($sender->profile->wallet < $amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient balance'
                ], 400);
            }

            // Start database transaction
            DB::beginTransaction();
            try {
                // Generate request ID and references
                $requestId = 'REQ_' . Str::random(10);
                $senderReference = 'TRF_' . Str::random(10);
                $recipientReference = 'RCV_' . Str::random(10);

                // Transfer Transaction record
                TransferTransaction::create([
                    'user_id' => $sender->id,
                    'account_name' => $recipient->first_name . ' ' . $recipient->last_name,
                    'account_number' => "",
                    'amount' => $amount,
                    'account_bank' => "Tekpay",
                    'account_code' => "Tekpay"
                ]);

                // Create transaction record
                $transaction = Transaction::create([
                    'user_id' => $sender->id,
                    'request_id' => $requestId,
                    'transaction_id' => Str::random(15),
                    'reference' => $senderReference,
                    'amount' => $amount,
                    'commission' => 0,
                    'total_amount' => $amount,
                    'type' => 'transfer',
                    'status' => 'success',
                    'service_id' => 'WALLET_TRANSFER',
                    'phone' => $sender->phone_number,
                    'product_name' => 'Wallet Transfer',
                    'platform' => 'wallet',
                    'channel' => 'wallet',
                    'method' => 'wallet',
                    'response_code' => '00',
                    'response_message' => 'Successful',
                    'transaction_date' => now(),
                    'purchased_code' => null,
                    'pin' => null,
                    'cards' => null
                ]);

                // Create recipient's transaction record
                $recipientTransaction = Transaction::create([
                    'user_id' => $recipient->id,
                    'request_id' => 'RCV_' . $requestId,
                    'transaction_id' => Str::random(15),
                    'reference' => $recipientReference,
                    'amount' => $amount,
                    'commission' => 0,
                    'total_amount' => $amount,
                    'type' => 'credit',
                    'status' => 'success',
                    'service_id' => 'WALLET_TRANSFER',
                    'phone' => $recipient->phone_number,
                    'product_name' => 'Wallet Transfer',
                    'platform' => 'wallet',
                    'channel' => 'wallet',
                    'method' => 'wallet',
                    'response_code' => '00',
                    'response_message' => 'Successful',
                    'transaction_date' => now(),
                    'purchased_code' => null,
                    'pin' => null,
                    'cards' => null
                ]);

                // Update balances
                $sender->profile->decrement('wallet', $amount);
                $recipient->profile->increment('wallet', $amount);

                DB::commit();

                // Send notifications
                app(NotificationService::class)->notifyTransaction($sender->id, $transaction);
                app(NotificationService::class)->notifyTransaction($recipient->id, $recipientTransaction);

                // Refresh sender to get updated wallet balance
                $sender->refresh();

                return response()->json([
                    'status' => true,
                    'message' => 'Transfer successful',
                    'data' => [
                        'transaction' => $senderReference,
                        'amount' => number_format($amount, 2),
                        'recipient' => [
                            'id' => $recipient->id,
                            'name' => $recipient->first_name . ' ' . $recipient->last_name
                        ],
                        'balance' => number_format($sender->profile->wallet, 2)
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error processing in-app transfer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing the transfer',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
