<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL DeepL V3 Requests',
    'description' => 'Shared DeepL REST request services for PPL DeepL V3 packages.',
    'category' => 'services',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'version' => '12.4.1',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
