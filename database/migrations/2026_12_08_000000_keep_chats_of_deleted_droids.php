<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Project discussions are shared: deleting a droid keeps what it said there.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['droid_id']);
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('droid_id')->nullable()->change();
            $table->foreign('droid_id')->references('id')->on('droids')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['droid_id']);
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('droid_id')->nullable(false)->change();
            $table->foreign('droid_id')->references('id')->on('droids')->cascadeOnDelete();
        });
    }
};
