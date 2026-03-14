<?php
// app/Models/Session.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    use SoftDeletes;

    protected $table = 'live_sessions';

    protected $fillable = [
        'presentation_id', 'access_code', 'status',
        'current_slide_id', 'is_voting_open', 'show_results',
        'timer_duration', 'timer_started_at', 'session_settings',
        'started_at', 'ended_at',
    ];

    protected $casts = [
        'session_settings' => 'array',
        'is_voting_open'   => 'boolean',
        'show_results'     => 'boolean',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'timer_started_at' => 'datetime',
    ];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    public function currentSlide(): BelongsTo
    {
        return $this->belongsTo(Slide::class, 'current_slide_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'session_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'session_id');
    }
}
