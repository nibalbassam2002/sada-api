<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlideOption extends Model
{
     protected $fillable = ['slide_id', 'label', 'image_url', 'order'];

    public function slide(): BelongsTo
    {
        return $this->belongsTo(Slide::class);
    }
}
