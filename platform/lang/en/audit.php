<?php

declare(strict_types=1);

return [

    'label' => 'Audit record',
    'plural_label' => 'Audit trail',
    'system_actor' => 'System',

    'columns' => [
        'at' => 'When',
        'actor' => 'Who',
        'event' => 'Action',
        'record' => 'Record',
        'ip' => 'IP address',
    ],

    'events' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
    ],

    'filters' => [
        'last_7_days' => 'Last 7 days',
    ],

    'detail' => [
        'summary' => 'Summary',
        'changes' => 'Changes',
        'before' => 'Before',
        'after' => 'After',
    ],

];
