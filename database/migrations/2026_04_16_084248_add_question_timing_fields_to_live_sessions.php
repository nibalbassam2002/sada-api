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
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->timestamp('question_started_at')->nullable()->after('timer_started_at');
            $table->timestamp('question_ended_at')->nullable()->after('question_started_at');
            $table->integer('question_total_duration')->default(900)->after('timer_duration');
            $table->integer('question_user_duration')->default(30)->after('question_total_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'question_started_at', 
                'question_ended_at', 
                'question_total_duration', 
                'question_user_duration'
            ]);
        });
    }
};
