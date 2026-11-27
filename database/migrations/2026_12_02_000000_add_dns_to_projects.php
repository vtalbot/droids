<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API keys for outside services, one per service (Cloudflare for DNS).
        Schema::create('service_keys', function (Blueprint $table) {
            $table->id();
            $table->string('service')->unique();
            $table->text('token');
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('domain')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
        Schema::dropIfExists('service_keys');
    }
};
