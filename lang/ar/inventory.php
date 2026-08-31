<?php

declare(strict_types=1);

/**
 * المخزون — التتبع والتكلفة والتسويات.
 */
return [

    'adjustment_kind' => [
        'opening' => 'رصيد افتتاحي',
        'count' => 'تسوية جرد',
    ],

    'adjustments' => [
        'label' => 'تسوية مخزون',
        'plural_label' => 'تسويات المخزون',
        'nav_label' => 'تسويات المخزون',
        'narration' => 'تسوية مخزون :reference',
        'columns' => [
            'reference' => 'المرجع',
            'kind' => 'النوع',
            'branch' => 'الموقع',
            'date' => 'التاريخ',
            'status' => 'الحالة',
        ],
        'sections' => [
            'details' => 'بيانات التسوية',
            'items' => 'البنود',
        ],
        'fields' => [
            'reference' => 'المرجع',
            'kind' => 'النوع',
            'branch' => 'الموقع',
            'date' => 'التاريخ',
            'description' => 'الوصف',
            'offset_account' => 'الحساب المقابل',
        ],
        'items' => [
            'product' => 'المنتج',
            'current_qty' => 'الكمية الحالية',
            'quantity_change' => 'التغير في الكمية',
            'unit_cost' => 'تكلفة الوحدة',
            'add' => 'إضافة بند',
        ],
        'hints' => [
            'offset_account' => 'الزيادة تُقيَّد دائنًا عليه والنقص مدينًا. الافتراضي تسويات المخزون.',
            'quantity_change' => 'موجب للزيادة وسالب للنقص. تكلفة الوحدة مطلوبة للزيادات فقط — النقص يُقيَّم بمتوسط التكلفة عند الاعتماد.',
        ],
        'actions' => [
            'save_draft' => 'حفظ كمسودة',
            'approve' => 'حفظ وموافقة',
            'approve_confirm' => 'بعد الاعتماد تتحرك الكميات ويُرحَّل القيد، ولا يمكن تعديل التسوية — التصحيح بتسوية مضادة.',
            'approved' => 'تم اعتماد التسوية وترحيلها.',
        ],
        'errors' => [
            'no_items' => 'لا يمكن اعتماد تسوية بلا بنود.',
            'already_approved' => 'التسوية :reference معتمدة بالفعل.',
            'not_draft' => 'لا يمكن تعديل تسوية بعد اعتمادها.',
            'zero_line' => 'البند :line بلا تغيير في الكمية.',
            'opening_negative' => 'الرصيد الافتتاحي لا يقبل كميات سالبة.',
            'cost_required_line' => 'البند :line زيادة بلا تكلفة وحدة.',
        ],
    ],

    'stock' => [
        'section' => 'المخزون',
        'quantity' => 'الكمية',
        'average_cost' => 'متوسط التكلفة',
        'total_value' => 'القيمة الإجمالية',
        'tracked' => 'مخزون؟',
        'per_branch' => 'الكميات حسب الموقع',
        'movements' => 'حركات المنتج',
        'movement_date' => 'تاريخ الحركة',
        'movement_source' => 'المرجع',
        'movement_qty' => 'التغيّر',
        'movement_cost' => 'القيمة المتوسطة',
        'movement_value' => 'قيمة الحركة',
        'movement_balance' => 'الكمية المتوفرة',
        'available_hint' => 'المتوفر: :quantity',
    ],

    'fields' => [
        'track_inventory' => 'يُخزن',
        'branch' => 'الموقع',
    ],

    'hints' => [
        'track_inventory' => 'تتبع الكمية والتكلفة لهذا المنتج. لا يمكن تغييره بعد أول حركة مخزون.',
        'track_frozen' => 'للمنتج حركات مخزون — لم يعد الخيار قابلًا للتغيير.',
        'credit_note_restock' => 'سبب الإرجاع يعيد الكميات للمخزون عند الاعتماد.',
        'returns_goods' => 'هل أُعيدت البضاعة فعليًا للمورد؟ عطّله لإشعارات تصحيح الأسعار.',
    ],

    'errors' => [
        'tracking_flag_frozen' => 'المنتج :product له حركات مخزون — خيار «يُخزن» لم يعد قابلًا للتغيير.',
        'insufficient_stock' => 'الكمية غير متوفرة للمنتج :product في :branch — المتوفر :available والمطلوب :requested.',
        'missing_cost_row' => 'لا يوجد سجل تكلفة للمنتج :product.',
        'branch_required' => 'المستند يحتوي بنودًا مخزنية ويحتاج تحديد الموقع.',
        'account_not_postable' => 'الحساب :account لا يقبل الترحيل المباشر.',
        'not_tracked' => 'المنتج :product غير مخزني.',
        'cost_required' => 'الزيادة للمنتج :product تحتاج تكلفة وحدة.',
    ],

];
