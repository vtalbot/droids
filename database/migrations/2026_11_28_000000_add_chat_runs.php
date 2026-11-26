<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Runs that answer in a conversation (plan, propose, reflect, chat) have no task.
        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->change();
            $table->foreignId('project_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
            $table->string('topic')->nullable()->after('kind'); // the conversation it answers: "plan:store" or a droid slug
            $table->text('input')->nullable()->after('topic'); // what the person asked
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // Planning sessions are keyed "plan:<project>"; a droid's own chat has no topic.
            $table->string('topic')->nullable()->after('droid_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn('topic'));
        Schema::table('runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['topic', 'input']);
        });
    }
};
