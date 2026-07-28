<?php

declare(strict_types=1);

return [

    'settings' => [
        'nav_label' => 'Company settings',
        'title' => 'Company settings',
        'save' => 'Save changes',
        'saved' => 'Company settings saved.',

        'sections' => [
            'identity' => 'Legal identity',
            'address' => 'National address',
            'address_hint' => 'Required for ZATCA e-invoicing. Each part is issued separately as part of the invoice.',
            'financial' => 'Financial year and currency',
        ],
    ],

    'fields' => [
        'name' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'commercial_registration_no' => 'Commercial registration number',
        'vat_registration_number' => 'VAT registration number',
        'vat_hint' => '15 digits, starting and ending with 3.',
        'building_number' => 'Building number',
        'street_name' => 'Street',
        'district' => 'District',
        'city' => 'City',
        'postal_code' => 'Postal code',
        'additional_number' => 'Additional number',
        'base_currency' => 'Base currency',
        'base_currency_hint' => 'Fixed once transactions exist: changing it would reinterpret every posted amount.',
        'fiscal_year_start_month' => 'Financial year starts (month)',
        'fiscal_year_start_day' => 'Financial year starts (day)',
        'fiscal_day_hint' => 'Limited to 28 so the date is valid in every month.',
        'uses_hijri_fiscal_year' => 'Assess Zakat on the Hijri year',
        'hijri_hint' => 'Most Saudi entities assess Zakat on the Hijri calendar.',
    ],

];
