<?php

declare(strict_types=1);

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a document line can refer to: products, their categories and their units.
 *
 * Modelled on Qoyod's own three screens. Its category (`الصنف`) carries a name,
 * a description and a parent and nothing else — no accounts, which is worth
 * stating because it is the question everyone asks: Qoyod posts sales revenue
 * to one company-level default rather than deriving it from the product or its
 * category, and so does this.
 *
 * Deliberately not built here: attribute sets and the product variants they
 * generate, unit conversions, product images, and the components of a bundle.
 * All four are inventory concerns, and none of them is needed to raise an
 * invoice — which is what this slice exists to make possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            // Categories nest. Restricted rather than cascaded: removing a
            // parent must not silently take its children and their products.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('product_categories')->restrictOnDelete();

            // The category every product falls into unless told otherwise.
            // Qoyod creates one automatically and calls it الصنف الأساسي.
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'product_categories_company_name_unique');
        });

        Schema::create('product_unit_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 40);
            $table->string('name_en', 40)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'name'], 'product_unit_types_company_name_unique');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20)->default(ProductType::Product->value);

            // Qoyod requires both names, and requires them for a reason: a
            // tax invoice may have to be produced in either language.
            $table->string('name');
            $table->string('name_en');

            $table->string('sku', 60);
            $table->string('barcode', 60)->nullable();

            $table->foreignUlid('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->foreignUlid('unit_type_id')->constrained('product_unit_types')->restrictOnDelete();

            // The rate a line defaults to. Restricted, because a tax that
            // documents still refer to cannot be removed underneath them.
            $table->foreignUlid('tax_id')->nullable()->constrained('taxes')->restrictOnDelete();

            $table->text('description')->nullable();
            $table->text('terms_and_conditions')->nullable();

            // Qoyod reveals the price only once the matching box is ticked, so
            // a product that is bought but not sold carries no selling price.
            $table->boolean('is_sold')->default(true);
            $table->boolean('is_purchased')->default(false);
            $table->decimal('selling_price', 19, 4)->nullable();
            $table->decimal('buying_price', 19, 4)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            // Retired, never erased: an invoice line has to keep naming
            // something.
            $table->softDeletes();

            $table->unique(['company_id', 'sku'], 'products_company_sku_unique');
            $table->index(['company_id', 'type'], 'products_company_type_idx');
            // Read by every document form, which lists sellable active
            // products.
            $table->index(['company_id', 'is_sold', 'is_active'], 'products_company_sold_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_unit_types');
        Schema::dropIfExists('product_categories');
    }
};
