<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // The model id the worker ran it on, as the CLI took it.
            $table->string('model')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn('model'));
    }
};
