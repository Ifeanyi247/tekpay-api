<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_token',
        'device_type', // android, ios, web
        'is_active'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
