<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\ProductUnitType;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue scaffolding a company starts with.
 *
 * A product cannot be created without a category and a unit, and requiring a
 * company to invent both before it can record its first item is a poor way to
 * begin. Qoyod solves it the same way — it creates الصنف الأساسي automatically
 * and ships a fixed unit list.
 *
 * Idempotent, like the chart and tax templates: re-running adds what is missing
 * and leaves renamed entries alone.
 */
final class CatalogueTemplate
{
    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * @return array{categories: int, units: int} Counts of what was created.
     */
    public function applyTo(Company $company): array
    {
        return $this->context->forCompany($company, function (): array {
            return DB::transaction(fn (): array => [
                'categories' => $this->seedDefaultCategory(),
                'units' => $this->seedUnitTypes(),
            ]);
        });
    }

    private function seedDefaultCategory(): int
    {
        if (ProductCategory::query()->where('is_default', true)->exists()) {
            return 0;
        }

        ProductCategory::create([
            'name' => 'الصنف الأساسي',
            'description' => 'صنف أساسي يتم إنشاؤه تلقائياً.',
            'is_default' => true,
        ]);

        return 1;
    }

    private function seedUnitTypes(): int
    {
        $created = 0;

        foreach ($this->units() as $unit) {
            if (ProductUnitType::query()->where('name', $unit['name'])->exists()) {
                continue;
            }

            ProductUnitType::create(['name' => $unit['name'], 'name_en' => $unit['name_en']]);
            $created++;
        }

        return $created;
    }

    /**
     * The units Qoyod offers, in its order.
     *
     * @return list<array{name: string, name_en: string}>
     */
    private function units(): array
    {
        return [
            ['name' => 'وحدة', 'name_en' => 'Unit'],
            ['name' => 'ج', 'name_en' => 'Gram'],
            ['name' => 'قطعة', 'name_en' => 'Piece'],
            ['name' => 'كج', 'name_en' => 'Kilogram'],
            ['name' => 'لتر', 'name_en' => 'Litre'],
            ['name' => 'م٢', 'name_en' => 'Square metre'],
            ['name' => 'م٣', 'name_en' => 'Cubic metre'],
        ];
    }
}
