<?php

namespace App\Http\Controllers\FlutterWave;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransferTransaction;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handleWebhook(Request $request)
    {
        try {
            $payload = $request->all();
            $event = $payload['event'] ?? null;
            $eventType = $payload['event.type'] ?? null;

            Log::info('Received Flutterwave webhook', [
                'event' => $event,
                'event_type' => $eventType,
                'payload' => $payload
            ]);

            switch ($event) {
                case 'charge.completed':
                    if ($eventType === 'BANK_TRANSFER_TRANSACTION') {
                        return $this->handleVirtualAccountDeposit($payload);
                    }
                    break;

                case 'transfer.completed':
                    if ($eventType === 'Transfer') {
                        return $this->handleTransferWebhook($payload);
                    }
                    break;

                default:
                    Log::warning('Unhandled webhook event', [
                        'event' => $event,
                        'event_type' => $eventType
                    ]);
                    return response()->json(['status' => 'ignored', 'message' => 'Unhandled webhook event']);
            }
        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error'
            ], 500);
        }
    }

    protected function handleVirtualAccountDeposit(array $payload)
    {
        try {
            $data = $payload['data'];

            // Find user by email
            $user = User::where('email', $data['customer']['email'])->first();
            if (!$user) {
                Log::error('User not found for virtual account transaction', [
                    'email' => $data['customer']['email'],
                    'tx_ref' => $data['tx_ref']
                ]);
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }

            DB::beginTransaction();

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'request_id' => $data['id'],
                'transaction_id' => $data['id'],
                'reference' => $data['tx_ref'],
                'amount' => $data['amount'],
                'total_amount' => $data['charged_amount'],
                'commission' => $data['app_fee'],
                'type' => 'deposit',
                'status' => strtolower($data['status']) === 'successful' ? 'successful' : 'failed',
                'platform' => 'flutterwave',
                'channel' => 'virtual_account',
                'method' => 'bank_transfer',
                'response_code' => '00',
                'response_message' => $data['processor_response'],
                'transaction_date' => $data['created_at'],
                'phone' => $user->phone_number,
                'service_id' => "Deposit",
                'product_name' => "Deposit",
            ]);

            // Update user's wallet balance if successful
            if (strtolower($data['status']) === 'successful') {
                $user->profile->increment('wallet', $data['amount']);
            }

            DB::commit();

            // Send notification
            $this->notificationService->notifyTransaction($user->id, $transaction);

            Log::info('Virtual account deposit processed', [
                'user_id' => $user->id,
                'amount' => $data['amount'],
                'status' => $data['status'],
                'reference' => $data['tx_ref']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Virtual account webhook processed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function handleTransferWebhook(array $payload)
    {
        try {
            $data = $payload['data'];

            // Log the incoming webhook payload
            Log::info('Received transfer webhook', ['payload' => $payload]);

            // // Find the transfer transaction
            // $transferTxn = TransferTransaction::where('reference', $data['reference'])->first();
            // if (!$transferTxn) {
            //     Log::error('Transfer transaction not found', [
            //         'reference' => $data['reference']
            //     ]);
            //     return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
            // }

            // DB::beginTransaction();

            // // Update transfer status
            // $transferTxn->update([
            //     'status' => strtolower($data['status']),
            //     'complete_message' => $data['complete_message']
            // ]);

            // Find the related transaction
            $transaction = Transaction::where('reference', $data['reference'])->first();
            if ($transaction) {
                $transaction->update([
                    'status' => strtolower($data['status']),
                    'response_message' => $data['complete_message']
                ]);

                // If transfer failed, refund the user
                if (strtolower($data['status']) === 'failed') {
                    $user = User::find($transaction->user_id);
                    if ($user) {
                        $user->profile->increment('wallet', $data['amount']);

                        // Create refund transaction
                        Transaction::create([
                            'user_id' => $user->id,
                            'request_id' => $data['id'],
                            'transaction_id' => $data['id'] . '_refund',
                            'reference' => $data['reference'] . '_refund',
                            'amount' => $data['amount'],
                            'total_amount' => $data['amount'],
                            'type' => 'refund',
                            'status' => 'successful',
                            'platform' => 'flutterwave',
                            'channel' => 'transfer',
                            'method' => 'wallet',
                            'response_code' => '00',
                            'response_message' => 'Transfer failed - Amount refunded',
                            'transaction_date' => now(),
                            'service_id' => 'Refund',
                            'product_name' => 'Failed Transfer Refund'
                        ]);
                    }
                }

                // Send notification
                $this->notificationService->notifyTransaction($transaction->user_id, $transaction);
            }

            DB::commit();

            Log::info('Transfer webhook processed', [
                'reference' => $data['reference'],
                'status' => $data['status'],
                'message' => $data['complete_message']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Transfer webhook processed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
