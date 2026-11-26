<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Machines that run droids. Each polls the API with its own token.
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('subscriptions')->nullable(); // slugs the worker is signed in to
            $table->string('version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // One attempt by a droid to build a task, on one subscription and one worker.
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('droid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind')->default('build');
            $table->string('status')->default('queued'); // queued, running, succeeded, failed, cancelled
            $table->string('branch')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->longText('log')->nullable();
            $table->decimal('cost', 10, 4)->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Set when a run hits the plan's usage limit, so routing falls back until then.
            $table->timestamp('limited_until')->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('limited_until'));
        Schema::dropIfExists('runs');
        Schema::dropIfExists('workers');
    }
};
