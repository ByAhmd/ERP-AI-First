<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'display_name' => $this->displayName(),
            'status' => $this->status->value,
            'base_currency' => $this->base_currency,
            'timezone' => $this->timezone,
            'vat_registration_number' => $this->vat_registration_number,
            'commercial_registration_no' => $this->commercial_registration_no,
            'is_vat_registered' => $this->isVatRegistered(),
            'fiscal_year' => [
                'start_month' => $this->fiscal_year_start_month,
                'start_day' => $this->fiscal_year_start_day,
                'uses_hijri' => $this->uses_hijri_fiscal_year,
            ],
            'address' => [
                'building_number' => $this->building_number,
                'street_name' => $this->street_name,
                'district' => $this->district,
                'city' => $this->city,
                'postal_code' => $this->postal_code,
                'additional_number' => $this->additional_number,
                'country_code' => $this->country_code,
            ],
        ];
    }
}
