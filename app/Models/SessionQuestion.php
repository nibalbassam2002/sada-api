<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionQuestion extends Model
{
    protected $table = 'session_questions';

    protected $fillable = [
        'session_id',
        'slide_id',
        'total_duration',
        'user_duration',
        'started_at',
        'ended_at',
        'closed_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /**
     * هل السؤال منتهي؟ (يدوياً أو بالوقت)
     */
    public function isExpired(): bool
    {
        if (!is_null($this->ended_at)) {
            return true;
        }

        if ($this->started_at && $this->total_duration) {
            return now()->greaterThanOrEqualTo(
                $this->started_at->copy()->addSeconds($this->total_duration)
            );
        }

        return false;
    }

    /**
     * وقت انتهاء السؤال الكلي
     */
    public function globalEndsAt(): ?\Carbon\Carbon
    {
        if (!$this->started_at) return null;
        return $this->started_at->copy()->addSeconds($this->total_duration);
    }
}