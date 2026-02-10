<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained()->onDelete('cascade');
            $table->string('access_code', 6)->unique()->index();
            $table->enum('status', ['waiting', 'active', 'finished'])->default('waiting');
            $table->unsignedBigInteger('current_slide_id')->nullable();
            $table->boolean('is_voting_open')->default(false); // المقدم يفتح ويغلق التصويت بضغطة زر
            $table->boolean('show_results')->default(false);   // هل تظهر النتائج الآن على شاشة العرض أم يخفيها المقدم؟
            $table->integer('timer_duration')->nullable();    //مؤقت
            $table->timestamp('timer_started_at')->nullable(); // متى بدأ العد التنازلي
            $table->json('session_settings')->nullable(); 
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
