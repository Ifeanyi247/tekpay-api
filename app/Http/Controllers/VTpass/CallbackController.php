<?php

namespace App\Http\Controllers\VTpass;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallbackController extends Controller
{
    /**
     * Handle VTpass transaction update webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleTransactionUpdate(Request $request)
    {
        try {
            $payload = $request->all();

            // Validate webhook type
            if ($payload['type'] !== 'transaction-update') {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid webhook type'
                ], 400);
            }

            $transactionData = $payload['data'];
            $transaction = $transactionData['content']['transactions'];

            // Find the transaction
            $dbTransaction = Transaction::where('request_id', $transactionData['requestId'])
                ->orWhere('transaction_id', $transaction['transactionId'])
                ->first();

            if (!$dbTransaction) {
                Log::error('Transaction not found for webhook update', [
                    'request_id' => $transactionData['requestId'],
                    'transaction_id' => $transaction['transactionId']
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            // Start database transaction for atomic operations
            DB::beginTransaction();
            try {
                // Update transaction details
                $dbTransaction->update([
                    'status' => $transaction['status'],
                    'response_code' => $transactionData['code'],
                    'response_message' => $transactionData['response_description'],
                    'amount' => $transaction['amount'],
                    'commission' => $transaction['commission'],
                    'total_amount' => $transaction['total_amount'],
                    'purchased_code' => $transactionData['purchased_code'] ?? null,
                    'transaction_date' => $transactionData['transaction_date']
                ]);

                // Handle transaction reversal
                if ($transaction['status'] === 'reversed' && $transactionData['code'] === '040') {
                    $user = $dbTransaction->user;
                    if ($user) {
                        // Credit user's wallet with the reversed amount
                        $user->profile->increment('wallet', $transaction['total_amount']);

                        Log::info('Wallet credited for reversed transaction', [
                            'user_id' => $user->id,
                            'amount' => $transaction['total_amount'],
                            'transaction_id' => $transaction['transactionId']
                        ]);
                    }
                }

                DB::commit();

                Log::info('Transaction webhook processed successfully', [
                    'request_id' => $transactionData['requestId'],
                    'status' => $transaction['status'],
                    'is_reversal' => $transaction['status'] === 'reversed'
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Transaction updated successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error processing VTpass webhook: ' . $e->getMessage(), [
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error processing webhook',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
