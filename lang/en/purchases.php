<?php

declare(strict_types=1);

/**
 * Purchases — the mirror of the sales side, in Qoyod's own wording.
 *
 * Narration strings are stored inside posted journal entries and never
 * re-rendered, so they must exist before the first approval, not before the
 * UI polish pass.
 */
return [

    'navigation_group' => 'Purchases',

    'suppliers' => [
        'label' => 'Supplier',
        'plural_label' => 'Suppliers',
        'nav_label' => 'Suppliers',
        'fields' => [
            'contact_name' => 'Supplier Name',
        ],
        'columns' => [
            'name' => 'Supplier Name',
        ],
    ],

    'invoices' => [
        'label' => 'Purchase Invoice',
        'plural_label' => 'Purchase Invoices',
        'nav_label' => 'Purchase Invoices',
        'narration' => 'Purchase invoice :reference — :supplier',
        'columns' => [
            'reference' => 'Reference',
            'contact' => 'Supplier',
            'supplier_invoice_number' => 'Supplier Invoice No.',
            'issue_date' => 'Issue Date',
            'due_date' => 'Due Date',
            'status' => 'Status',
            'payment' => 'Payment',
            'net' => 'Total Before Tax',
            'tax' => 'Tax Amount',
            'total' => 'Total',
        ],
        'sections' => [
            'details' => 'Invoice Details',
            'items' => 'Items',
            'notes' => 'Notes & Terms',
        ],
        'fields' => [
            'reference' => 'Reference',
            'description' => 'Description',
            'contact' => 'Supplier',
            'supplier_invoice_number' => 'Supplier Invoice No.',
            'supplier_invoice_date' => 'Supplier Invoice Date',
            'issue_date' => 'Issue Date',
            'due_date' => 'Due Date',
            'terms_and_conditions' => 'Terms & Conditions',
            'notes' => 'Notes',
        ],
        'items' => [
            'expense_account' => 'Account',
        ],
        'hints' => [
            'supplier_invoice_number' => 'The invoice number as the supplier issued it. Prevents keying the same bill twice.',
            'issue_date' => 'The date the invoice enters our books; the ledger posts on it. The supplier paper date is kept in its own field.',
        ],
        'actions' => [
            'save_draft' => 'Save as Draft',
            'approve' => 'Save & Approve',
            'approve_confirm' => 'After approval the invoice posts to the accounts and appears in reports; correction is by debit note.',
            'approved' => 'The invoice has been approved and posted.',
        ],
        'errors' => [
            'no_items' => 'An invoice with no items cannot be approved.',
            'already_approved' => 'Invoice :reference is already approved. Correction is by debit note.',
            'not_draft' => 'An invoice cannot be edited after approval.',
            'missing_supplier' => 'A purchase invoice cannot be approved without a supplier — recovering input VAT requires a supplier identity.',
            'not_a_supplier' => 'Contact :contact is not a supplier.',
            'inactive_supplier' => 'Supplier :contact is inactive.',
            'due_before_issue' => 'The due date cannot precede the issue date.',
            'due_date_required' => 'A purchase invoice needs a due date.',
            'duplicate_supplier_invoice' => 'Invoice :number is already recorded for this supplier — keying it again doubles expense, VAT and payables.',
            'duplicate_supplier_invoice_form' => 'This invoice is already recorded for this supplier.',
            'expense_account_missing' => 'Line :line has no expense account.',
            'expense_account_not_postable' => 'Account :account does not accept direct postings.',
            'totals_do_not_reconcile' => 'The totals of invoice :reference do not match its items.',
        ],
    ],

    'debit_notes' => [
        'label' => 'Debit Note',
        'plural_label' => 'Debit Notes',
        'nav_label' => 'Debit Notes',
        'narration' => 'Debit note :reference against supplier invoice :original',
        'fields' => [
            'reference' => 'Reference',
            'contact' => 'Supplier',
            'parent' => 'Original Purchase Invoice',
            'original_invoice_number' => 'Original Supplier Invoice Ref.',
            'original_invoice_date' => 'Original Invoice Date',
            'issue_date' => 'Note Date',
            'description' => 'Description',
            'terms_and_conditions' => 'Terms & Conditions',
            'notes' => 'Notes',
        ],
        'hints' => [
            'parent' => 'Pick the invoice recorded in the system, or leave empty and enter an external reference for an invoice from a previous system.',
            'original_invoice_number' => 'The supplier invoice number this note corrects.',
        ],
        'actions' => [
            'save_draft' => 'Save as Draft',
            'approve' => 'Save & Approve',
            'approve_confirm' => 'After approval the note posts and reduces what is owed to the supplier; it can no longer be edited.',
            'approved' => 'The debit note has been approved and posted.',
        ],
        'errors' => [
            'no_items' => 'A debit note with no items cannot be approved.',
            'already_approved' => 'Note :reference is already approved.',
            'not_draft' => 'A debit note cannot be edited after approval.',
            'parent_not_approved' => 'A debit note cannot be raised against an unapproved invoice.',
            'supplier_mismatch' => 'The note names a different supplier than the original invoice.',
            'dated_before_parent' => 'The note date cannot precede the original invoice date.',
            'exceeds_remaining' => 'The note amount :amount exceeds what remains of the invoice, :remaining.',
            'nothing_to_debit' => 'The note amounts to zero — there is nothing to post.',
            'inactive_supplier' => 'Supplier :contact is inactive.',
            'totals_do_not_reconcile' => 'The totals of note :reference do not match its items.',
        ],
    ],

    'payments' => [
        'label' => 'Payment Voucher',
        'plural_label' => 'Supplier Vouchers',
        'nav_label' => 'Supplier Vouchers',
        'narration' => 'Payment voucher :reference — :supplier',
        'allocation_narration' => 'Allocation of voucher :reference to invoice :invoice',
        'unallocation_narration' => 'Unallocation of voucher :reference from invoice :invoice',
        'columns' => [
            'reference' => 'Reference',
            'contact' => 'Supplier',
            'account' => 'Payment Account',
            'date' => 'Date',
            'amount' => 'Amount',
            'allocated' => 'Allocated',
            'status' => 'Status',
        ],
        'sections' => [
            'details' => 'Voucher Details',
            'allocations' => 'Allocation to Invoices',
        ],
        'fields' => [
            'reference' => 'Reference',
            'contact' => 'Supplier',
            'payment_account' => 'Payment Account',
            'payment_date' => 'Date',
            'amount' => 'Amount',
            'description' => 'Description',
        ],
        'allocations' => [
            'title' => 'Allocation to Invoices',
            'invoice' => 'Invoice',
            'amount' => 'Amount',
            'add' => 'Add Allocation',
            'date' => 'Date',
            'outstanding' => 'Outstanding',
            'summary' => 'Allocated :allocated · Unallocated :unallocated',
            'allocated' => 'Allocated',
            'unallocated' => 'Unallocated',
        ],
        'hints' => [
            'payment_account' => 'Only accounts flagged "can pay and collect" appear here.',
            'unallocated' => 'The unallocated amount posts as an advance to the supplier and can be allocated later.',
        ],
        'actions' => [
            'save_draft' => 'Save as Draft',
            'approve' => 'Save & Approve',
            'approve_confirm' => 'After approval the voucher posts: the allocated part settles invoices, the unallocated part becomes an advance to the supplier.',
            'approved' => 'The voucher has been approved and posted.',
            'allocate' => 'Allocate',
            'allocated' => 'Allocated.',
            'unallocate' => 'Unallocate',
            'unallocated_done' => 'Allocation released.',
        ],
        'errors' => [
            'zero_amount' => 'The voucher amount must be greater than zero.',
            'already_approved' => 'Voucher :reference is already approved.',
            'not_draft' => 'A voucher cannot be edited after approval.',
            'missing_supplier' => 'A payment voucher cannot be approved without a supplier.',
            'not_a_supplier' => 'Contact :contact is not a supplier.',
            'inactive_supplier' => 'Supplier :contact is inactive.',
            'account_not_payment' => 'Account :account is not flagged "can pay and collect".',
            'invoice_not_approved' => 'Allocation against an unapproved invoice is not possible.',
            'invoice_wrong_supplier' => 'Invoice :invoice does not belong to this supplier.',
            'currency_mismatch' => 'Invoice :invoice is in a different currency than the voucher.',
            'dated_before_invoice' => 'A voucher cannot be allocated with a date before the invoice date.',
            'exceeds_outstanding' => 'The allocated amount :amount exceeds what remains of the invoice, :remaining.',
            'exceeds_unallocated' => 'The amount :amount exceeds the voucher unallocated remainder, :remaining.',
            'allocation_exists' => 'The voucher is already allocated to this invoice — unallocate first.',
            'allocation_missing' => 'No allocation of this voucher to this invoice exists.',
        ],
    ],

    'order_status' => [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'billed' => 'Billed',
        'cancelled' => 'Cancelled',
        'overdue' => 'Overdue',
    ],

    'orders' => [
        'label' => 'Purchase Order',
        'plural_label' => 'Purchase Orders',
        'nav_label' => 'Purchase Orders',
        'from_order' => 'From purchase order :reference',
        'columns' => [
            'reference' => 'Order No.',
            'contact' => 'Supplier',
            'issue_date' => 'Issue Date',
            'expiry_date' => 'Expiry Date',
            'status' => 'Status',
            'net' => 'Total Before Tax',
            'tax' => 'Tax Amount',
            'total' => 'Total',
        ],
        'filters' => [
            'overdue' => 'Overdue',
        ],
        'sections' => [
            'details' => 'Order Details',
            'items' => 'Items',
            'notes' => 'Notes & Terms',
        ],
        'fields' => [
            'reference' => 'Order No.',
            'description' => 'Order Description',
            'contact' => 'Supplier Name',
            'issue_date' => 'Issue Date',
            'expiry_date' => 'Expiry Date',
            'terms_and_conditions' => 'Terms & Conditions',
            'notes' => 'Notes',
        ],
        'hints' => [
            'expiry_date' => 'How long the order stands — past this date without billing the order is considered overdue.',
        ],
        'actions' => [
            'save_draft' => 'Save as Draft',
            'approve' => 'Save & Approve',
            'approve_confirm' => 'Approving fixes the order. It affects neither the accounts nor the reports — no entry is posted. Posting happens only when the invoice created by conversion is approved.',
            'approved' => 'The purchase order has been approved.',
            'convert' => 'Convert to Invoice',
            'convert_confirm' => 'A draft purchase invoice will be created from this order and the order becomes Billed. Taxes are computed at the invoice date rates.',
            'convert_overdue_warning' => 'This order expired on :date. You may proceed; the invoice is created as a reviewable draft.',
            'converted' => 'Draft invoice :reference has been created.',
            'cancel' => 'Cancel',
            'cancel_confirm' => 'The order will be cancelled. Cancellation cannot be undone.',
            'cancelled' => 'The purchase order has been cancelled.',
        ],
        'errors' => [
            'no_items' => 'An order with no items cannot be approved.',
            'already_approved' => 'Order :reference is already approved.',
            'not_draft' => 'An order cannot be edited after approval.',
            'inactive_supplier' => 'Supplier :contact is inactive.',
            'expiry_before_issue' => 'The expiry date cannot precede the issue date.',
            'totals_do_not_reconcile' => 'The totals of order :reference do not match its items.',
            'not_approved' => 'Order :reference cannot be converted — only approved orders convert.',
            'already_billed' => 'Order :reference has already been converted to invoice :invoice.',
            'tax_no_longer_available' => 'The tax ":tax" used on this order is no longer available. Update the order items before converting.',
            'cannot_cancel' => 'Order :reference cannot be cancelled in its current state.',
        ],
    ],

    'simple_invoices' => [
        'label' => 'Simple Invoice',
        'plural_label' => 'Simple Invoices',
        'nav_label' => 'Simple Invoices',
        'columns' => [
            'statement' => 'Statement',
        ],
        'fields' => [
            'statement' => 'Statement',
            'value' => 'Value',
        ],
    ],

    'payment_status' => [
        'unpaid' => 'Unpaid',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
    ],

];
