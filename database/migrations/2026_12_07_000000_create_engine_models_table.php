<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Models the workers found their Claude Code and Codex logins can use.
        Schema::create('engine_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model_id'); // what the CLI takes, like claude-opus-5-5 or gpt-5.1-codex
            $table->string('label');
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['provider', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_models');
    }
};
