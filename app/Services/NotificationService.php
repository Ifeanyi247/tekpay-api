<?php

namespace App\Services;

use App\Models\Notification;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected $pushNotificationService;

    public function __construct(PushNotificationService $pushNotificationService)
    {
        $this->pushNotificationService = $pushNotificationService;
    }

    public function notifyTransaction($userId, $transaction)
    {
        try {
            Log::info('Creating transaction notification', [
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id,
                'reference' => $transaction->reference,
                'type' => $transaction->type,
                'amount' => $transaction->amount
            ]);

            // Create notification message based on transaction type and status
            $title = $this->getTransactionTitle($transaction);
            $message = $this->getTransactionMessage($transaction);

            // Store notification in database
            $notification = Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'type' => 'transaction',
                'transaction_id' => $transaction->transaction_id ?? $transaction->id,
                'reference' => $transaction->reference,
                'amount' => $transaction->amount,
                'status' => $transaction->status
            ]);

            Log::info('Notification created, sending push notification', [
                'notification_id' => $notification->id,
                'title' => $title,
                'message' => $message
            ]);

            // Send push notification
            $pushResult = $this->pushNotificationService->sendToUser($userId, $title, $message, [
                'type' => 'transaction',
                'transaction_id' => $transaction->transaction_id ?? $transaction->id,
                'status' => $transaction->status
            ]);

            Log::info('Push notification result', [
                'notification_id' => $notification->id,
                'success' => $pushResult
            ]);

            return $notification;
        } catch (\Exception $e) {
            Log::error('Error sending notification', [
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function notifyReferralBonus($userId, $referralBonus)
    {
        $title = 'Referral Bonus Received!';
        $message = "You've earned NGN {$referralBonus} as referral bonus.";

        // Store notification
        $notification = Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => 'referral',
            'amount' => $referralBonus,
            'status' => 'success'
        ]);

        // Send push notification
        $this->pushNotificationService->sendToUser($userId, $title, $message, [
            'type' => 'referral',
            'amount' => $referralBonus
        ]);

        return $notification;
    }

    protected function getTransactionTitle($transaction)
    {
        $status = ucfirst(strtolower($transaction->status));
        $type = ucfirst(strtolower($transaction->type));
        
        return "{$type} {$status}";
    }

    protected function getTransactionMessage($transaction)
    {
        $amount = number_format($transaction->amount, 2);
        $status = strtolower($transaction->status);
        
        switch ($transaction->type) {
            case 'transfer':
                return "Your transfer of NGN {$amount} was {$status}";
            case 'deposit':
                return "Your deposit of NGN {$amount} was {$status}";
            case 'withdrawal':
                return "Your withdrawal of NGN {$amount} was {$status}";
            case 'referral_bonus':
                return "You received NGN {$amount} referral bonus";
            default:
                return "Your transaction of NGN {$amount} was {$status}";
        }
    }
}
