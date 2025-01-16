<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kyc extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'bvn',
        'nin',
        'state',
        'local_government',
        'address',
        'house_number',
        'utility_bill_type',
        'bill_image',
        'bvn_verified',
        'nin_verified'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'bvn_verified' => 'boolean',
        'nin_verified' => 'boolean'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
