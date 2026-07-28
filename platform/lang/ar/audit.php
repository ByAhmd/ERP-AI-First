<?php

declare(strict_types=1);

return [

    'label' => 'سجل تدقيق',
    'plural_label' => 'سجل التدقيق',
    'system_actor' => 'النظام',

    'columns' => [
        'at' => 'الوقت',
        'actor' => 'المستخدم',
        'event' => 'الإجراء',
        'record' => 'السجل',
        'ip' => 'عنوان IP',
    ],

    'events' => [
        'created' => 'إنشاء',
        'updated' => 'تعديل',
        'deleted' => 'حذف',
        'restored' => 'استرجاع',
    ],

    'filters' => [
        'last_7_days' => 'آخر ٧ أيام',
    ],

    'detail' => [
        'summary' => 'الملخص',
        'changes' => 'التغييرات',
        'before' => 'قبل',
        'after' => 'بعد',
    ],

];
