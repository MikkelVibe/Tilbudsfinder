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
        Schema::create('grocer_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocer_id')->constrained()->cascadeOnDelete();
            $table->string('source_product_id');
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->decimal('package_amount', 12, 3)->nullable();
            $table->string('package_unit')->nullable();
            $table->string('compare_unit')->nullable();
            $table->text('declaration')->nullable();
            $table->json('attributes')->nullable();
            $table->json('traceability')->nullable();
            $table->json('raw_detail_payload')->nullable();
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
            $table->timestampTz('detail_observed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['grocer_id', 'source_product_id']);
            $table->index(['grocer_id', 'category']);
            $table->index(['name']);
            $table->index(['protein_g_per_100', 'energy_kcal_per_100']);
        });

        Schema::table('scraped_offers', function (Blueprint $table): void {
            $table->foreignUuid('grocer_product_id')->nullable()->after('paper_id')->constrained()->nullOnDelete();
            $table->index(['grocer_product_id', 'paper_id']);
        });

        Schema::table('canonical_products', function (Blueprint $table): void {
            $table->dropIndex('canonical_products_protein_g_per_100_energy_kcal_per_100_index');
            $table->dropColumn([
                'declaration',
                'nutrition_info_raw',
                'nutrition_basis_unit',
                'energy_kj_per_100',
                'energy_kcal_per_100',
                'fat_g_per_100',
                'saturated_fat_g_per_100',
                'carbohydrate_g_per_100',
                'sugars_g_per_100',
                'fiber_g_per_100',
                'protein_g_per_100',
                'salt_g_per_100',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canonical_products', function (Blueprint $table): void {
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
            $table->index(['protein_g_per_100', 'energy_kcal_per_100']);
        });

        Schema::table('scraped_offers', function (Blueprint $table): void {
            $table->dropForeign(['grocer_product_id']);
            $table->dropIndex(['grocer_product_id', 'paper_id']);
            $table->dropColumn('grocer_product_id');
        });

        Schema::dropIfExists('grocer_products');
    }
};
