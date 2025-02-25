<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordHistory extends Model
{
    use HasFactory;

    protected $table = 'password_history';

    protected $fillable = [
        'user_id',
        'password'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
