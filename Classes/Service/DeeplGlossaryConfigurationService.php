<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeeplGlossaryConfigurationService
{
    private const STORAGE_DIRECTORY = 'ppl_deepl_v3_requests';
    private const LEGACY_STORAGE_DIRECTORIES = ['ppl_deepl_v3_translate', 'ppl_deepl'];
    private const STORAGE_FILE = 'glossaries.json';

    private ?DeeplLanguageConfigurationService $languageService;
    private ?DeeplApiClientService $apiClient;

    public function __construct(
        ?DeeplLanguageConfigurationService $languageService = null,
        ?DeeplApiClientService $apiClient = null
    ) {
        $this->languageService = $languageService;
        $this->apiClient = $apiClient;
    }

    public function fetchRemoteGlossaries(string $authKey): array
    {
        if (trim($authKey) === '') {
            return [];
        }

        $response = $this->getApiClient()->listGlossaries($authKey);
        $glossaries = $response['glossaries'] ?? [];
        $normalizedGlossaries = [];

        foreach ($glossaries as $glossary) {
            if (!is_array($glossary)) {
                continue;
            }

            $id = (string)($glossary['glossary_id'] ?? $glossary['glossaryId'] ?? $glossary['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $dictionaries = $this->normalizeDictionaries((array)($glossary['dictionaries'] ?? []));
            $normalizedGlossaries[] = [
                'id' => $id,
                'name' => (string)($glossary['name'] ?? $id),
                'creationTime' => $this->formatCreationTime($glossary['creation_time'] ?? $glossary['creationTime'] ?? null),
                'dictionaries' => $dictionaries,
                'languagePairs' => $this->buildLanguagePairs($dictionaries),
                'entryCount' => array_sum(array_map(static fn(array $dictionary): int => (int)$dictionary['entryCount'], $dictionaries)),
            ];
        }

        usort(
            $normalizedGlossaries,
            static fn(array $left, array $right): int => strcasecmp((string)$left['name'], (string)$right['name'])
        );

        return $normalizedGlossaries;
    }

    public function getSavedGlossaries(): array
    {
        $this->migrateLegacyStorageFileIfNeeded();
        $storageFile = $this->getStorageFilePath();
        if (!is_file($storageFile)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($storageFile), true);
        if (!is_array($data)) {
            return [];
        }

        $glossaries = $data['glossaries'] ?? [];

        if (!is_array($glossaries)) {
            return [];
        }

        return array_values(array_map(
            fn(array $glossary): array => $this->normalizeSavedGlossary($glossary),
            array_filter($glossaries, 'is_array')
        ));
    }

    public function saveSelectedGlossaries(array $remoteGlossaries, array $selectedIds): array
    {
        return $this->saveGlossaries($remoteGlossaries, $selectedIds);
    }

    public function saveGlossaries(array $remoteGlossaries, array $enabledIds): array
    {
        $enabledLookup = array_fill_keys(array_map('strval', $enabledIds), true);
        $savedGlossaries = [];

        foreach ($remoteGlossaries as $glossary) {
            $id = (string)($glossary['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $glossary['enabled'] = isset($enabledLookup[$id]);
            $savedGlossaries[] = $glossary;
        }

        $storageDirectory = dirname($this->getStorageFilePath());
        if (!is_dir($storageDirectory)) {
            GeneralUtility::mkdir_deep($storageDirectory);
        }

        file_put_contents(
            $this->getStorageFilePath(),
            json_encode(
                [
                    'savedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'glossaries' => $savedGlossaries,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        return $savedGlossaries;
    }

    public function getSelectedGlossaryIds(): array
    {
        return array_values(array_filter(array_map(
            static fn(array $glossary): string => (string)($glossary['id'] ?? ''),
            $this->getEnabledGlossaries()
        )));
    }

    public function getEnabledGlossaries(): array
    {
        return array_values(array_filter(
            $this->getSavedGlossaries(),
            static fn(array $glossary): bool => (bool)($glossary['enabled'] ?? false)
        ));
    }

    public function getGlossaryIdForLanguagePair(string $sourceLanguage, string $targetLanguage, string $preferredGlossaryId = ''): ?string
    {
        $combinationKey = $this->getLanguageService()->buildGlossaryCombinationKey($sourceLanguage, $targetLanguage);
        $fallbackGlossaryId = null;

        foreach ($this->getEnabledGlossaries() as $glossary) {
            $glossaryId = (string)($glossary['id'] ?? '');
            foreach (($glossary['dictionaries'] ?? []) as $dictionary) {
                if (($dictionary['combinationKey'] ?? '') === $combinationKey) {
                    if ($preferredGlossaryId !== '' && $glossaryId === $preferredGlossaryId) {
                        return $glossaryId;
                    }

                    $fallbackGlossaryId ??= $glossaryId;
                }
            }
        }

        return $fallbackGlossaryId;
    }

    public function hasGlossaryForLanguagePair(string $sourceLanguage, string $targetLanguage): bool
    {
        return $this->getGlossaryIdForLanguagePair($sourceLanguage, $targetLanguage) !== null;
    }

    public function getGlossaryCombinations(): array
    {
        $combinations = [];

        foreach ($this->getEnabledGlossaries() as $glossary) {
            foreach (($glossary['dictionaries'] ?? []) as $dictionary) {
                $combinationKey = (string)($dictionary['combinationKey'] ?? '');
                if ($combinationKey !== '') {
                    $combinations[$combinationKey] = true;
                }
            }
        }

        return $combinations;
    }

    public function getGlossaryOptionsForLanguagePair(string $sourceLanguage, string $targetLanguage): array
    {
        $combinationKey = $this->getLanguageService()->buildGlossaryCombinationKey($sourceLanguage, $targetLanguage);
        $options = [];

        foreach ($this->getEnabledGlossaries() as $glossary) {
            foreach (($glossary['dictionaries'] ?? []) as $dictionary) {
                if (($dictionary['combinationKey'] ?? '') !== $combinationKey) {
                    continue;
                }

                $id = (string)($glossary['id'] ?? '');
                if ($id !== '') {
                    $options[$id] = (string)($glossary['name'] ?? $id) . ' (' . (string)($dictionary['sourceLang'] ?? '') . ' -> ' . (string)($dictionary['targetLang'] ?? '') . ')';
                }
            }
        }

        return $options;
    }

    public function getGlossaryOptionsByCombination(): array
    {
        $optionsByCombination = [];

        foreach ($this->getEnabledGlossaries() as $glossary) {
            foreach (($glossary['dictionaries'] ?? []) as $dictionary) {
                $combinationKey = (string)($dictionary['combinationKey'] ?? '');
                $id = (string)($glossary['id'] ?? '');
                if ($combinationKey === '' || $id === '') {
                    continue;
                }

                $optionsByCombination[$combinationKey][$id] = (string)($glossary['name'] ?? $id) . ' (' . (string)($dictionary['sourceLang'] ?? '') . ' -> ' . (string)($dictionary['targetLang'] ?? '') . ')';
            }
        }

        return $optionsByCombination;
    }

    public function isGlossaryAvailableForLanguagePair(string $glossaryId, string $sourceLanguage, string $targetLanguage): bool
    {
        return $glossaryId !== '' && $this->getGlossaryIdForLanguagePair($sourceLanguage, $targetLanguage, $glossaryId) === $glossaryId;
    }

    private function normalizeDictionaries(array $dictionaries): array
    {
        $normalized = [];

        foreach ($dictionaries as $dictionary) {
            if (!is_object($dictionary) && !is_array($dictionary)) {
                continue;
            }

            $sourceLanguage = $this->readValue($dictionary, 'source_lang', 'sourceLang');
            $targetLanguage = $this->readValue($dictionary, 'target_lang', 'targetLang');
            if ($sourceLanguage === '' || $targetLanguage === '') {
                continue;
            }

            $sourceLanguage = $this->getLanguageService()->normalizeGlossaryLanguage($sourceLanguage);
            $targetLanguage = $this->getLanguageService()->normalizeGlossaryLanguage($targetLanguage);
            $normalized[] = [
                'sourceLang' => $sourceLanguage,
                'targetLang' => $targetLanguage,
                'entryCount' => (int)$this->readValue($dictionary, 'entry_count', 'entryCount'),
                'combinationKey' => $this->getLanguageService()->buildGlossaryCombinationKey($sourceLanguage, $targetLanguage),
            ];
        }

        return $normalized;
    }

    private function normalizeSavedGlossary(array $glossary): array
    {
        $dictionaries = $this->normalizeDictionaries((array)($glossary['dictionaries'] ?? []));

        $glossary['enabled'] = array_key_exists('enabled', $glossary) ? (bool)$glossary['enabled'] : true;
        $glossary['dictionaries'] = $dictionaries;
        $glossary['languagePairs'] = $this->buildLanguagePairs($dictionaries);
        $glossary['entryCount'] = array_sum(array_map(static fn(array $dictionary): int => (int)$dictionary['entryCount'], $dictionaries));

        return $glossary;
    }

    private function readValue(object|array $source, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return (string)$source[$key];
            }

            if (is_object($source) && isset($source->{$key})) {
                return (string)$source->{$key};
            }
        }

        return '';
    }

    private function buildLanguagePairs(array $dictionaries): string
    {
        $pairs = array_map(
            static fn(array $dictionary): string => $dictionary['sourceLang'] . ' -> ' . $dictionary['targetLang'],
            $dictionaries
        );

        return implode(', ', $pairs);
    }

    private function formatCreationTime(mixed $creationTime): string
    {
        if ($creationTime instanceof \DateTimeInterface) {
            return $creationTime->format(DATE_ATOM);
        }

        return is_scalar($creationTime) ? (string)$creationTime : '';
    }

    private function getStorageFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::STORAGE_DIRECTORY . '/' . self::STORAGE_FILE;
    }

    private function getLegacyStorageFilePaths(): array
    {
        return array_map(
            static fn(string $directory): string => Environment::getVarPath() . '/' . $directory . '/' . self::STORAGE_FILE,
            self::LEGACY_STORAGE_DIRECTORIES
        );
    }

    private function migrateLegacyStorageFileIfNeeded(): void
    {
        $storageFile = $this->getStorageFilePath();
        if (is_file($storageFile)) {
            return;
        }

        foreach ($this->getLegacyStorageFilePaths() as $legacyStorageFile) {
            if (!is_file($legacyStorageFile)) {
                continue;
            }

            $storageDirectory = dirname($storageFile);
            if (!is_dir($storageDirectory)) {
                GeneralUtility::mkdir_deep($storageDirectory);
            }

            copy($legacyStorageFile, $storageFile);
            return;
        }
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
}
