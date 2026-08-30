<?php

declare(strict_types=1);

/**
 * المشتريات — الجانب الموازي للمبيعات، بصياغة قيود نفسها.
 *
 * نصوص القيود (narration) تُخزَّن داخل قيود اليومية المرحّلة ولا يُعاد
 * عرضها من جديد، لذلك يجب أن تكون موجودة قبل أول اعتماد، لا قبل تلميع
 * الواجهة.
 */
return [

    'navigation_group' => 'المشتريات',

    'suppliers' => [
        'label' => 'مورد',
        'plural_label' => 'الموردين',
        'nav_label' => 'الموردين',
        'fields' => [
            'contact_name' => 'اسم المورد',
        ],
        'columns' => [
            'name' => 'اسم المورد',
        ],
    ],

    'invoices' => [
        'label' => 'فاتورة مشتريات',
        'plural_label' => 'فواتير المشتريات',
        'nav_label' => 'فواتير المشتريات',
        'narration' => 'فاتورة مشتريات :reference — :supplier',
        'columns' => [
            'reference' => 'المرجع',
            'contact' => 'المورد',
            'supplier_invoice_number' => 'رقم فاتورة المورد',
            'issue_date' => 'تاريخ الإصدار',
            'due_date' => 'تاريخ الاستحقاق',
            'status' => 'الحالة',
            'payment' => 'الدفع',
            'net' => 'الاجمالي قبل الضريبة',
            'tax' => 'قيمة الضريبة',
            'total' => 'الإجمالي',
        ],
        'sections' => [
            'details' => 'بيانات الفاتورة',
            'items' => 'البنود',
            'notes' => 'ملاحظات وشروط',
        ],
        'fields' => [
            'reference' => 'المرجع',
            'description' => 'الوصف',
            'contact' => 'المورد',
            'supplier_invoice_number' => 'رقم فاتورة المورد',
            'supplier_invoice_date' => 'تاريخ فاتورة المورد',
            'issue_date' => 'تاريخ الإصدار',
            'due_date' => 'تاريخ الاستحقاق',
            'terms_and_conditions' => 'الشروط والأحكام',
            'notes' => 'ملاحظات',
        ],
        'items' => [
            'expense_account' => 'الحساب',
        ],
        'hints' => [
            'supplier_invoice_number' => 'رقم الفاتورة كما أصدرها المورد. يمنع تسجيل الفاتورة نفسها مرتين.',
            'issue_date' => 'تاريخ تسجيل الفاتورة في دفاترنا، وعليه يُرحَّل القيد. تاريخ ورقة المورد يُحفظ في حقله الخاص.',
        ],
        'actions' => [
            'save_draft' => 'حفظ كمسودة',
            'approve' => 'حفظ وموافقة',
            'approve_confirm' => 'بعد الاعتماد تُرحَّل الفاتورة إلى الحسابات وتظهر في التقارير، ولا يمكن تعديلها إلا بإشعار مدين.',
            'approved' => 'تم اعتماد الفاتورة وترحيلها.',
        ],
        'errors' => [
            'no_items' => 'لا يمكن اعتماد فاتورة بلا بنود.',
            'already_approved' => 'الفاتورة :reference معتمدة بالفعل. التصحيح يكون بإشعار مدين.',
            'not_draft' => 'لا يمكن تعديل فاتورة بعد اعتمادها.',
            'missing_supplier' => 'لا يمكن اعتماد فاتورة مشتريات بلا مورد — خصم ضريبة المدخلات يحتاج هوية مورد.',
            'not_a_supplier' => 'جهة الاتصال :contact ليست موردًا.',
            'inactive_supplier' => 'المورد :contact غير نشط ولا يمكن تسجيل فاتورة له.',
            'due_before_issue' => 'تاريخ الاستحقاق لا يمكن أن يسبق تاريخ الإصدار.',
            'due_date_required' => 'فاتورة المشتريات تحتاج تاريخ استحقاق.',
            'duplicate_supplier_invoice' => 'الفاتورة :number مسجلة بالفعل لهذا المورد — تسجيلها مرة أخرى يضاعف المصروف والضريبة والذمم.',
            'duplicate_supplier_invoice_form' => 'هذه الفاتورة مسجلة بالفعل لهذا المورد.',
            'expense_account_missing' => 'البند :line بلا حساب مصروف.',
            'expense_account_not_postable' => 'الحساب :account لا يقبل الترحيل المباشر.',
            'totals_do_not_reconcile' => 'إجماليات الفاتورة :reference لا تتطابق مع بنودها.',
        ],
    ],

    'debit_notes' => [
        'label' => 'إشعار مدين',
        'plural_label' => 'الإشعارات المدينة',
        'nav_label' => 'الإشعارات المدينة',
        'narration' => 'إشعار مدين :reference على فاتورة المورد :original',
        'fields' => [
            'reference' => 'المرجع',
            'contact' => 'المورد',
            'parent' => 'فاتورة المشتريات الأصلية',
            'original_invoice_number' => 'مرجع فاتورة المورد الأصلية',
            'original_invoice_date' => 'تاريخ الفاتورة الأصلية',
            'issue_date' => 'تاريخ الإشعار',
            'description' => 'الوصف',
            'terms_and_conditions' => 'الشروط والأحكام',
            'notes' => 'ملاحظات',
        ],
        'hints' => [
            'parent' => 'اختر الفاتورة المسجلة في النظام، أو اترك الحقل فارغًا وأدخل مرجعًا خارجيًا لفاتورة من نظام سابق.',
            'original_invoice_number' => 'رقم فاتورة المورد التي يصححها هذا الإشعار.',
        ],
        'actions' => [
            'save_draft' => 'حفظ كمسودة',
            'approve' => 'حفظ وموافقة',
            'approve_confirm' => 'بعد الاعتماد يُرحَّل الإشعار ويخفض مديونية المورد، ولا يمكن تعديله.',
            'approved' => 'تم اعتماد الإشعار المدين وترحيله.',
        ],
        'errors' => [
            'no_items' => 'لا يمكن اعتماد إشعار مدين بلا بنود.',
            'already_approved' => 'الإشعار :reference معتمد بالفعل.',
            'not_draft' => 'لا يمكن تعديل إشعار مدين بعد اعتماده.',
            'parent_not_approved' => 'لا يمكن إصدار إشعار مدين على فاتورة غير معتمدة.',
            'supplier_mismatch' => 'الإشعار لمورد غير مورد الفاتورة الأصلية.',
            'dated_before_parent' => 'تاريخ الإشعار لا يمكن أن يسبق تاريخ الفاتورة الأصلية.',
            'exceeds_remaining' => 'قيمة الإشعار :amount تتجاوز المتبقي من الفاتورة :remaining.',
            'nothing_to_debit' => 'قيمة الإشعار صفر — لا يوجد ما يُرحَّل.',
            'inactive_supplier' => 'المورد :contact غير نشط.',
            'totals_do_not_reconcile' => 'إجماليات الإشعار :reference لا تتطابق مع بنوده.',
        ],
    ],

    'payments' => [
        'label' => 'سند صرف',
        'plural_label' => 'سندات الموردين',
        'nav_label' => 'سندات الموردين',
        'narration' => 'سند صرف :reference — :supplier',
        'allocation_narration' => 'تخصيص السند :reference على الفاتورة :invoice',
        'unallocation_narration' => 'إلغاء تخصيص السند :reference عن الفاتورة :invoice',
        'columns' => [
            'reference' => 'المرجع',
            'contact' => 'المورد',
            'account' => 'حساب الدفع',
            'date' => 'التاريخ',
            'amount' => 'المبلغ',
            'allocated' => 'المخصص',
            'status' => 'الحالة',
        ],
        'sections' => [
            'details' => 'بيانات السند',
            'allocations' => 'التخصيص على الفواتير',
        ],
        'fields' => [
            'reference' => 'المرجع',
            'contact' => 'المورد',
            'payment_account' => 'حساب الدفع',
            'payment_date' => 'التاريخ',
            'amount' => 'المبلغ',
            'description' => 'الوصف',
        ],
        'allocations' => [
            'title' => 'التخصيص على الفواتير',
            'invoice' => 'الفاتورة',
            'amount' => 'المبلغ',
            'add' => 'إضافة تخصيص',
            'allocated' => 'المخصص',
            'unallocated' => 'غير المخصص',
        ],
        'hints' => [
            'payment_account' => 'تظهر هنا الحسابات المفعّل عليها خيار «يمكن الدفع والتحصيل» فقط.',
            'unallocated' => 'المبلغ غير المخصص يُقيَّد دفعة مقدمة للمورد ويمكن تخصيصه لاحقًا.',
        ],
        'actions' => [
            'save_draft' => 'حفظ كمسودة',
            'approve' => 'حفظ وموافقة',
            'approve_confirm' => 'بعد الاعتماد يُرحَّل السند: المخصص يسدد الفواتير، وغير المخصص يُقيَّد دفعة مقدمة للمورد.',
            'approved' => 'تم اعتماد السند وترحيله.',
            'allocate' => 'تخصيص',
            'allocated' => 'تم التخصيص.',
            'unallocate' => 'إلغاء تخصيص',
            'unallocated_done' => 'تم إلغاء التخصيص.',
        ],
        'errors' => [
            'zero_amount' => 'مبلغ السند يجب أن يكون أكبر من صفر.',
            'already_approved' => 'السند :reference معتمد بالفعل.',
            'not_draft' => 'لا يمكن تعديل سند بعد اعتماده.',
            'missing_supplier' => 'لا يمكن اعتماد سند صرف بلا مورد.',
            'not_a_supplier' => 'جهة الاتصال :contact ليست موردًا.',
            'inactive_supplier' => 'المورد :contact غير نشط.',
            'account_not_payment' => 'الحساب :account غير مفعّل عليه «يمكن الدفع والتحصيل».',
            'invoice_not_approved' => 'لا يمكن التخصيص على فاتورة غير معتمدة.',
            'invoice_wrong_supplier' => 'الفاتورة :invoice ليست لهذا المورد.',
            'currency_mismatch' => 'عملة الفاتورة :invoice تختلف عن عملة السند.',
            'dated_before_invoice' => 'لا يمكن تخصيص سند بتاريخ يسبق تاريخ الفاتورة.',
            'exceeds_outstanding' => 'المبلغ المخصص :amount يتجاوز المتبقي من الفاتورة :remaining.',
            'exceeds_unallocated' => 'المبلغ :amount يتجاوز غير المخصص من السند :remaining.',
            'allocation_exists' => 'السند مخصص بالفعل على هذه الفاتورة — ألغِ التخصيص أولًا.',
            'allocation_missing' => 'لا يوجد تخصيص لهذا السند على هذه الفاتورة.',
        ],
    ],

    'order_status' => [
        'draft' => 'مسودة',
        'approved' => 'موافق عليه',
        'billed' => 'تمت الفوترة',
        'cancelled' => 'ملغي',
        'overdue' => 'متأخرة',
    ],

    'orders' => [
        'label' => 'أمر شراء',
        'plural_label' => 'أوامر الشراء',
        'nav_label' => 'أوامر الشراء',
        'from_order' => 'من أمر شراء :reference',
        'columns' => [
            'reference' => 'رقم أمر الشراء',
            'contact' => 'المورد',
            'issue_date' => 'تاريخ الإصدار',
            'expiry_date' => 'تاريخ الانتهاء',
            'status' => 'الحالة',
            'net' => 'الاجمالي قبل الضريبة',
            'tax' => 'قيمة الضريبة',
            'total' => 'الإجمالي',
        ],
        'filters' => [
            'overdue' => 'متأخرة',
        ],
        'sections' => [
            'details' => 'بيانات أمر الشراء',
            'items' => 'البنود',
            'notes' => 'ملاحظات وشروط',
        ],
        'fields' => [
            'reference' => 'رقم أمر الشراء',
            'description' => 'وصف أمر الشراء',
            'contact' => 'اسم المورد',
            'issue_date' => 'تاريخ الإصدار',
            'expiry_date' => 'تاريخ الانتهاء',
            'terms_and_conditions' => 'الشروط والأحكام',
            'notes' => 'ملاحظات',
        ],
        'hints' => [
            'expiry_date' => 'صلاحية الأمر — بعد هذا التاريخ دون فوترة يُعد الأمر متأخرًا.',
        ],
        'actions' => [
            'save_draft' => 'حفظ كمسودة',
            'approve' => 'حفظ وموافقة',
            'approve_confirm' => 'الموافقة تثبّت أمر الشراء ولا تؤثر على الحسابات أو التقارير — لا يُرحَّل أي قيد. الترحيل يحدث فقط عند اعتماد الفاتورة الناتجة عن التحويل.',
            'approved' => 'تمت الموافقة على أمر الشراء.',
            'convert' => 'تحويل لفاتورة',
            'convert_confirm' => 'سيتم إنشاء مسودة فاتورة مشتريات من هذا الأمر ويصبح الأمر «تمت الفوترة». تُحتسب الضرائب بنسب تاريخ الفاتورة.',
            'convert_overdue_warning' => 'انتهت صلاحية هذا الأمر بتاريخ :date. يمكنك المتابعة، وستُنشأ الفاتورة بمسودة قابلة للمراجعة.',
            'converted' => 'تم إنشاء مسودة الفاتورة :reference.',
            'cancel' => 'إلغاء',
            'cancel_confirm' => 'سيصبح أمر الشراء ملغيًا ولا يمكن التراجع عن الإلغاء.',
            'cancelled' => 'تم إلغاء أمر الشراء.',
        ],
        'errors' => [
            'no_items' => 'لا يمكن الموافقة على أمر شراء بلا بنود.',
            'already_approved' => 'أمر الشراء :reference موافق عليه بالفعل.',
            'not_draft' => 'لا يمكن تعديل أمر شراء بعد الموافقة عليه.',
            'inactive_supplier' => 'المورد :contact غير نشط.',
            'expiry_before_issue' => 'تاريخ الانتهاء لا يمكن أن يسبق تاريخ الإصدار.',
            'totals_do_not_reconcile' => 'إجماليات أمر الشراء :reference لا تتطابق مع بنوده.',
            'not_approved' => 'لا يمكن تحويل أمر الشراء :reference — التحويل متاح للأوامر الموافق عليها فقط.',
            'already_billed' => 'أمر الشراء :reference سبق تحويله إلى الفاتورة :invoice.',
            'tax_no_longer_available' => 'الضريبة «:tax» المستخدمة في هذا الأمر لم تعد متاحة. حدّث بنود الأمر قبل التحويل.',
            'cannot_cancel' => 'لا يمكن إلغاء أمر الشراء :reference في حالته الحالية.',
        ],
    ],

    'simple_invoices' => [
        'label' => 'فاتورة بسيطة',
        'plural_label' => 'الفواتير البسيطة',
        'nav_label' => 'فواتير بسيطة',
        'columns' => [
            'statement' => 'البيان',
        ],
        'fields' => [
            'statement' => 'البيان',
            'value' => 'القيمة',
        ],
    ],

    'payment_status' => [
        'unpaid' => 'غير مدفوعة',
        'partially_paid' => 'مدفوعة جزئيًا',
        'paid' => 'مدفوعة',
    ],

];
