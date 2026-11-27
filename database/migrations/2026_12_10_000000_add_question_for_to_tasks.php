<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // The role of the droid the triage question went to; null when it is for the person.
            $table->string('question_for')->nullable()->after('question');
            // What that droid answered, for the next triage.
            $table->text('answer')->nullable()->after('question_for');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['question_for', 'answer']));
    }
};
