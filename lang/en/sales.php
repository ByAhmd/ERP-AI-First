<?php

declare(strict_types=1);

return [

    'navigation_group' => 'Sales',

    'taxes' => [
        'label' => 'Tax',
        'plural_label' => 'Taxes',
        'nav_label' => 'Taxes',
        'columns' => [
            'number' => 'No.',
            'name' => 'Name',
            'code' => 'Code',
            'rate' => 'Rate',
            'account' => 'Account',
            'active' => 'Active',
            'default' => 'Default',
        ],
        'sections' => [
            'details' => 'Tax details',
            'posting' => 'Posting',
        ],
        'fields' => [
            'name' => 'Name',
            'name_en' => 'Name in English',
            'category' => 'Code',
            'rate' => 'Rate',
            'account' => 'Account',
            'is_active' => 'Active',
            'is_default' => 'Default tax',
        ],
        'hints' => [
            'category' => 'The ZATCA tax category code carried on an electronic invoice.',
            'rate' => 'A percentage. Zero-rated and exempt are always zero.',
            'account' => 'Where the tax posts when a document is approved.',
            'is_default' => 'Applied to document lines that name no tax of their own.',
        ],
        'errors' => [
            'rate_on_zero_category' => 'Zero-rated and exempt taxes cannot carry a rate above zero.',
            'system_delete' => 'A system tax cannot be deleted. Deactivate it instead.',
        ],
    ],

    'tax_category' => [
        'S' => 'Standard rated',
        'Z' => 'Zero rated',
        'E' => 'Exempt',
    ],

    'product_type' => [
        'product' => 'Product',
        'bundle' => 'Bundle',
        'raw_material' => 'Raw material',
        'service' => 'Service',
        'expense' => 'Expense',
    ],

    'products' => [
        'label' => 'Product',
        'plural_label' => 'Products and Costs',
        'nav_label' => 'Products and Costs',
        'columns' => [
            'sku' => 'SKU',
            'name' => 'Name',
            'type' => 'Type',
            'category' => 'Category',
            'unit' => 'Unit',
            'tax' => 'Tax',
            'selling_price' => 'Selling price',
            'buying_price' => 'Buying price',
            'active' => 'Active',
        ],
        'sections' => [
            'details' => 'Product details',
            'pricing' => 'Pricing',
        ],
        'fields' => [
            'type' => 'Product type',
            'name' => 'Name in Arabic',
            'name_en' => 'Name in English',
            'sku' => 'SKU',
            'barcode' => 'Barcode',
            'category' => 'Category',
            'unit_type' => 'Unit',
            'tax' => 'Tax %',
            'description' => 'Description',
            'terms_and_conditions' => 'Terms and conditions',
            'is_sold' => 'Sold',
            'is_purchased' => 'Purchased',
            'selling_price' => 'Selling price',
            'buying_price' => 'Buying price',
            'is_active' => 'Active',
        ],
        'hints' => [
            'sku' => 'Generated automatically if left blank.',
            'name_en' => 'Required because a tax invoice may be issued in either language.',
            'selling_price' => 'A default documents copy; changing it later does not alter invoices already raised.',
        ],
    ],

    'product_categories' => [
        'label' => 'Category',
        'plural_label' => 'Categories',
        'nav_label' => 'Categories',
        'columns' => [
            'name' => 'Name',
            'parent' => 'Parent category',
            'description' => 'Description',
        ],
        'fields' => [
            'name' => 'Category name',
            'description' => 'Description',
            'parent' => 'Parent category',
        ],
    ],

    'contact_type' => [
        'customer' => 'Customer',
        'supplier' => 'Supplier',
    ],

    'contact_status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

    'contacts' => [
        'customer_label' => 'Customer',
        'customers_label' => 'Customers',
        'nav_label' => 'Customers',
        'columns' => [
            'code' => 'Reference',
            'name' => 'Customer name',
            'organization' => 'Organisation',
            'phone' => 'Phone',
            'email' => 'Email',
            'tax_number' => 'VAT number',
            'status' => 'Status',
        ],
        'sections' => [
            'details' => 'Customer details',
            'billing_address' => 'Billing address',
            'shipping_address' => 'Shipping address',
            'bank' => 'Bank account',
        ],
        'fields' => [
            'code' => 'Reference',
            'contact_name' => 'Customer name',
            'organization_name' => 'Organisation name',
            'primary_contact_number' => 'Primary phone',
            'secondary_contact_number' => 'Secondary phone',
            'primary_email' => 'Primary email',
            'secondary_email' => 'Secondary email',
            'website' => 'Website',
            'tax_number' => 'VAT number',
            'status' => 'Status',
            'currency' => 'Currency',
            'is_pos' => 'Point of sale customer',
            'is_government_entity' => 'Government entity',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'Region',
            'zip' => 'Postal code',
            'building_number' => 'Building number',
            'country' => 'Country',
            'copy_billing' => 'Copy billing address',
            'bank_name' => 'Bank name',
            'bank_account_name' => 'Account holder',
            'bank_country' => 'Country',
            'bank_currency' => 'Currency',
            'bank_iban' => 'IBAN',
            'bank_account_number' => 'Account number',
            'bank_swift_code' => 'SWIFT code',
            'bank_address' => 'Bank address',
        ],
        'hints' => [
            'code' => 'Generated automatically if left blank.',
            'tax_number' => "The VAT registration number, printed on the customer's tax invoices.",
            'building_number' => 'Part of the Saudi national address a tax invoice must carry.',
            'is_government_entity' => 'Cannot be cleared once set — sales to government bodies are reported differently.',
        ],
        'errors' => [
            'government_entity_locked' => 'The government entity flag on :contact cannot be cleared once set.',
        ],
    ],

];
