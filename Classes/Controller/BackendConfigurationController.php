<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Controller;

use Ppl\PplDeeplV3Requests\Service\DeeplConfigurationService;
use Ppl\PplDeeplV3Requests\Service\DeeplGlossaryConfigurationService;
use Ppl\PplDeeplV3Requests\Service\DeeplLanguageConfigurationService;
use Ppl\PplDeeplV3Requests\Service\DeeplStyleRuleConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class BackendConfigurationController
{
    private const FORM_NAME = 'ppl_deepl_v3_requests';
    private const FORM_ACTION = 'configuration';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly FormProtectionFactory $formProtectionFactory,
        private readonly DeeplConfigurationService $configurationService,
        private readonly DeeplGlossaryConfigurationService $glossaryConfigurationService,
        private readonly DeeplLanguageConfigurationService $languageConfigurationService,
        private readonly DeeplStyleRuleConfigurationService $styleRuleConfigurationService
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $authKey = $this->configurationService->getAuthKey();
        $messages = [];
        $activeConfigTab = (string)($body['config_tab'] ?? $request->getQueryParams()['config_tab'] ?? 'glossaries');
        $activeConfigTab = in_array($activeConfigTab, ['glossaries', 'style_rules', 'languages'], true) ? $activeConfigTab : 'glossaries';
        $glossaries = $this->glossaryConfigurationService->getSavedGlossaries();
        $languages = $this->languageConfigurationService->getSavedLanguages();
        $styleRules = $this->styleRuleConfigurationService->getSavedStyleRules();
        $action = (string)($body['module_action'] ?? '');
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $formToken = $formProtection->generateToken(self::FORM_NAME, self::FORM_ACTION);

        if ($action !== ''
            && !$formProtection->validateToken((string)($body['form_token'] ?? ''), self::FORM_NAME, self::FORM_ACTION)
        ) {
            $action = '';
            $messages[] = [
                'type' => 'error',
                'text' => $this->translate('message.invalidFormToken'),
            ];
        }

        if (in_array($action, ['fetch_languages', 'save_remote_languages'], true)) {
            $activeConfigTab = 'languages';
            try {
                if ($authKey === '') {
                    throw new \RuntimeException($this->translate('error.missingAuthKey.v3'));
                }

                $languages = $this->languageConfigurationService->fetchRemoteLanguages($authKey);

                if ($action === 'save_remote_languages') {
                    $languages = $this->languageConfigurationService->saveLanguages(
                        $languages,
                        (array)($body['enabled_languages'] ?? [])
                    );
                    $messages[] = [
                        'type' => 'success',
                        'text' => $this->translate('message.languagesSaved', [count($languages)]),
                    ];
                } else {
                    $messages[] = [
                        'type' => 'success',
                        'text' => $this->translate('message.languagesFound', [count($languages)]),
                    ];
                }
            } catch (\Throwable $exception) {
                $messages[] = [
                    'type' => 'error',
                    'text' => $this->translate('message.languagesFetchFailed', [$exception->getMessage()]),
                ];
            }
        } elseif ($action === 'save_saved_languages') {
            $activeConfigTab = 'languages';
            $languages = $this->languageConfigurationService->saveLanguages(
                $languages,
                (array)($body['enabled_languages'] ?? [])
            );
            $messages[] = [
                'type' => 'success',
                'text' => $this->translate('message.languagesApproved', [count($this->languageConfigurationService->getEnabledLanguages())]),
            ];
        } elseif (in_array($action, ['fetch_glossaries', 'save_remote_glossaries'], true)) {
            $activeConfigTab = 'glossaries';
            try {
                if ($authKey === '') {
                    throw new \RuntimeException($this->translate('error.missingAuthKey.v3'));
                }

                $previouslySavedGlossaries = $this->glossaryConfigurationService->getSavedGlossaries();
                $glossaries = $this->glossaryConfigurationService->fetchRemoteGlossaries($authKey);
                $enabledIds = $previouslySavedGlossaries === []
                    ? array_map(static fn(array $glossary): string => (string)($glossary['id'] ?? ''), $glossaries)
                    : $this->glossaryConfigurationService->getSelectedGlossaryIds();
                $glossaries = $this->markEnabled($glossaries, $enabledIds);

                if ($action === 'save_remote_glossaries') {
                    $glossaries = $this->glossaryConfigurationService->saveGlossaries(
                        $glossaries,
                        (array)($body['enabled_glossaries'] ?? [])
                    );
                    $messages[] = [
                        'type' => 'success',
                        'text' => $this->translate('message.glossariesSaved', [count($glossaries), count($this->glossaryConfigurationService->getSelectedGlossaryIds())]),
                    ];
                } else {
                    $messages[] = [
                        'type' => 'success',
                        'text' => $this->translate('message.glossariesFound', [count($glossaries)]),
                    ];
                }
            } catch (\Throwable $exception) {
                $messages[] = [
                    'type' => 'error',
                    'text' => $this->translate('message.glossariesFetchFailed', [$exception->getMessage()]),
                ];
            }
        } elseif ($action === 'save_saved_glossaries') {
            $activeConfigTab = 'glossaries';
            $glossaries = $this->glossaryConfigurationService->saveGlossaries(
                $glossaries,
                (array)($body['enabled_glossaries'] ?? [])
            );
            $messages[] = [
                'type' => 'success',
                'text' => $this->translate('message.glossariesApproved', [count($this->glossaryConfigurationService->getSelectedGlossaryIds())]),
            ];
        }

        if (in_array($action, ['fetch_style_rules', 'save_remote_style_rules'], true)) {
            $activeConfigTab = 'style_rules';
            try {
                if ($authKey === '') {
                    throw new \RuntimeException($this->translate('error.missingAuthKey.v3'));
                }

                $previouslySavedStyleRules = $this->styleRuleConfigurationService->getSavedStyleRules();
                $styleRules = $this->styleRuleConfigurationService->fetchRemoteStyleRules($authKey);
                $enabledIds = $previouslySavedStyleRules === []
                    ? array_map(static fn(array $styleRule): string => (string)($styleRule['id'] ?? ''), $styleRules)
                    : $this->styleRuleConfigurationService->getSelectedStyleRuleIds();
                $styleRules = $this->markEnabled($styleRules, $enabledIds);

                if ($action === 'save_remote_style_rules') {
                    $styleRules = $this->styleRuleConfigurationService->saveStyleRules(
                        $styleRules,
                        (array)($body['enabled_style_rules'] ?? [])
                    );
                    $messages[] = [
                        'type' => 'success',
                        'text' => $this->translate('message.styleRulesSaved', [count($styleRules), count($this->styleRuleConfigurationService->getSelectedStyleRuleIds())]),
                    ];
                } else {
                    $messages[] = [
                        'type' => 'success',
                        'text' => $this->translate('message.styleRulesFound', [count($styleRules)]),
                    ];
                }
            } catch (\Throwable $exception) {
                $messages[] = [
                    'type' => 'error',
                    'text' => $this->translate('message.styleRulesFetchFailed', [$exception->getMessage()]),
                ];
            }
        } elseif ($action === 'save_saved_style_rules') {
            $activeConfigTab = 'style_rules';
            $styleRules = $this->styleRuleConfigurationService->saveStyleRules(
                $styleRules,
                (array)($body['enabled_style_rules'] ?? [])
            );
            $messages[] = [
                'type' => 'success',
                'text' => $this->translate('message.styleRulesApproved', [count($this->styleRuleConfigurationService->getSelectedStyleRuleIds())]),
            ];
        }

        $this->pageRenderer->addCssFile('EXT:ppl_deepl_v3_requests/Resources/Public/Css/site.css');
        $this->pageRenderer->addCssFile('EXT:ppl_deepl_v3_requests/Resources/Public/Css/backend.css');
        $this->pageRenderer->addJsFile('EXT:ppl_deepl_v3_requests/Resources/Public/Javascript/backend-scroll.js', 'module', true, false, '', true);
        $this->pageRenderer->addJsFile('EXT:ppl_deepl_v3_requests/Resources/Public/Javascript/backend-language-selection.js', 'module', true, false, '', true);

        $languageGroups = $this->languageConfigurationService->getApprovalLanguageGroups($languages);
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setModuleClass('ppl-deepl-v3-config-module');
        $moduleTemplate->setTitle($this->translate('config.title'));
        $moduleTemplate->assignMultiple([
            'activeConfigTab' => $activeConfigTab,
            'apiCapabilities' => $this->languageConfigurationService->getApiCapabilities(),
            'authKeyConfigured' => $authKey !== '',
            'disabledLanguages' => $languageGroups['disabled'],
            'enabledLanguages' => $languageGroups['enabled'],
            'formToken' => $formToken,
            'glossaries' => $glossaries,
            'languages' => $languages,
            'messages' => $messages,
            'routeConfiguration' => (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_configuration'),
            'routeConfigurationGlossaries' => (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_configuration', ['config_tab' => 'glossaries']),
            'routeConfigurationLanguages' => (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_configuration', ['config_tab' => 'languages']),
            'routeConfigurationStyleRules' => (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_configuration', ['config_tab' => 'style_rules']),
            'saveGlossaryAction' => $action === 'fetch_glossaries' ? 'save_remote_glossaries' : 'save_saved_glossaries',
            'saveLanguageAction' => $action === 'fetch_languages' ? 'save_remote_languages' : 'save_saved_languages',
            'saveStyleRuleAction' => $action === 'fetch_style_rules' ? 'save_remote_style_rules' : 'save_saved_style_rules',
            'styleRules' => $styleRules,
        ]);

        return $moduleTemplate->renderResponse('Backend/Configuration');
    }

    private function markEnabled(array $items, array $enabledIds): array
    {
        $enabledLookup = array_fill_keys(array_map('strval', $enabledIds), true);

        foreach ($items as $index => $item) {
            $items[$index]['enabled'] = isset($enabledLookup[(string)($item['id'] ?? '')]);
        }

        return $items;
    }

    private function getBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate($key, 'PplDeeplV3Requests', $arguments) ?? $key;
    }
}
