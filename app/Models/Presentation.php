<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presentation extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'template_id', 'title', 'description', 'theme_settings', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class)->orderBy('order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
