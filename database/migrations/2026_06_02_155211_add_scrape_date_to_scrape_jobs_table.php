<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table): void {
            $table->date('scrape_date')->nullable()->after('scraper_agent_id');
            $table->unique(['grocer_id', 'scrape_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table): void {
            $table->dropUnique(['grocer_id', 'scrape_date']);
            $table->dropColumn('scrape_date');
        });
    }
};
