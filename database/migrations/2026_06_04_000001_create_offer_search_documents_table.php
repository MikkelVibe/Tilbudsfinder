<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_search_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('scraped_offer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('paper_id')->constrained()->cascadeOnDelete();
            $table->string('grocer_slug');
            $table->string('grocer_name');
            $table->string('title');
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->text('search_text');
            $table->decimal('price', 10, 2);
            $table->decimal('package_amount', 12, 3)->nullable();
            $table->string('package_unit')->nullable();
            $table->string('compare_unit')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('DKK');
            $table->timestampTz('active_from')->index();
            $table->timestampTz('active_until')->index();
            $table->timestampsTz();

            $table->index(['grocer_id', 'active_from', 'active_until']);
            $table->index(['grocer_slug', 'active_from', 'active_until']);
            $table->index(['price']);
            $table->index(['title']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::unprepared("ALTER TABLE offer_search_documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (setweight(to_tsvector('simple', coalesce(title, '')), 'A') || setweight(to_tsvector('simple', coalesce(brand, '')), 'A') || setweight(to_tsvector('simple', coalesce(category, '')), 'B') || setweight(to_tsvector('simple', coalesce(subcategory, '')), 'B') || setweight(to_tsvector('simple', coalesce(description, '')), 'C')) STORED");
            DB::unprepared('CREATE INDEX offer_search_documents_search_vector_idx ON offer_search_documents USING GIN (search_vector)');
            DB::unprepared('CREATE INDEX offer_search_documents_search_text_trgm_idx ON offer_search_documents USING GIN (search_text gin_trgm_ops)');
            DB::unprepared('CREATE INDEX offer_search_documents_title_trgm_idx ON offer_search_documents USING GIN (title gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS offer_search_documents_title_trgm_idx');
            DB::unprepared('DROP INDEX IF EXISTS offer_search_documents_search_text_trgm_idx');
            DB::unprepared('DROP INDEX IF EXISTS offer_search_documents_search_vector_idx');
        }

        Schema::dropIfExists('offer_search_documents');
    }
};
