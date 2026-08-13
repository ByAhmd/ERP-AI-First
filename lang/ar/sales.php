<?php

declare(strict_types=1);

return [

    'navigation_group' => 'المبيعات',

    'taxes' => [
        'label' => 'الضريبة',
        'plural_label' => 'الضرائب',
        'nav_label' => 'الضرائب',
        'columns' => [
            'number' => 'رقم الضريبة',
            'name' => 'الاسم',
            'code' => 'الرمز',
            'rate' => 'النسبة',
            'account' => 'الحساب',
            'active' => 'نشط',
            'default' => 'افتراضية',
        ],
        'sections' => [
            'details' => 'بيانات الضريبة',
            'posting' => 'الترحيل',
        ],
        'fields' => [
            'name' => 'الاسم',
            'name_en' => 'الاسم بالإنجليزية',
            'category' => 'الرمز',
            'rate' => 'النسبة',
            'account' => 'الحساب',
            'is_active' => 'نشط',
            'is_default' => 'الضريبة الافتراضية',
        ],
        'hints' => [
            'category' => 'رمز فئة الضريبة المعتمد لدى هيئة الزكاة والضريبة والجمارك في الفاتورة الإلكترونية.',
            'rate' => 'النسبة المئوية. الضريبة الصفرية والمعفاة تكونان صفراً دائماً.',
            'account' => 'الحساب الذي تُرحَّل إليه قيمة الضريبة عند اعتماد المستند.',
            'is_default' => 'تُطبَّق على بنود المستندات التي لم يُحدَّد لها ضريبة.',
        ],
        'errors' => [
            'rate_on_zero_category' => 'الضريبة الصفرية والمعفاة لا تقبل نسبة أكبر من صفر.',
            'system_delete' => 'لا يمكن حذف ضريبة نظامية. يمكن تعطيلها بدلاً من ذلك.',
        ],
    ],

    'tax_category' => [
        'S' => 'خاضعة للضريبة',
        'Z' => 'صفرية',
        'E' => 'معفاة',
    ],

    'contact_type' => [
        'customer' => 'عميل',
        'supplier' => 'مورد',
    ],

    'contact_status' => [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ],

    'contacts' => [
        'customer_label' => 'عميل',
        'customers_label' => 'العملاء',
        'nav_label' => 'العملاء',
        'columns' => [
            'code' => 'الرقم المرجعي',
            'name' => 'اسم العميل',
            'organization' => 'اسم المنشأة',
            'phone' => 'رقم الاتصال',
            'email' => 'البريد الإلكتروني',
            'tax_number' => 'الرقم الضريبي',
            'status' => 'الحالة',
        ],
        'sections' => [
            'details' => 'بيانات العميل',
            'billing_address' => 'عنوان الفوترة',
            'shipping_address' => 'عنوان الشحن',
            'bank' => 'الحساب البنكي',
        ],
        'fields' => [
            'code' => 'الرقم المرجعي',
            'contact_name' => 'اسم العميل',
            'organization_name' => 'اسم المنشأة',
            'primary_contact_number' => 'رقم الاتصال الأساسي',
            'secondary_contact_number' => 'رقم الاتصال الثانوي',
            'primary_email' => 'البريد الإلكتروني الأساسي',
            'secondary_email' => 'البريد الإلكتروني الثانوي',
            'website' => 'الموقع الالكتروني',
            'tax_number' => 'الرقم الضريبي',
            'status' => 'الحالة',
            'currency' => 'العملة',
            'is_pos' => 'عميل نقاط بيع',
            'is_government_entity' => 'العميل جهة حكومية',
            'address' => 'العنوان',
            'city' => 'المدينة',
            'state' => 'المنطقة',
            'zip' => 'الرمز البريدي',
            'building_number' => 'رقم المبنى',
            'country' => 'الدولة',
            'copy_billing' => 'نسخ عنوان الفوترة',
            'bank_name' => 'اسم البنك',
            'bank_account_name' => 'اسم صاحب الحساب',
            'bank_country' => 'الدولة',
            'bank_currency' => 'العملة',
            'bank_iban' => 'الآيبان',
            'bank_account_number' => 'رقم الحساب',
            'bank_swift_code' => 'رمز السويفت',
            'bank_address' => 'عنوان البنك',
        ],
        'hints' => [
            'code' => 'يُولَّد تلقائياً إذا تُرك فارغاً.',
            'tax_number' => 'الرقم الضريبي للمنشأة الخاضعة للضريبة، ويظهر على الفاتورة الضريبية.',
            'building_number' => 'جزء من العنوان الوطني الذي تتطلبه الفاتورة الضريبية.',
            'is_government_entity' => 'لا يمكن إلغاء هذا الخيار بعد تفعيله؛ مبيعات الجهات الحكومية تُقرَّر بطريقة مختلفة.',
        ],
        'errors' => [
            'government_entity_locked' => 'لا يمكن إلغاء صفة الجهة الحكومية عن :contact بعد تفعيلها.',
        ],
    ],

];
