<?php

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationFailureSeverity;
use App\Enums\NormalizationStatus;
use App\Enums\ScrapeJobStatus;
use App\Enums\ScraperAgentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grocers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('website_url')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('health_status')->default(GrocerHealthStatus::Healthy->value)->index();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->timestampTz('next_expected_import_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('scraper_agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default(ScraperAgentStatus::Active->value)->index();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('scrape_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scraper_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(ScrapeJobStatus::Pending->value)->index();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestampTz('scheduled_for')->index();
            $table->timestampTz('leased_until')->nullable()->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('context')->nullable();
            $table->timestampsTz();

            $table->index(['grocer_id', 'status', 'scheduled_for']);
        });

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scrape_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(ImportBatchStatus::Pending->value)->index();
            $table->string('source_type')->default('json');
            $table->string('source_url')->nullable();
            $table->string('source_external_id')->nullable();
            $table->string('raw_payload_path')->nullable();
            $table->string('raw_payload_sha256', 64)->nullable();
            $table->unsignedBigInteger('raw_payload_size_bytes')->nullable();
            $table->timestampTz('raw_payload_retained_until')->nullable()->index();
            $table->unsignedInteger('parsed_offer_count')->default(0);
            $table->unsignedInteger('published_offer_count')->default(0);
            $table->unsignedInteger('normalization_failure_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['grocer_id', 'status']);
        });

        Schema::create('papers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('source_external_id')->nullable();
            $table->string('title')->nullable();
            $table->timestampTz('active_from')->index();
            $table->timestampTz('active_until')->index();
            $table->timestampsTz();

            $table->index(['grocer_id', 'active_from', 'active_until']);
        });

        Schema::create('scraped_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('paper_id')->constrained()->cascadeOnDelete();
            $table->string('source_offer_id')->nullable();
            $table->string('source_product_id')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->unsignedInteger('source_position')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->decimal('price', 10, 2);
            $table->string('currency', 3)->default('DKK');
            $table->decimal('package_amount', 12, 3)->nullable();
            $table->string('package_unit_original')->nullable();
            $table->string('package_unit')->nullable();
            $table->string('compare_unit')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('normalization_status')->default(NormalizationStatus::NotAttempted->value)->index();
            $table->unsignedTinyInteger('normalization_confidence')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestampsTz();

            $table->index(['grocer_id', 'paper_id']);
            $table->index(['paper_id', 'price']);
            $table->index(['compare_unit', 'unit_price']);
            $table->index('title');
        });

        Schema::create('normalization_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scraped_offer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('severity')->default(NormalizationFailureSeverity::Warning->value)->index();
            $table->string('field')->nullable();
            $table->string('code');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestampsTz();

            $table->index(['import_batch_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalization_failures');
        Schema::dropIfExists('scraped_offers');
        Schema::dropIfExists('papers');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('scrape_jobs');
        Schema::dropIfExists('scraper_agents');
        Schema::dropIfExists('grocers');
    }
};
