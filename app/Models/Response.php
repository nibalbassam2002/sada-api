<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Response extends Model
{
        protected $fillable = [
        'session_id', 
        'slide_id', 
        'participant_id', 
        'answer_index',   
        'answer_value',
        'answer_rating',  
        'time_taken',     
        'is_correct',     
        'points',         
        'option_id',
    ];
    protected $casts = [
        'is_correct'   => 'boolean',
        'answer_index' => 'integer',
        'points'       => 'integer',
        'time_taken'   => 'integer',
    ];

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
