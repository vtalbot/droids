<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Laravel Cloud organization Droids reads from, through one API token.
        Schema::create('cloud_connections', function (Blueprint $table) {
            $table->id();
            $table->text('token'); // encrypted with APP_KEY
            $table->string('organization_name');
            $table->string('organization_slug')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('cloud_application_id')->nullable()->after('cloud_snapshot');
            $table->timestamp('cloud_synced_at')->nullable()->after('cloud_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['cloud_application_id', 'cloud_synced_at']));
        Schema::dropIfExists('cloud_connections');
    }
};
