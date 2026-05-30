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
        Schema::table('scraper_agents', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->after('slug');
            $table->string('app_version')->nullable()->after('status');
            $table->timestampTz('last_heartbeat_at')->nullable()->after('last_seen_at')->index();
        });

        Schema::table('scrape_jobs', function (Blueprint $table): void {
            $table->timestampTz('payload_received_at')->nullable()->after('finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_jobs', function (Blueprint $table): void {
            $table->dropColumn('payload_received_at');
        });

        Schema::table('scraper_agents', function (Blueprint $table): void {
            $table->dropColumn(['token_hash', 'app_version', 'last_heartbeat_at']);
        });
    }
};
