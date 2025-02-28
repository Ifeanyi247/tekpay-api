<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'user_id',
        'pin_code',
        'profile_url',
        'wallet',
        'transaction_pin',
        'referral_code',
        'referred_by',
        'referral_count',
        'referral_earnings'
    ];

    protected $casts = [
        'wallet' => 'decimal:2',
        'referral_earnings' => 'decimal:2'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
