<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Slide extends Model
{
    protected $fillable = ['presentation_id', 'type', 'content', 'settings', 'order'];

    protected $casts = [
        'content' => 'array',
        'settings' => 'array',
    ];

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(SlideOption::class)->orderBy('order');
    }
}
