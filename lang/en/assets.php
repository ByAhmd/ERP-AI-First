<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Fixed Assets',

    'status' => [
        'active' => 'Active',
        'disposed' => 'Disposed',
        'archived' => 'Archived',
    ],

    'acquisition_kind' => [
        'opening' => 'Opening balance',
        'purchase' => 'Purchase',
        'bill' => 'Purchase invoice',
    ],

    'disposal_kind' => [
        'sale' => 'Sale',
        'scrap' => 'Scrap',
    ],

    'opening' => [
        'narration' => 'Opening balance for fixed asset :reference — :name',
    ],

    'acquisition' => [
        'narration' => 'Purchase of fixed asset :reference — :name',
    ],

    'depreciation' => [
        'narration' => 'Depreciation :reference through :period',
    ],

    'disposal' => [
        'narration' => ':kind of fixed asset :reference — :name',
    ],

    'errors' => [
        'account_not_postable' => 'Account :account does not accept postings.',
        'account_type_mismatch' => 'Account :account is not of the required type (:expected).',
        'not_payment_account' => 'Account :account is not a payment account.',
        'salvage_exceeds_cost' => 'Salvage value must be less than the asset cost.',
        'opening_accumulated_too_large' => 'Opening accumulated depreciation exceeds the depreciable base.',
        'opening_accumulated_needs_date' => 'Enter the last-depreciated date when opening accumulated depreciation is present.',
        'life_required' => 'Useful life in months is required for a depreciable asset.',
        'purchase_carries_no_accumulated' => 'A newly purchased asset carries no opening accumulated depreciation.',
        'nothing_to_post' => 'No unposted depreciation exists through this date.',
        'missing_period' => 'No accounting period covers :date — create the fiscal year first.',
        'run_not_approved' => 'Depreciation run :reference is not approved.',
        'run_bound_to_disposal' => 'Depreciation run :reference belongs to a disposal and cannot be reversed.',
        'run_has_disposed_assets' => 'Depreciation run :reference touches disposed assets and cannot be reversed.',
        'disposal_already_approved' => 'Disposal :reference is already approved.',
        'disposal_not_draft' => 'Only a draft disposal can be approved.',
        'asset_not_active' => 'Asset :name is not active.',
        'proceeds_required' => 'Sale proceeds are required for a disposal by sale.',
        'proceeds_account_required' => 'A proceeds account is required.',
    ],

    'types' => [
        'label' => 'Asset Type',
        'plural_label' => 'Asset Types',
        'nav_label' => 'Asset Types',
        'columns' => [
            'name' => 'Name',
            'asset_account' => 'Asset Account',
            'life' => 'Default Life (months)',
            'depreciable' => 'Depreciable',
            'assets_count' => 'Assets',
        ],
        'sections' => [
            'details' => 'Type Details',
            'accounts' => 'Accounts',
        ],
        'fields' => [
            'name' => 'Arabic Name',
            'name_en' => 'English Name',
            'description' => 'Description',
            'default_life' => 'Default Useful Life (months)',
            'is_depreciable' => 'Depreciable',
            'asset_account' => 'Asset Account',
            'accumulated_account' => 'Accumulated Depreciation Account',
            'expense_account' => 'Depreciation Expense Account',
        ],
        'hints' => [
            'is_depreciable' => 'Turn off for land and anything that does not depreciate.',
            'accounts' => 'Every posting for this type\'s assets goes through these three accounts.',
        ],
    ],

    'register' => [
        'label' => 'Fixed Asset',
        'plural_label' => 'Fixed Assets',
        'nav_label' => 'Fixed Assets',
        'columns' => [
            'reference' => 'Reference',
            'name' => 'Asset Name',
            'type' => 'Type',
            'branch' => 'Branch',
            'in_service_date' => 'Receipt Date',
            'cost' => 'Cost',
            'accumulated' => 'Accumulated Depreciation',
            'book_value' => 'Book Value',
            'status' => 'Status',
        ],
        'sections' => [
            'details' => 'Asset Details',
            'acquisition' => 'Acquisition',
            'opening' => 'Opening Balance',
            'payment' => 'Payment',
        ],
        'fields' => [
            'type' => 'Asset Type',
            'name' => 'Arabic Name',
            'name_en' => 'English Name',
            'description' => 'Description',
            'serial_number' => 'Serial Number',
            'barcode' => 'Barcode',
            'branch' => 'Branch',
            'acquisition_kind' => 'Acquisition Method',
            'acquisition_date' => 'Acquisition Date',
            'in_service_date' => 'Receipt Date',
            'cost' => 'Asset Value',
            'salvage_value' => 'Salvage Value',
            'useful_life_months' => 'Useful Life (months)',
            'opening_accumulated' => 'Accumulated Depreciation to Date',
            'opening_depreciated_through' => 'Last Depreciation Date',
            'register_only' => 'Balance already on the books',
            'payment_account' => 'Payment Account',
            'tax' => 'Tax',
        ],
        'hints' => [
            'in_service_date' => 'Depreciation starts from this date.',
            'salvage_value' => 'The value remaining after the useful life ends.',
            'opening' => 'For an asset that existed before this system.',
            'opening_depreciated_through' => 'Depreciation is charged only for days after this date.',
            'register_only' => 'Register without posting — when the balances already stand in the ledger.',
        ],
        'actions' => [
            'dispose' => 'Dispose Asset',
        ],
        'figures' => [
            'cost' => 'Cost',
            'salvage' => 'Salvage Value',
            'life' => 'Useful Life (months)',
            'accumulated' => 'Accumulated Depreciation',
            'unposted' => 'Unposted Depreciation',
            'book_value' => 'Book Value',
        ],
        'schedule' => [
            'title' => 'Projected Depreciation Schedule',
            'hint' => 'A projection of what is not yet posted — posted entries alone are authoritative.',
            'period' => 'Period',
            'days' => 'Days',
            'amount' => 'Amount',
        ],
        'charges' => [
            'title' => 'Posted Depreciation',
            'period' => 'Period of Record',
            'posted_period' => 'Posted Period',
            'days' => 'Days',
            'amount' => 'Amount',
            'run' => 'Run',
            'entry' => 'Entry',
        ],
    ],

    'runs' => [
        'label' => 'Depreciation Run',
        'plural_label' => 'Depreciation',
        'nav_label' => 'Depreciation',
        'columns' => [
            'reference' => 'Reference',
            'period' => 'Period',
            'through_date' => 'Through Date',
            'assets_count' => 'Assets',
            'total_amount' => 'Total',
            'entry' => 'Entry',
            'status' => 'Status',
        ],
        'fields' => [
            'through_period' => 'Depreciation Period',
            'type' => 'Asset Type',
            'all_types' => 'All types',
            'asset' => 'Asset',
            'all_assets' => 'All assets',
        ],
        'hints' => [
            'through_period' => 'Charges every unposted period through the end of this one.',
        ],
        'actions' => [
            'run' => 'Add Depreciation',
            'ran' => 'Depreciation run :reference posted, totalling :total.',
            'reverse' => 'Reverse Run',
            'reverse_confirm' => 'A reversing entry is posted and the run\'s charge rows are removed together.',
            'reversal_date' => 'Reversal Date',
            'reversed' => 'The depreciation run was reversed.',
        ],
        'charges' => [
            'title' => 'Run Lines',
            'asset_reference' => 'Asset Reference',
            'asset' => 'Asset',
        ],
    ],

    'disposals' => [
        'label' => 'Disposal',
        'plural_label' => 'Disposals',
        'nav_label' => 'Disposals',
        'columns' => [
            'reference' => 'Reference',
            'kind' => 'Kind',
            'asset' => 'Asset',
            'date' => 'Disposal Date',
            'proceeds' => 'Proceeds',
            'gain_loss' => 'Gain / Loss',
            'status' => 'Status',
        ],
        'sections' => [
            'details' => 'Disposal Details',
            'sale' => 'Sale Details',
        ],
        'fields' => [
            'kind' => 'Disposal Kind',
            'asset' => 'Asset',
            'date' => 'Disposal Date',
            'notes' => 'Notes',
            'proceeds' => 'Sale Proceeds (before tax)',
            'tax' => 'Tax',
            'proceeds_account' => 'Receiving Account',
        ],
        'hints' => [
            'proceeds' => 'Net proceeds; tax is added on top.',
            'tax' => 'Selling an asset is a taxable supply.',
            'figures' => 'Cost :cost — accumulated :accumulated — unposted depreciation :unposted — projected book value :book',
        ],
        'actions' => [
            'approve' => 'Approve Disposal',
            'approve_confirm' => 'Depreciation is posted through the disposal date, then the asset is closed for good — no undo after approval.',
            'approved' => 'The disposal was approved.',
            'save_draft' => 'Save as Draft',
        ],
    ],

    'tie' => [
        'title' => 'Fixed Asset Register Tie',
        'account' => 'Account',
        'role' => 'Role',
        'roles' => [
            'cost' => 'Asset cost',
            'accumulated' => 'Accumulated depreciation',
        ],
        'gl_balance' => 'Ledger Balance',
        'register_total' => 'Register Total',
        'difference' => 'Difference',
        'empty' => 'No asset types yet.',
        'balanced' => 'The register ties to the ledger.',
        'unbalanced' => 'The register and the ledger differ — review manual entries and balances that predate registration.',
    ],
];
