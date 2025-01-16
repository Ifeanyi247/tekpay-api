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
        'kyc_verified'
    ];

    protected $casts = [
        'wallet' => 'decimal:2',
        'kyc_verified' => 'boolean'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
