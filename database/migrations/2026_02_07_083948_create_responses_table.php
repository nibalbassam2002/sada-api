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
        Schema::create('responses', function (Blueprint $table) {
            $table->id();
             $table->foreignId('session_id')->constrained('live_sessions')->onDelete('cascade');
            $table->string('slide_id');          // ← string مش foreign key
            $table->foreignId('participant_id')->constrained()->onDelete('cascade');
            $table->integer('answer_index')->nullable();   // رقم الخيار (0,1,2,3)
            $table->string('answer_value')->nullable();    // نص الإجابة
            $table->boolean('is_correct')->default(false);
            $table->integer('time_taken')->default(0);     // كم ثانية أخذ للإجابة
            $table->integer('points')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('responses');
    }
};
