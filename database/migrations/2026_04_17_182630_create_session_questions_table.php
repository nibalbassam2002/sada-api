<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('live_sessions')->onDelete('cascade');
            $table->string('slide_id'); // ID الشريحة/السؤال
            $table->integer('total_duration')->default(900); // 15 دقيقة افتراضي
            $table->integer('user_duration')->default(30);   // 30 ثانية للمشارك
            $table->timestamp('started_at')->nullable();      // متى فُتح السؤال
            $table->timestamp('ended_at')->nullable();        // متى أُغلق (يدوياً أو تلقائياً)
            $table->enum('closed_reason', ['manual', 'timeout'])->nullable();
            $table->timestamps();

            // ضمان إن كل سؤال يظهر مرة وحدة لكل سيشن
            $table->unique(['session_id', 'slide_id']);
        });

        // تنظيف الأعمدة القديمة من live_sessions
        Schema::table('live_sessions', function (Blueprint $table) {
            // نحتفظ بـ current_slide_id بس نشيل باقي حقول السؤال
            $table->dropColumn([
                'timer_duration',
                'timer_started_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_questions');

        Schema::table('live_sessions', function (Blueprint $table) {
            $table->integer('timer_duration')->nullable();
            $table->timestamp('timer_started_at')->nullable();
        });
    }
};