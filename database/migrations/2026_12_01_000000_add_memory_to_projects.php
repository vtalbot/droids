<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notes the droids on a project keep for each other, as one Markdown document.
        Schema::table('projects', function (Blueprint $table) {
            $table->longText('memory')->nullable();
            $table->foreignId('memory_droid_id')->nullable()->constrained('droids')->nullOnDelete();
            $table->timestamp('memory_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('memory_droid_id');
            $table->dropColumn(['memory', 'memory_updated_at']);
        });
    }
};
