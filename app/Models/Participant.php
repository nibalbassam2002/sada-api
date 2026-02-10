<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    protected $fillable = ['session_id', 'nickname', 'device_token', 'ip_address'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }
}
