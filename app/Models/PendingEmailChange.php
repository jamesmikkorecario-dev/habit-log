<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingEmailChange extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['new_email', 'source_email', 'token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'last_sent_at' => 'immutable_datetime'];
    }
}
