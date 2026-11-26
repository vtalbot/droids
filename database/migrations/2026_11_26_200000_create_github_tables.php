<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The GitHub App this factory runs as. One row, created through the manifest flow.
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->unique();
            $table->string('slug');
            $table->string('name');
            $table->string('owner_login')->nullable();
            $table->string('html_url');
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('private_key');
            $table->text('webhook_secret')->nullable();
            // Classic token with the project scope, for boards owned by a personal account.
            $table->timestamps();
        });

        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->string('account_login');
            $table->string('account_type')->default('User');
            $table->string('repository_selection')->default('selected');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('github_installation_id')->nullable()->after('color')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('github_repo_id')->nullable()->after('github_installation_id');
            $table->timestamp('synced_at')->nullable()->after('cloud_snapshot');
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedInteger('github_number')->nullable()->after('position');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('github_id')->nullable()->after('position');
            $table->string('state')->default('open')->after('github_id');
            $table->string('url')->nullable()->after('state');
        });

        Schema::table('pull_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('github_id')->nullable()->after('task_id');
            $table->string('head_sha')->nullable()->after('branch');
            $table->string('url')->nullable()->after('head_sha');
        });
    }

    public function down(): void
    {
        Schema::table('pull_requests', fn (Blueprint $t) => $t->dropColumn(['github_id', 'head_sha', 'url']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['github_id', 'state', 'url']));
        Schema::table('milestones', fn (Blueprint $t) => $t->dropColumn('github_number'));
        Schema::table('projects', function (Blueprint $t) {
            $t->dropConstrainedForeignId('github_installation_id');
            $t->dropColumn(['github_repo_id', 'synced_at']);
        });
        Schema::dropIfExists('github_installations');
        Schema::dropIfExists('github_apps');
    }
};
