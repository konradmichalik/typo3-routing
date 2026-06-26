<?php

declare(strict_types=1);

$EM_CONF['routing_test'] = [
    'title' => 'Routing Test',
    'description' => 'Fixture extension exposing attribute routes for the dispatcher functional test.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'typo3_routing' => '',
        ],
    ],
];
