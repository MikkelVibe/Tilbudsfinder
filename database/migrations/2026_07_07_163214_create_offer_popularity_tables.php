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
        Schema::create('offer_popularity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('scraped_offer_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64);
            $table->string('event_type')->default('detail_view')->index();
            $table->timestampTz('occurred_at')->index();
            $table->string('user_agent_family')->nullable();
            $table->boolean('is_bot')->default(false)->index();
            $table->timestampsTz();

            $table->index(['scraped_offer_id', 'occurred_at']);
            $table->index(['scraped_offer_id', 'session_hash', 'occurred_at']);
        });

        Schema::create('offer_popularity_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('scraped_offer_id')->constrained()->cascadeOnDelete();
            $table->string('window', 8);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('unique_sessions')->default(0);
            $table->unsignedInteger('capped_views')->default(0);
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(['scraped_offer_id', 'window']);
            $table->index(['window', 'score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_popularity_scores');
        Schema::dropIfExists('offer_popularity_events');
    }
};
