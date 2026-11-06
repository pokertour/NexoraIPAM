<?php

return [
    'default' => 'log',

    'mailers' => [
        'log' => ['transport' => 'log'],
        'array' => ['transport' => 'array'],
    ],

    'from' => ['address' => 'noreply@example.com', 'name' => 'NexoraIPAM'],
];
