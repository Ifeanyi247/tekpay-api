<?php

namespace App\Traits;

trait VTPassResponseHandler
{
    protected function getResponseMessage(string $code): array
    {
        $responses = [
            '000' => ['message' => 'Transaction processed successfully', 'status' => true],
            '099' => ['message' => 'Transaction is processing', 'status' => true],
            '001' => ['message' => 'Transaction query successful', 'status' => true],
            '044' => ['message' => 'Transaction has been resolved', 'status' => true],
            '091' => ['message' => 'Transaction not processed', 'status' => false],
            '016' => ['message' => 'Transaction failed', 'status' => false],
            '010' => ['message' => 'Invalid variation code', 'status' => false],
            '011' => ['message' => 'Invalid arguments provided', 'status' => false],
            '012' => ['message' => 'Product does not exist', 'status' => false],
            '013' => ['message' => 'Amount is below minimum allowed', 'status' => false],
            '014' => ['message' => 'Request ID already exists', 'status' => false],
            '015' => ['message' => 'Invalid request ID', 'status' => false],
            '017' => ['message' => 'Amount is above maximum allowed', 'status' => false],
            '018' => ['message' => 'Insufficient wallet balance', 'status' => false],
            '019' => ['message' => 'Possible duplicate transaction', 'status' => false],
            '021' => ['message' => 'Account is locked', 'status' => false],
            '022' => ['message' => 'Account is suspended', 'status' => false],
            '023' => ['message' => 'API access not enabled', 'status' => false],
            '024' => ['message' => 'Account is inactive', 'status' => false],
            '025' => ['message' => 'Invalid recipient bank', 'status' => false],
            '026' => ['message' => 'Recipient account verification failed', 'status' => false],
            '027' => ['message' => 'IP not whitelisted', 'status' => false],
            '028' => ['message' => 'Product not whitelisted for your account', 'status' => false],
            '030' => ['message' => 'Biller not reachable', 'status' => false],
            '031' => ['message' => 'Quantity is below minimum allowed', 'status' => false],
            '032' => ['message' => 'Quantity is above maximum allowed', 'status' => false],
            '034' => ['message' => 'Service is suspended', 'status' => false],
            '035' => ['message' => 'Service is inactive', 'status' => false],
            '040' => ['message' => 'Transaction reversed to wallet', 'status' => true],
            '083' => ['message' => 'System error occurred', 'status' => false],
            '085' => ['message' => 'Invalid request ID format', 'status' => false],
        ];

        return $responses[$code] ?? ['message' => 'Unknown response code', 'status' => false];
    }

    protected function isProcessing(string $code): bool
    {
        return $code === '099';
    }

    protected function isSuccess(string $code): bool
    {
        return in_array($code, ['000', '001', '044', '040']);
    }

    protected function shouldRequery(string $code): bool
    {
        return in_array($code, ['099']);
    }

    protected function getTransactionStatus(array $transactions): string
    {
        return match($transactions['status'] ?? 'unknown') {
            'delivered' => 'Transaction completed successfully',
            'initiated' => 'Transaction has been initiated',
            'pending' => 'Transaction is being processed',
            'failed' => 'Transaction failed',
            default => 'Unknown transaction status'
        };
    }
}
