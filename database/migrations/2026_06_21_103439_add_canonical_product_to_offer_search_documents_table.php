<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offer_search_documents', function (Blueprint $table): void {
            $table->foreignUuid('canonical_product_id')->nullable()->after('paper_id')->constrained()->nullOnDelete();
            $table->string('canonical_product_name')->nullable()->after('canonical_product_id');
            $table->unsignedTinyInteger('product_match_confidence')->nullable()->after('canonical_product_name');

            $table->index(['canonical_product_id', 'active_from', 'active_until'], 'offer_search_documents_canonical_active_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->replaceSearchVector(
                "setweight(to_tsvector('simple', coalesce(canonical_product_name, '')), 'A') || setweight(to_tsvector('simple', coalesce(title, '')), 'A') || setweight(to_tsvector('simple', coalesce(brand, '')), 'A') || setweight(to_tsvector('simple', coalesce(category, '')), 'B') || setweight(to_tsvector('simple', coalesce(subcategory, '')), 'B') || setweight(to_tsvector('simple', coalesce(description, '')), 'C')"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->replaceSearchVector(
                "setweight(to_tsvector('simple', coalesce(title, '')), 'A') || setweight(to_tsvector('simple', coalesce(brand, '')), 'A') || setweight(to_tsvector('simple', coalesce(category, '')), 'B') || setweight(to_tsvector('simple', coalesce(subcategory, '')), 'B') || setweight(to_tsvector('simple', coalesce(description, '')), 'C')"
            );
        }

        Schema::table('offer_search_documents', function (Blueprint $table): void {
            $table->dropIndex('offer_search_documents_canonical_active_idx');
            $table->dropConstrainedForeignId('canonical_product_id');
            $table->dropColumn(['canonical_product_name', 'product_match_confidence']);
        });
    }

    private function replaceSearchVector(string $expression): void
    {
        DB::unprepared('DROP INDEX IF EXISTS offer_search_documents_search_vector_idx');
        DB::unprepared('ALTER TABLE offer_search_documents DROP COLUMN IF EXISTS search_vector');
        DB::unprepared("ALTER TABLE offer_search_documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS ({$expression}) STORED");
        DB::unprepared('CREATE INDEX offer_search_documents_search_vector_idx ON offer_search_documents USING GIN (search_vector)');
    }
};
