<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL DeepL V3 Requests',
    'description' => 'Shared DeepL REST request services for PPL DeepL V3 packages.',
    'category' => 'services',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'version' => '14.3.0',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
