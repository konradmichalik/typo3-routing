<?php

declare(strict_types=1);

$EM_CONF['routing_benchmark'] = [
    'title' => 'Routing Benchmark',
    'description' => 'Fixture extension benchmarking typo3-routing attribute routes against an equivalent plain PSR-15 middleware.',
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
