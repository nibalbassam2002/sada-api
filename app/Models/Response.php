<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Response extends Model
{
    protected $fillable = ['session_id', 'slide_id', 'participant_id', 'option_id', 'answer_value'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(Slide::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
