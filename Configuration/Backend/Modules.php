<?php

declare(strict_types=1);

use Ppl\PplDeeplV3Requests\Controller\BackendConfigurationController;

return [
    'ppl_deepl_v3' => [
        'position' => ['after' => 'system'],
        'iconIdentifier' => 'module-ppl-deepl-v3',
        'labels' => [
            'title' => 'LLL:EXT:ppl_deepl_v3_requests/Resources/Private/Language/locallang.xlf:module.root.title',
            'shortDescription' => 'LLL:EXT:ppl_deepl_v3_requests/Resources/Private/Language/locallang.xlf:module.root.description',
        ],
    ],
    'ppl_deepl_v3_configuration' => [
        'parent' => 'ppl_deepl_v3',
        'position' => ['before' => '*'],
        'access' => 'user',
        'path' => '/module/ppl-deepl-v3/configuration',
        'iconIdentifier' => 'module-ppl-deepl-v3',
        'labels' => [
            'title' => 'LLL:EXT:ppl_deepl_v3_requests/Resources/Private/Language/locallang.xlf:module.configuration.title',
            'shortDescription' => 'LLL:EXT:ppl_deepl_v3_requests/Resources/Private/Language/locallang.xlf:module.configuration.description.v3',
        ],
        'routes' => [
            '_default' => [
                'target' => BackendConfigurationController::class . '::handleRequest',
            ],
        ],
    ],
];
