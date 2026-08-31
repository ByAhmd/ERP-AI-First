<?php

declare(strict_types=1);

/**
 * Inventory — tracking, costing, adjustments.
 */
return [

    'adjustment_kind' => [
        'opening' => 'Opening Balance',
        'count' => 'Count Adjustment',
    ],

    'adjustments' => [
        'label' => 'Stock Adjustment',
        'plural_label' => 'Stock Adjustments',
        'nav_label' => 'Stock Adjustments',
        'narration' => 'Stock adjustment :reference',
        'columns' => [
            'reference' => 'Reference',
            'kind' => 'Kind',
            'branch' => 'Location',
            'date' => 'Date',
            'status' => 'Status',
        ],
        'sections' => [
            'details' => 'Adjustment Details',
            'items' => 'Items',
        ],
        'fields' => [
            'reference' => 'Reference',
            'kind' => 'Kind',
            'branch' => 'Location',
            'date' => 'Date',
            'description' => 'Description',
            'offset_account' => 'Offset Account',
        ],
        'items' => [
            'product' => 'Product',
            'current_qty' => 'Current Quantity',
            'quantity_change' => 'Quantity Change',
            'unit_cost' => 'Unit Cost',
            'add' => 'Add Item',
        ],
        'hints' => [
            'offset_account' => 'Increases credit it, decreases debit it. Defaults to Inventory Adjustments.',
            'quantity_change' => 'Positive to increase, negative to decrease. Unit cost is required for increases only — decreases are valued at the running average at approval.',
        ],
        'actions' => [
            'save_draft' => 'Save as Draft',
            'approve' => 'Save & Approve',
            'approve_confirm' => 'After approval quantities move and the entry posts; the adjustment can no longer be edited — correct with a counter-adjustment.',
            'approved' => 'The adjustment has been approved and posted.',
        ],
        'errors' => [
            'no_items' => 'An adjustment with no items cannot be approved.',
            'already_approved' => 'Adjustment :reference is already approved.',
            'not_draft' => 'An adjustment cannot be edited after approval.',
            'zero_line' => 'Line :line has no quantity change.',
            'opening_negative' => 'An opening balance cannot carry negative quantities.',
            'cost_required_line' => 'Line :line is an increase with no unit cost.',
        ],
    ],

    'stock' => [
        'section' => 'Inventory',
        'quantity' => 'Quantity',
        'average_cost' => 'Average Cost',
        'total_value' => 'Total Value',
        'tracked' => 'Stocked?',
        'per_branch' => 'Quantities by Location',
        'movements' => 'Product Movements',
        'movement_date' => 'Movement Date',
        'movement_source' => 'Reference',
        'movement_qty' => 'Change',
        'movement_cost' => 'Average Cost',
        'movement_value' => 'Movement Value',
        'movement_balance' => 'Available Quantity',
        'available_hint' => 'Available: :quantity',
    ],

    'fields' => [
        'track_inventory' => 'Stocked',
        'branch' => 'Location',
    ],

    'hints' => [
        'track_inventory' => 'Track quantity and cost for this product. Cannot change after the first stock movement.',
        'track_frozen' => 'This product has stock movements — the option is no longer changeable.',
        'credit_note_restock' => 'The return reason restocks the quantities on approval.',
        'returns_goods' => 'Did the goods physically go back to the supplier? Turn off for price-correction notes.',
    ],

    'errors' => [
        'tracking_flag_frozen' => 'Product :product has stock movements — the "Stocked" option is no longer changeable.',
        'insufficient_stock' => 'Insufficient quantity of :product at :branch — available :available, requested :requested.',
        'missing_cost_row' => 'No cost record exists for product :product.',
        'branch_required' => 'The document carries stocked lines and needs a location.',
        'account_not_postable' => 'Account :account does not accept direct postings.',
        'not_tracked' => 'Product :product is not stocked.',
        'cost_required' => 'The increase for product :product needs a unit cost.',
    ],

    'transfers' => [
        'label' => 'Inventory Transfer',
        'plural_label' => 'Inventory Transfers',
        'nav_label' => 'Inventory Transfers',
        'columns' => [
            'reference' => 'Reference',
            'from_branch' => 'Sending Location',
            'to_branch' => 'Receiving Location',
            'date' => 'Date',
            'status' => 'Status',
        ],
        'sections' => [
            'details' => 'Transfer Details',
            'items' => 'Items',
        ],
        'fields' => [
            'reference' => 'Reference',
            'from_branch' => 'Sending Location',
            'to_branch' => 'Receiving Location',
            'date' => 'Date',
            'description' => 'Description',
        ],
        'items' => [
            'product' => 'Product',
            'quantity' => 'Quantity',
            'add' => 'Add Item',
        ],
        'actions' => [
            'save_draft' => 'Save as Draft',
            'approve' => 'Send & Receive',
            'approve_confirm' => 'Quantities move from the sending location to the receiving one immediately; the transfer cannot be edited afterwards — correct with a reverse transfer.',
            'approved' => 'Transferred.',
        ],
        'errors' => [
            'no_items' => 'A transfer with no items cannot be approved.',
            'already_approved' => 'Transfer :reference is already approved.',
            'not_draft' => 'A transfer cannot be edited after approval.',
            'same_branch' => 'Both locations are the same — pick a different receiving location.',
            'zero_line' => 'Line :line has no quantity.',
        ],
    ],

    'locations_report' => [
        'title' => 'Product Locations',
        'branch' => 'Location',
        'all_branches' => 'All locations',
        'product' => 'Product Name',
        'sku' => 'SKU',
        'total' => 'Total Quantity',
        'totals' => 'Total',
        'empty' => 'No stocked products.',
    ],

];
