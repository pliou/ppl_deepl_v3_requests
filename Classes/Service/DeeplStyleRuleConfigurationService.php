<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeeplStyleRuleConfigurationService
{
    private const STORAGE_DIRECTORY = 'ppl_deepl_v3_requests';
    private const LEGACY_STORAGE_DIRECTORY = 'ppl_deepl_v3_translate';
    private const STORAGE_FILE = 'style-rules.json';

    private ?DeeplLanguageConfigurationService $languageService;
    private ?DeeplApiClientService $apiClient;
    private ?AtomicJsonConfigurationStore $configurationStore = null;

    public function __construct(
        ?DeeplLanguageConfigurationService $languageService = null,
        ?DeeplApiClientService $apiClient = null
    ) {
        $this->languageService = $languageService;
        $this->apiClient = $apiClient;
    }

    public function fetchRemoteStyleRules(string $authKey): array
    {
        if (trim($authKey) === '') {
            return [];
        }

        $response = $this->getApiClient()->listStyleRules($authKey);
        $styleRules = $response['style_rules'] ?? [];
        $normalizedStyleRules = [];

        foreach ($styleRules as $styleRule) {
            if (!is_array($styleRule)) {
                continue;
            }

            $id = (string)($styleRule['style_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $language = $this->getLanguageService()->normalizeStyleRuleLanguage((string)($styleRule['language'] ?? ''));

            $normalizedStyleRules[] = [
                'id' => $id,
                'name' => (string)($styleRule['name'] ?? $id),
                'language' => $language,
                'version' => (int)($styleRule['version'] ?? 0),
                'updatedTime' => (string)($styleRule['updated_time'] ?? ''),
                'label' => trim((string)($styleRule['name'] ?? $id) . ($language !== '' ? ' (' . $language . ')' : '')),
            ];
        }

        usort(
            $normalizedStyleRules,
            static fn(array $left, array $right): int => strcasecmp((string)$left['label'], (string)$right['label'])
        );

        return $normalizedStyleRules;
    }

    public function getSavedStyleRules(): array
    {
        $this->migrateLegacyStorageFileIfNeeded();
        $storageFile = $this->getStorageFilePath();
        if (!is_file($storageFile)) {
            return [];
        }

        $data = $this->getConfigurationStore()->readJsonFile($storageFile);
        if ($data === null) {
            return [];
        }

        $styleRules = $data['styleRules'] ?? [];

        if (!is_array($styleRules)) {
            return [];
        }

        return array_values(array_map(
            static function (array $styleRule): array {
                $styleRule['enabled'] = array_key_exists('enabled', $styleRule) ? (bool)$styleRule['enabled'] : true;

                return $styleRule;
            },
            array_filter($styleRules, 'is_array')
        ));
    }

    public function saveSelectedStyleRules(array $remoteStyleRules, array $selectedIds): array
    {
        return $this->saveStyleRules($remoteStyleRules, $selectedIds);
    }

    public function saveStyleRules(array $remoteStyleRules, array $enabledIds): array
    {
        $enabledLookup = array_fill_keys(array_map('strval', $enabledIds), true);
        $savedStyleRules = [];

        foreach ($remoteStyleRules as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $styleRule['enabled'] = isset($enabledLookup[$id]);
            $savedStyleRules[] = $styleRule;
        }

        $this->getConfigurationStore()->writeJsonFile(
            $this->getStorageFilePath(),
            [
                'savedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'styleRules' => $savedStyleRules,
            ]
        );

        return $savedStyleRules;
    }

    public function getSelectedStyleRuleIds(): array
    {
        return array_values(array_filter(array_map(
            static fn(array $styleRule): string => (string)($styleRule['id'] ?? ''),
            $this->getEnabledStyleRules()
        )));
    }

    public function getEnabledStyleRules(): array
    {
        return array_values(array_filter(
            $this->getSavedStyleRules(),
            static fn(array $styleRule): bool => (bool)($styleRule['enabled'] ?? false)
        ));
    }

    public function getStyleRuleOptions(): array
    {
        $options = [];

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            if ($id !== '') {
                $options[$id] = (string)($styleRule['label'] ?? $styleRule['name'] ?? $id);
            }
        }

        return $options;
    }

    public function getStyleRuleOptionsForLanguage(string $targetLanguage): array
    {
        $targetLanguage = $this->getLanguageService()->normalizeStyleRuleLanguage($targetLanguage);
        if ($targetLanguage === '') {
            return [];
        }

        $options = [];

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            if ($id === '' || (string)($styleRule['language'] ?? '') !== $targetLanguage) {
                continue;
            }

            $options[$id] = (string)($styleRule['label'] ?? $styleRule['name'] ?? $id);
        }

        return $options;
    }

    public function getStyleRuleDisplayOptions(): array
    {
        $options = [];

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $options[$id] = [
                'label' => (string)($styleRule['label'] ?? $styleRule['name'] ?? $id),
                'language' => (string)($styleRule['language'] ?? ''),
            ];
        }

        return $options;
    }

    public function getStyleRuleOptionsByLanguage(): array
    {
        $optionsByLanguage = [];

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            $language = (string)($styleRule['language'] ?? '');
            if ($id === '' || $language === '') {
                continue;
            }

            $optionsByLanguage[$language][$id] = (string)($styleRule['label'] ?? $styleRule['name'] ?? $id);
        }

        return $optionsByLanguage;
    }

    public function isStyleRuleAvailableForLanguage(string $styleRuleId, string $targetLanguage): bool
    {
        if ($styleRuleId === '') {
            return false;
        }

        $targetLanguage = $this->getLanguageService()->normalizeStyleRuleLanguage($targetLanguage);
        if ($targetLanguage === '') {
            return false;
        }

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            if ((string)($styleRule['id'] ?? '') !== $styleRuleId) {
                continue;
            }

            return (string)($styleRule['language'] ?? '') === $targetLanguage;
        }

        return false;
    }

    private function getStorageFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::STORAGE_DIRECTORY . '/' . self::STORAGE_FILE;
    }

    private function getLegacyStorageFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::LEGACY_STORAGE_DIRECTORY . '/' . self::STORAGE_FILE;
    }

    private function migrateLegacyStorageFileIfNeeded(): void
    {
        $storageFile = $this->getStorageFilePath();
        if (is_file($storageFile)) {
            return;
        }

        $legacyStorageFile = $this->getLegacyStorageFilePath();
        if (!is_file($legacyStorageFile)) {
            return;
        }

        $this->getConfigurationStore()->copyJsonFile($legacyStorageFile, $storageFile);
    }

    private function getLanguageService(): DeeplLanguageConfigurationService
    {
        if ($this->languageService === null) {
            $this->languageService = GeneralUtility::makeInstance(DeeplLanguageConfigurationService::class);
        }

        return $this->languageService;
    }

    private function getApiClient(): DeeplApiClientService
    {
        if ($this->apiClient === null) {
            $this->apiClient = GeneralUtility::makeInstance(DeeplApiClientService::class);
        }

        return $this->apiClient;
    }

    private function getConfigurationStore(): AtomicJsonConfigurationStore
    {
        if ($this->configurationStore === null) {
            $this->configurationStore = GeneralUtility::makeInstance(AtomicJsonConfigurationStore::class);
        }

        return $this->configurationStore;
    }
}
