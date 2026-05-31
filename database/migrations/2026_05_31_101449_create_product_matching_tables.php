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
        Schema::create('canonical_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->decimal('package_amount', 12, 3)->nullable();
            $table->string('package_unit')->nullable();
            $table->string('compare_unit')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedTinyInteger('match_confidence')->nullable();
            $table->text('declaration')->nullable();
            $table->json('nutrition_info_raw')->nullable();
            $table->string('nutrition_basis_unit', 8)->nullable();
            $table->decimal('energy_kj_per_100', 10, 2)->nullable();
            $table->decimal('energy_kcal_per_100', 10, 2)->nullable();
            $table->decimal('fat_g_per_100', 10, 2)->nullable();
            $table->decimal('saturated_fat_g_per_100', 10, 2)->nullable();
            $table->decimal('carbohydrate_g_per_100', 10, 2)->nullable();
            $table->decimal('sugars_g_per_100', 10, 2)->nullable();
            $table->decimal('fiber_g_per_100', 10, 2)->nullable();
            $table->decimal('protein_g_per_100', 10, 2)->nullable();
            $table->decimal('salt_g_per_100', 10, 2)->nullable();
            $table->timestampsTz();

            $table->index(['brand', 'name']);
            $table->index(['protein_g_per_100', 'energy_kcal_per_100']);
        });

        Schema::create('product_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('grocer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('value');
            $table->timestampsTz();

            $table->index(['canonical_product_id', 'type']);
        });

        DB::statement("CREATE UNIQUE INDEX product_identifiers_unique_ean ON product_identifiers (value) WHERE type = 'ean' AND grocer_id IS NULL");
        DB::statement('CREATE UNIQUE INDEX product_identifiers_unique_grocer_identifier ON product_identifiers (grocer_id, type, value) WHERE grocer_id IS NOT NULL');

        Schema::create('product_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('scraped_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('canonical_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_method');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('status')->index();
            $table->json('warnings')->nullable();
            $table->timestampsTz();

            $table->unique('scraped_offer_id');
            $table->index(['canonical_product_id', 'status']);
        });

        Schema::create('price_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scraped_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('DKK');
            $table->timestampTz('observed_at');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestampsTz();

            $table->unique('scraped_offer_id');
            $table->index(['canonical_product_id', 'grocer_id', 'observed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_observations');
        Schema::dropIfExists('product_matches');
        DB::statement('DROP INDEX IF EXISTS product_identifiers_unique_grocer_identifier');
        DB::statement('DROP INDEX IF EXISTS product_identifiers_unique_ean');
        Schema::dropIfExists('product_identifiers');
        Schema::dropIfExists('canonical_products');
    }
};
