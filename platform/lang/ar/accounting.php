<?php

declare(strict_types=1);

return [

    'account_type' => [
        'asset' => 'أصول',
        'liability' => 'التزامات',
        'equity' => 'حقوق ملكية',
        'revenue' => 'إيرادات',
        'expense' => 'مصروفات',
    ],

    'normal_balance' => [
        'debit' => 'مدين',
        'credit' => 'دائن',
    ],

    'period_status' => [
        'open' => 'مفتوحة',
        'adjusting' => 'تسويات',
        'closed' => 'مقفلة',
        'locked' => 'مغلقة نهائياً',
    ],

    'journal_status' => [
        'draft' => 'مسودة',
        'posted' => 'مرحّل',
    ],

    'reversal_of' => 'عكس القيد :number',

    'errors' => [
        'unbalanced' => 'القيد غير متوازن. المدين :debits والدائن :credits بفارق :difference.',
        'too_few_lines' => 'القيد يحتاج إلى سطرين على الأقل.',
        'line_has_both_sides' => 'السطر :line يحمل مديناً ودائناً معاً. استخدم أحدهما فقط.',
        'line_has_no_amount' => 'السطر :line بدون مبلغ.',
        'negative_amount' => 'السطر :line يحمل مبلغاً سالباً. اعكس الجانب بدلاً من استخدام السالب.',
        'account_not_postable' => 'الحساب :account لا يقبل القيود، إما لأنه حساب تجميعي أو غير نشط.',
        'account_not_found' => 'الحساب :id غير موجود في هذه الشركة.',
        'no_open_period' => 'لا توجد فترة محاسبية تغطي :date. أنشئ السنة المالية أولاً.',
        'period_closed' => 'الفترة المحاسبية التي تغطي :date مقفلة.',
        'already_posted' => 'القيد :number مرحّل بالفعل.',
        'already_reversed' => 'القيد :number تم عكسه بالفعل.',
        'cannot_reverse_draft' => 'المسودة ليست ضمن الدفاتر ولا تحتاج إلى عكس. احذفها بدلاً من ذلك.',
        'entry_immutable' => 'القيد :number مرحّل ولا يمكن تعديله (:fields). أنشئ قيداً عكسياً بدلاً من ذلك.',
        'entry_undeletable' => 'القيد :number مرحّل ولا يمكن حذفه. أنشئ قيداً عكسياً بدلاً من ذلك.',
        'type_change_with_history' => 'لا يمكن تغيير نوع الحساب :account لوجود قيود عليه، والتغيير يعيد صياغة تقارير سابقة.',
        'account_has_history' => 'الحساب :account عليه قيود ولا يمكن حذفه. عطّله بدلاً من ذلك.',
        'account_has_children' => 'الحساب :account له حسابات فرعية. انقلها أو احذفها أولاً.',
        'system_account_deletion' => 'الحساب :account مطلوب للنظام ولا يمكن حذفه.',
        'parent_has_history' => 'الحساب :account عليه قيود بالفعل، لذا لا يمكن تحويله إلى حساب تجميعي. انقل القيود أولاً.',
    ],

];
