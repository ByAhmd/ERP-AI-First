<?php

declare(strict_types=1);

return [
    'navigation_group' => 'الأصول الثابتة',

    'status' => [
        'active' => 'نشط',
        'disposed' => 'مستبعد',
        'archived' => 'مؤرشف',
    ],

    'acquisition_kind' => [
        'opening' => 'رصيد افتتاحي',
        'purchase' => 'شراء',
        'bill' => 'فاتورة مشتريات',
    ],

    'disposal_kind' => [
        'sale' => 'بيع',
        'scrap' => 'تخريد',
    ],

    'opening' => [
        'narration' => 'رصيد افتتاحي لأصل ثابت :reference — :name',
    ],

    'acquisition' => [
        'narration' => 'شراء أصل ثابت :reference — :name',
    ],

    'depreciation' => [
        'narration' => 'إهلاك :reference حتى :period',
    ],

    'disposal' => [
        'narration' => ':kind أصل ثابت :reference — :name',
    ],

    'errors' => [
        'account_not_postable' => 'الحساب :account لا يقبل القيود.',
        'account_type_mismatch' => 'الحساب :account ليس من النوع المطلوب (:expected).',
        'not_payment_account' => 'الحساب :account ليس حساب دفع.',
        'salvage_exceeds_cost' => 'قيمة الخردة يجب أن تكون أقل من تكلفة الأصل.',
        'opening_accumulated_too_large' => 'مجمع الإهلاك الافتتاحي يتجاوز القاعدة القابلة للإهلاك.',
        'opening_accumulated_needs_date' => 'أدخل تاريخ آخر إهلاك عند وجود مجمع إهلاك افتتاحي.',
        'life_required' => 'العمر الإنتاجي بالأشهر مطلوب لأصل قابل للإهلاك.',
        'purchase_carries_no_accumulated' => 'أصل مشترى جديد لا يحمل مجمع إهلاك افتتاحي.',
        'nothing_to_post' => 'لا يوجد إهلاك غير مسجل حتى هذا التاريخ.',
        'missing_period' => 'لا توجد فترة محاسبية تغطي :date — أنشئ السنة المالية أولاً.',
        'run_not_approved' => 'دورة الإهلاك :reference ليست معتمدة.',
        'run_bound_to_disposal' => 'دورة الإهلاك :reference مرتبطة باستبعاد ولا يمكن عكسها.',
        'run_has_disposed_assets' => 'دورة الإهلاك :reference تخص أصولاً مستبعدة ولا يمكن عكسها.',
        'disposal_already_approved' => 'الاستبعاد :reference معتمد مسبقاً.',
        'disposal_not_draft' => 'لا يمكن اعتماد استبعاد غير مسودة.',
        'asset_not_active' => 'الأصل :name ليس نشطاً.',
        'proceeds_required' => 'قيمة البيع مطلوبة لاستبعاد بالبيع.',
        'proceeds_account_required' => 'حساب استلام قيمة البيع مطلوب.',
    ],

    'types' => [
        'label' => 'تصنيف أصول',
        'plural_label' => 'تصنيفات الأصول',
        'nav_label' => 'تصنيفات الأصول',
        'columns' => [
            'name' => 'الاسم',
            'asset_account' => 'حساب الأصل',
            'life' => 'العمر الافتراضي (أشهر)',
            'depreciable' => 'قابل للإهلاك',
            'assets_count' => 'عدد الأصول',
        ],
        'sections' => [
            'details' => 'بيانات التصنيف',
            'accounts' => 'الحسابات',
        ],
        'fields' => [
            'name' => 'الاسم العربي',
            'name_en' => 'الاسم الإنجليزي',
            'description' => 'الوصف',
            'default_life' => 'العمر الإنتاجي الافتراضي (أشهر)',
            'is_depreciable' => 'قابل للإهلاك',
            'asset_account' => 'حساب الأصل',
            'accumulated_account' => 'حساب مجمع الإهلاك',
            'expense_account' => 'حساب مصروف الإهلاك',
        ],
        'hints' => [
            'is_depreciable' => 'أوقفه للأراضي وما لا يُهلك.',
            'accounts' => 'كل قيود أصول هذا التصنيف تمر عبر هذه الحسابات الثلاثة.',
        ],
    ],

    'register' => [
        'label' => 'أصل ثابت',
        'plural_label' => 'الأصول الثابتة',
        'nav_label' => 'الأصول الثابتة',
        'columns' => [
            'reference' => 'الرقم المرجعي',
            'name' => 'اسم الأصل',
            'type' => 'التصنيف',
            'branch' => 'الفرع',
            'in_service_date' => 'تاريخ الاستلام',
            'cost' => 'التكلفة',
            'accumulated' => 'مجمع الاستهلاك',
            'book_value' => 'القيمة الدفترية',
            'status' => 'الحالة',
        ],
        'sections' => [
            'details' => 'بيانات الأصل',
            'acquisition' => 'الاستحواذ',
            'opening' => 'الرصيد الافتتاحي',
            'payment' => 'الدفع',
        ],
        'fields' => [
            'type' => 'تصنيف الأصل',
            'name' => 'الاسم العربي',
            'name_en' => 'الاسم الإنجليزي',
            'description' => 'الوصف',
            'serial_number' => 'الرقم التسلسلي',
            'barcode' => 'الباركود',
            'branch' => 'الفرع',
            'acquisition_kind' => 'طريقة الاستحواذ',
            'acquisition_date' => 'تاريخ الاستحواذ',
            'in_service_date' => 'تاريخ الاستلام',
            'cost' => 'قيمة الأصل',
            'salvage_value' => 'قيمة الخردة',
            'useful_life_months' => 'العمر الإنتاجي (أشهر)',
            'opening_accumulated' => 'مجمع الاستهلاك حتى تاريخه',
            'opening_depreciated_through' => 'تاريخ آخر إهلاك',
            'register_only' => 'الرصيد قائم في الدفاتر',
            'payment_account' => 'حساب الدفع',
            'tax' => 'الضريبة',
        ],
        'hints' => [
            'in_service_date' => 'يبدأ الإهلاك من هذا التاريخ.',
            'salvage_value' => 'القيمة المتبقية بعد انتهاء العمر الإنتاجي.',
            'opening' => 'لأصل موجود قبل استخدام النظام.',
            'opening_depreciated_through' => 'يحتسب الإهلاك للأيام التالية لهذا التاريخ فقط.',
            'register_only' => 'سجّل الأصل دون قيد — عندما تكون أرصدته قائمة في دفتر الأستاذ.',
        ],
        'actions' => [
            'dispose' => 'استبعاد الأصل',
        ],
        'figures' => [
            'cost' => 'التكلفة',
            'salvage' => 'قيمة الخردة',
            'life' => 'العمر الإنتاجي (أشهر)',
            'accumulated' => 'مجمع الاستهلاك حتى تاريخه',
            'unposted' => 'الإهلاك غير المسجل',
            'book_value' => 'القيمة الدفترية',
        ],
        'schedule' => [
            'title' => 'جدول الإهلاك المتوقع',
            'hint' => 'عرض تقديري لما لم يُسجل بعد — القيود المرحلة وحدها هي المرجع.',
            'period' => 'الفترة',
            'days' => 'الأيام',
            'amount' => 'المبلغ',
        ],
        'charges' => [
            'title' => 'الإهلاكات المسجلة',
            'period' => 'فترة الاستحقاق',
            'posted_period' => 'فترة الترحيل',
            'days' => 'الأيام',
            'amount' => 'المبلغ',
            'run' => 'دورة الإهلاك',
            'entry' => 'القيد',
        ],
    ],

    'runs' => [
        'label' => 'دورة إهلاك',
        'plural_label' => 'الإهلاك',
        'nav_label' => 'الإهلاك',
        'columns' => [
            'reference' => 'الرقم المرجعي',
            'period' => 'الفترة',
            'through_date' => 'حتى تاريخ',
            'assets_count' => 'عدد الأصول',
            'total_amount' => 'الإجمالي',
            'entry' => 'القيد',
            'status' => 'الحالة',
        ],
        'fields' => [
            'through_period' => 'فترة الإهلاك',
            'type' => 'تصنيف الأصل',
            'all_types' => 'جميع التصنيفات',
            'asset' => 'الأصل',
            'all_assets' => 'جميع الأصول',
        ],
        'hints' => [
            'through_period' => 'يُسجل الإهلاك لكل فترة غير مسجلة حتى نهاية هذه الفترة.',
        ],
        'actions' => [
            'run' => 'إضافة إهلاك',
            'ran' => 'سُجلت دورة الإهلاك :reference بإجمالي :total.',
            'reverse' => 'عكس الدورة',
            'reverse_confirm' => 'يُنشأ قيد عكسي وتُحذف سطور الإهلاك المسجلة لهذه الدورة معاً.',
            'reversal_date' => 'تاريخ القيد العكسي',
            'reversed' => 'عُكست دورة الإهلاك.',
        ],
        'charges' => [
            'title' => 'سطور الدورة',
            'asset_reference' => 'الرقم المرجعي للأصل',
            'asset' => 'الأصل',
        ],
    ],

    'disposals' => [
        'label' => 'استبعاد',
        'plural_label' => 'الاستبعادات',
        'nav_label' => 'الاستبعادات',
        'columns' => [
            'reference' => 'الرقم المرجعي',
            'kind' => 'النوع',
            'asset' => 'الأصل',
            'date' => 'تاريخ الاستبعاد',
            'proceeds' => 'قيمة البيع',
            'gain_loss' => 'الربح / الخسارة',
            'status' => 'الحالة',
        ],
        'sections' => [
            'details' => 'بيانات الاستبعاد',
            'sale' => 'بيانات البيع',
        ],
        'fields' => [
            'kind' => 'نوع الاستبعاد',
            'asset' => 'الأصل',
            'date' => 'تاريخ الاستبعاد',
            'notes' => 'ملاحظات',
            'proceeds' => 'قيمة البيع (بدون الضريبة)',
            'tax' => 'الضريبة',
            'proceeds_account' => 'حساب الاستلام',
        ],
        'hints' => [
            'proceeds' => 'صافي قيمة البيع؛ تُضاف الضريبة فوقها.',
            'tax' => 'بيع الأصل توريد خاضع للضريبة.',
            'figures' => 'التكلفة :cost — مجمع الاستهلاك :accumulated — الإهلاك غير المسجل :unposted — القيمة الدفترية المتوقعة :book',
        ],
        'actions' => [
            'approve' => 'اعتماد الاستبعاد',
            'approve_confirm' => 'يُسجل الإهلاك حتى تاريخ الاستبعاد ثم يُقفل الأصل نهائياً — لا تراجع بعد الاعتماد.',
            'approved' => 'اعتُمد الاستبعاد.',
            'save_draft' => 'حفظ كمسودة',
        ],
    ],

    'tie' => [
        'title' => 'مطابقة سجل الأصول الثابتة',
        'account' => 'الحساب',
        'role' => 'الدور',
        'roles' => [
            'cost' => 'تكلفة الأصول',
            'accumulated' => 'مجمع الإهلاك',
        ],
        'gl_balance' => 'رصيد دفتر الأستاذ',
        'register_total' => 'إجمالي السجل',
        'difference' => 'الفرق',
        'empty' => 'لا توجد تصنيفات أصول بعد.',
        'balanced' => 'السجل مطابق لدفتر الأستاذ.',
        'unbalanced' => 'يوجد فرق بين السجل ودفتر الأستاذ — راجع القيود اليدوية والأرصدة السابقة على تسجيل الأصول.',
    ],
];
