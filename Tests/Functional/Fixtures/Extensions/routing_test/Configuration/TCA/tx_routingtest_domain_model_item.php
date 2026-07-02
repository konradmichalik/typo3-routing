<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Item',
        'label' => 'title',
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title'],
    ],
];
