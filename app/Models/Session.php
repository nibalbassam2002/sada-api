<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    use SoftDeletes;

    protected $fillable = ['presentation_id', 'access_code', 'status', 'current_slide_id', 'started_at', 'ended_at'];

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
        return $this->hasMany(Participant::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }
}
