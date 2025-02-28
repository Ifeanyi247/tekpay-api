<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'account_name',
        'account_number',
        'amount',
        'account_bank',
        'account_code',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
