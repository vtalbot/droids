<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('repo');
            $table->string('board');
            $table->string('color');
            // Interim snapshots until the GitHub and Laravel Cloud integrations land.
            $table->json('stats')->nullable();
            $table->json('cloud_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('goal')->nullable();
            $table->date('due_on')->nullable();
            $table->string('state')->default('planned');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('provider');
            $table->string('name');
            $table->string('account')->nullable();
            $table->string('auth')->nullable();
            $table->string('color');
            $table->boolean('enabled')->default(true);
            $table->json('windows')->nullable();
            $table->json('week')->nullable();
            $table->decimal('spend_used', 10, 2)->nullable();
            $table->decimal('spend_cap', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            // grid[day][hour] = subscription slug, or "" when the engine is paused.
            $table->json('grid');
            $table->timestamps();
        });

        Schema::create('droids', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('unit')->unique();
            $table->string('name');
            $table->string('provider');
            $table->string('model');
            $table->string('role');
            $table->string('status')->default('idle');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step')->default('');
            $table->decimal('progress', 4, 3)->default(0);
            $table->unsignedInteger('minutes')->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->unsignedSmallInteger('seed')->default(0);
            $table->string('autonomy')->default('pr');
            $table->text('instructions')->nullable();
            $table->decimal('daily_budget', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('column')->default('backlog');
            $table->json('labels')->nullable();
            $table->foreignId('builder_id')->nullable()->constrained('droids')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('droids')->nullOnDelete();
            $table->string('engine')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });

        Schema::table('droids', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        Schema::create('pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->string('branch');
            $table->string('state');
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('commits')->default(0);
            $table->unsignedInteger('files')->default(0);
            $table->json('checks')->nullable();
            $table->json('reviews')->nullable();
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('droid_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('droid_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('pull_requests');
        Schema::table('droids', fn (Blueprint $table) => $table->dropConstrainedForeignId('task_id'));
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('droids');
        Schema::dropIfExists('subscription_schedules');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('features');
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('projects');
    }
};
