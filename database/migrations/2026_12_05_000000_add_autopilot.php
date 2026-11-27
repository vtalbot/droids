<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The project's Planner triages the Ideas column.
            $table->boolean('auto_triage')->default(false);
            // Idle builders on the project take cards from Ready for droids.
            $table->boolean('auto_build')->default(false);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('triaged_at')->nullable();
            // What the triage droid asked before the card can be built.
            $table->text('question')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['auto_triage', 'auto_build']));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['triaged_at', 'question']));
    }
};
