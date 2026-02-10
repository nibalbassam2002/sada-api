<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = ['title', 'description', 'thumbnail', 'category', 'default_settings'];

    protected $casts = [
        'default_settings' => 'array',
    ];
}
