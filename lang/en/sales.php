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

];
