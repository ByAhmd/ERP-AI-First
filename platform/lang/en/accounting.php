<?php

declare(strict_types=1);

return [

    'account_type' => [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
    ],

    'normal_balance' => [
        'debit' => 'Debit',
        'credit' => 'Credit',
    ],

    'period_status' => [
        'open' => 'Open',
        'adjusting' => 'Adjusting',
        'closed' => 'Closed',
        'locked' => 'Locked',
    ],

    'journal_status' => [
        'draft' => 'Draft',
        'posted' => 'Posted',
    ],

    'reversal_of' => 'Reversal of :number',

    'errors' => [
        'unbalanced' => 'The entry does not balance. Debits :debits, credits :credits, a difference of :difference.',
        'too_few_lines' => 'A journal entry needs at least two lines.',
        'line_has_both_sides' => 'Line :line carries both a debit and a credit. Use one or the other.',
        'line_has_no_amount' => 'Line :line has no amount.',
        'negative_amount' => 'Line :line has a negative amount. Reverse the side instead of negating it.',
        'account_not_postable' => 'Account :account does not accept postings. It is a group account or is inactive.',
        'account_not_found' => 'Account :id does not exist in this company.',
        'no_open_period' => 'No accounting period covers :date. Create the fiscal year first.',
        'period_closed' => 'The accounting period covering :date is closed.',
        'already_posted' => 'Entry :number is already posted.',
        'already_reversed' => 'Entry :number has already been reversed.',
        'cannot_reverse_draft' => 'A draft entry is not in the ledger and does not need reversing. Delete it instead.',
        'entry_immutable' => 'Entry :number is posted and cannot be changed (:fields). Post a reversing entry instead.',
        'entry_undeletable' => 'Entry :number is posted and cannot be deleted. Post a reversing entry instead.',
        'type_change_with_history' => 'The type of :account cannot change: it already carries ledger entries, and reclassifying it would restate past reports.',
        'account_has_history' => ':account carries ledger entries and cannot be deleted. Deactivate it instead.',
        'account_has_children' => ':account has sub-accounts. Remove or move them first.',
        'system_account_deletion' => ':account is required by the platform and cannot be deleted.',
        'parent_has_history' => ':account already carries ledger entries, so it cannot become a group account. Move those entries first.',
    ],

];
