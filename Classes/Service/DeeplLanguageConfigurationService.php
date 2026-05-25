<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeeplLanguageConfigurationService
{
    private const STORAGE_DIRECTORY = 'ppl_deepl_v3_requests';
    private const LEGACY_STORAGE_DIRECTORIES = ['ppl_deepl_v3_translate', 'ppl_deepl'];
    private const STORAGE_FILE = 'languages.json';
    private const DEFAULT_ENABLED_LANGUAGE_CODES = [
        'DE',
        'EN',
        'ES',
        'IT',
        'NL',
        'PT',
    ];

    private const FALLBACK_LANGUAGES = [
        ['code' => 'AR', 'name' => 'Arabic'],
        ['code' => 'BG', 'name' => 'Bulgarian'],
        ['code' => 'CS', 'name' => 'Czech'],
        ['code' => 'DA', 'name' => 'Danish'],
        ['code' => 'DE', 'name' => 'German'],
        ['code' => 'EL', 'name' => 'Greek'],
        ['code' => 'EN', 'name' => 'English'],
        ['code' => 'ES', 'name' => 'Spanish'],
        ['code' => 'ET', 'name' => 'Estonian'],
        ['code' => 'FI', 'name' => 'Finnish'],
        ['code' => 'FR', 'name' => 'French'],
        ['code' => 'HE', 'name' => 'Hebrew'],
        ['code' => 'HU', 'name' => 'Hungarian'],
        ['code' => 'ID', 'name' => 'Indonesian'],
        ['code' => 'IT', 'name' => 'Italian'],
        ['code' => 'JA', 'name' => 'Japanese'],
        ['code' => 'KO', 'name' => 'Korean'],
        ['code' => 'LT', 'name' => 'Lithuanian'],
        ['code' => 'LV', 'name' => 'Latvian'],
        ['code' => 'NB', 'name' => 'Norwegian Bokmal'],
        ['code' => 'NL', 'name' => 'Dutch'],
        ['code' => 'PL', 'name' => 'Polish'],
        ['code' => 'PT', 'name' => 'Portuguese'],
        ['code' => 'RO', 'name' => 'Romanian'],
        ['code' => 'RU', 'name' => 'Russian'],
        ['code' => 'SK', 'name' => 'Slovak'],
        ['code' => 'SL', 'name' => 'Slovenian'],
        ['code' => 'SV', 'name' => 'Swedish'],
        ['code' => 'TH', 'name' => 'Thai'],
        ['code' => 'TR', 'name' => 'Turkish'],
        ['code' => 'UK', 'name' => 'Ukrainian'],
        ['code' => 'VI', 'name' => 'Vietnamese'],
        ['code' => 'ZH', 'name' => 'Chinese'],
    ];

    private ?DeeplApiClientService $apiClient;

    public function __construct(?DeeplApiClientService $apiClient = null)
    {
        $this->apiClient = $apiClient;
    }

    public function getApiCapabilities(): array
    {
        return [
            'supportsGlossaries' => true,
            'supportsFileTranslation' => true,
            'supportsWritingStyleTone' => false,
            'supportsStyleRules' => true,
            'supportsCustomInstructions' => true,
        ];
    }

    public function fetchRemoteLanguages(string $authKey): array
    {
        if (trim($authKey) === '') {
            return [];
        }

        return $this->withPreviousEnabledState($this->sanitizeLanguages($this->fetchApiLanguages($authKey)));
    }

    public function getSavedLanguages(): array
    {
        $this->migrateLegacyStorageFileIfNeeded();
        $storageFile = $this->getStorageFilePath();
        if (!is_file($storageFile)) {
            return $this->getFallbackLanguages();
        }

        $data = json_decode((string)file_get_contents($storageFile), true);
        if (!is_array($data) || !is_array($data['languages'] ?? null)) {
            return $this->getFallbackLanguages();
        }

        $languages = [];
        foreach ($data['languages'] as $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $languages[$code] = [
                'code' => $code,
                'name' => (string)($language['name'] ?? $code),
                'enabled' => array_key_exists('enabled', $language) ? (bool)$language['enabled'] : $this->isDefaultEnabledLanguage($code),
                'supportsSource' => array_key_exists('supportsSource', $language) ? (bool)$language['supportsSource'] : true,
                'supportsTarget' => array_key_exists('supportsTarget', $language) ? (bool)$language['supportsTarget'] : true,
            ];
        }

        $manualSelection = (bool)($data['manualSelection'] ?? false);

        return $languages !== []
            ? $this->sortLanguages($this->applyLegacyDefaultApprovals($this->sanitizeLanguages(array_values($languages)), $manualSelection), false)
            : $this->getFallbackLanguages();
    }

    public function saveLanguages(array $remoteLanguages, array $enabledCodes): array
    {
        $enabledLookup = array_fill_keys(array_map([$this, 'normalizeCode'], array_map('strval', $enabledCodes)), true);
        $languages = [];

        foreach ($this->sanitizeLanguages($remoteLanguages) as $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $languages[$code] = [
                'code' => $code,
                'name' => (string)($language['name'] ?? $code),
                'enabled' => isset($enabledLookup[$code]),
                'supportsSource' => array_key_exists('supportsSource', $language) ? (bool)$language['supportsSource'] : true,
                'supportsTarget' => array_key_exists('supportsTarget', $language) ? (bool)$language['supportsTarget'] : true,
            ];
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
                    'manualSelection' => true,
                    'languages' => array_values($languages),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        return $this->sortLanguages(array_values($languages), false);
    }

    public function getEnabledLanguages(): array
    {
        $languages = [];

        foreach ($this->getSavedLanguages() as $language) {
            if ((bool)($language['enabled'] ?? false)) {
                $languages[(string)$language['code']] = (string)$language['name'];
            }
        }

        if ($languages === [] && !$this->hasStorageFile()) {
            return $this->getFallbackLanguageOptions();
        }

        return $this->sortLanguageOptions($languages);
    }

    public function getEnabledSourceLanguages(): array
    {
        return $this->getEnabledLanguagesByCapability('supportsSource');
    }

    public function getEnabledTargetLanguages(): array
    {
        return $this->getEnabledLanguagesByCapability('supportsTarget');
    }

    public function getApprovalLanguageGroups(array $languages): array
    {
        $groups = [
            'enabled' => [],
            'disabled' => [],
        ];

        foreach ($this->sortLanguages($languages, false) as $language) {
            $group = (bool)($language['enabled'] ?? false) ? 'enabled' : 'disabled';
            $groups[$group][] = $language;
        }

        return $groups;
    }

    private function withPreviousEnabledState(array $languages): array
    {
        $enabledLookup = [];

        if ($this->hasStorageFile()) {
            foreach ($this->getSavedLanguages() as $language) {
                $enabledLookup[$this->normalizeCode((string)$language['code'])] = (bool)($language['enabled'] ?? false);
            }
        }

        foreach ($languages as $index => $language) {
            $code = (string)($language['code'] ?? '');
            $previousEnabledState = $this->getPreviousEnabledState($code, $enabledLookup);
            $languages[$index]['enabled'] = $previousEnabledState ?? $this->isDefaultEnabledLanguage($code);
        }

        return $this->sortLanguages($languages, false);
    }

    private function getFallbackLanguages(): array
    {
        $languages = [];
        foreach (self::FALLBACK_LANGUAGES as $language) {
            $code = (string)$language['code'];
            $languages[] = $language + [
                'enabled' => $this->isDefaultEnabledLanguage($code),
                'supportsSource' => true,
                'supportsTarget' => true,
            ];
        }

        return $this->sortLanguages($languages, false);
    }

    private function sanitizeLanguages(array $languages): array
    {
        $sanitized = [];
        foreach ($languages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $originalCode = $this->normalizeCode((string)($language['code'] ?? ''));
            $code = $this->normalizeApprovalLanguageCode($originalCode);
            if ($code === '') {
                continue;
            }

            $language['code'] = $code;
            $language['name'] = $this->normalizeApprovalLanguageName($code, (string)($language['name'] ?? $code));

            if (!isset($sanitized[$code])) {
                $sanitized[$code] = $language;
                continue;
            }

            $sanitized[$code]['enabled'] = (bool)($sanitized[$code]['enabled'] ?? false) || (bool)($language['enabled'] ?? false);
            $sanitized[$code]['supportsSource'] = (bool)($sanitized[$code]['supportsSource'] ?? false) || (bool)($language['supportsSource'] ?? false);
            $sanitized[$code]['supportsTarget'] = (bool)($sanitized[$code]['supportsTarget'] ?? false) || (bool)($language['supportsTarget'] ?? false);

            if ($originalCode === $code) {
                $sanitized[$code]['name'] = $language['name'];
            }
        }

        return array_values($sanitized);
    }

    private function normalizeApprovalLanguageCode(string $code): string
    {
        $code = $this->normalizeCode($code);

        return match (true) {
            $code === 'DE-DE' => 'DE',
            str_starts_with($code, 'EN-') => 'EN',
            str_starts_with($code, 'PT-') => 'PT',
            str_starts_with($code, 'ES-') => 'ES',
            $code === 'ZH-HANS' || $code === 'ZH-HANT' => 'ZH',
            str_contains($code, '-') => explode('-', $code, 2)[0],
            default => $code,
        };
    }

    private function normalizeApprovalLanguageName(string $code, string $name): string
    {
        $name = trim($name);

        return match ($code) {
            'DE' => 'German',
            'EN' => 'English',
            'ES' => 'Spanish',
            'PT' => 'Portuguese',
            'ZH' => 'Chinese',
            default => trim((string)preg_replace('/\s+\([^)]*\)$/', '', $name)) ?: $code,
        };
    }

    private function deduplicateEquivalentLanguages(array $languages): array
    {
        $indexed = [];

        foreach ($languages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $language['code'] = $code;
            $key = $this->getEquivalentLanguageKey($language);

            if (!isset($indexed[$key])) {
                $indexed[$key] = $language;
                continue;
            }

            $current = $indexed[$key];
            if ($this->shouldPreferLanguage($language, $current)) {
                if ((bool)($current['enabled'] ?? false) && !(bool)($language['enabled'] ?? false)) {
                    $language['enabled'] = true;
                }
                $indexed[$key] = $language;
                continue;
            }

            if ((bool)($language['enabled'] ?? false) && !(bool)($current['enabled'] ?? false)) {
                $current['enabled'] = true;
                $indexed[$key] = $current;
            }
        }

        return $this->removeGenericLanguageVariants(array_values($indexed));
    }

    private function getEquivalentLanguageKey(array $language): string
    {
        $code = $this->normalizeCode((string)($language['code'] ?? ''));
        $name = strtolower(trim((string)($language['name'] ?? '')));

        return match ($code) {
            'DE', 'DE-DE' => 'german',
            default => $name !== '' ? $name : $code,
        };
    }

    private function shouldPreferLanguage(array $candidate, array $current): bool
    {
        $candidateCode = $this->normalizeCode((string)($candidate['code'] ?? ''));
        $currentCode = $this->normalizeCode((string)($current['code'] ?? ''));

        if ($candidateCode === 'DE' && $currentCode === 'DE-DE') {
            return true;
        }

        if ($candidateCode === 'DE-DE' && $currentCode === 'DE') {
            return false;
        }

        $candidateSpecificity = substr_count($candidateCode, '-');
        $currentSpecificity = substr_count($currentCode, '-');

        if ($candidateSpecificity !== $currentSpecificity) {
            return $candidateSpecificity > $currentSpecificity;
        }

        if ((bool)($candidate['supportsTarget'] ?? false) !== (bool)($current['supportsTarget'] ?? false)) {
            return (bool)($candidate['supportsTarget'] ?? false);
        }

        return false;
    }

    private function removeGenericLanguageVariants(array $languages): array
    {
        $indexed = $this->indexByCode($languages);

        foreach (['EN', 'PT'] as $genericCode) {
            if (!isset($indexed[$genericCode])) {
                continue;
            }

            $specificCodes = array_values(
                array_filter(
                    array_keys($indexed),
                    static fn(string $code): bool => str_starts_with($code, $genericCode . '-')
                )
            );

            if ($specificCodes === []) {
                continue;
            }

            $genericEnabled = (bool)($indexed[$genericCode]['enabled'] ?? false);
            $hasEnabledSpecific = false;

            foreach ($specificCodes as $specificCode) {
                if ((bool)($indexed[$specificCode]['enabled'] ?? false)) {
                    $hasEnabledSpecific = true;
                    break;
                }
            }

            if ($genericEnabled && !$hasEnabledSpecific) {
                $preferredCode = $this->getPreferredSpecificCode($genericCode, $specificCodes);
                $indexed[$preferredCode]['enabled'] = true;
            }

            unset($indexed[$genericCode]);
        }

        return array_values($indexed);
    }

    private function getPreferredSpecificCode(string $genericCode, array $specificCodes): string
    {
        $preferredCodes = match ($genericCode) {
            'EN' => ['EN-GB', 'EN-US'],
            'PT' => ['PT-PT', 'PT-BR'],
            default => [],
        };

        foreach ($preferredCodes as $preferredCode) {
            if (in_array($preferredCode, $specificCodes, true)) {
                return $preferredCode;
            }
        }

        return (string)$specificCodes[0];
    }

    private function getFallbackLanguageOptions(): array
    {
        $languages = [];
        foreach ($this->getFallbackLanguages() as $language) {
            if ((bool)($language['enabled'] ?? false)) {
                $languages[(string)$language['code']] = (string)$language['name'];
            }
        }

        return $this->sortLanguageOptions($languages);
    }

    private function getEnabledLanguagesByCapability(string $capability): array
    {
        $languages = [];

        foreach ($this->getSavedLanguages() as $language) {
            if ((bool)($language['enabled'] ?? false) && (bool)($language[$capability] ?? true)) {
                $languages[(string)$language['code']] = (string)$language['name'];
            }
        }

        if ($languages === [] && !$this->hasStorageFile()) {
            return $this->getFallbackLanguageOptions();
        }

        return $this->sortLanguageOptions($languages);
    }

    private function applyLegacyDefaultApprovals(array $languages, bool $manualSelection): array
    {
        if ($manualSelection) {
            return $languages;
        }

        $enabledCount = 0;

        foreach ($languages as $language) {
            if ((bool)($language['enabled'] ?? false)) {
                $enabledCount++;
            }
        }

        if ($languages === [] || $enabledCount <= count(self::DEFAULT_ENABLED_LANGUAGE_CODES) || count($languages) <= count(self::DEFAULT_ENABLED_LANGUAGE_CODES)) {
            return $languages;
        }

        $excessiveEnabledCount = max(count(self::DEFAULT_ENABLED_LANGUAGE_CODES) + 1, (int)floor(count($languages) * 0.75));
        if ($enabledCount !== count($languages) && $enabledCount < $excessiveEnabledCount) {
            return $languages;
        }

        foreach ($languages as $index => $language) {
            $languages[$index]['enabled'] = $this->isDefaultEnabledLanguage((string)($language['code'] ?? ''));
        }

        return $languages;
    }

    private function indexByCode(array $languages): array
    {
        $indexed = [];
        foreach ($languages as $language) {
            $code = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($code !== '') {
                $indexed[$code] = $language + ['code' => $code];
            }
        }

        return $indexed;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function isDefaultEnabledLanguage(string $code): bool
    {
        return in_array($this->normalizeCode($code), self::DEFAULT_ENABLED_LANGUAGE_CODES, true);
    }

    private function getPreviousEnabledState(string $code, array $enabledLookup): ?bool
    {
        $code = $this->normalizeCode($code);
        if (array_key_exists($code, $enabledLookup)) {
            return (bool)$enabledLookup[$code];
        }

        $equivalentCodes = match ($code) {
            'DE' => ['DE-DE'],
            'DE-DE' => ['DE'],
            'EN' => ['EN-GB', 'EN-US'],
            'EN-GB', 'EN-US' => ['EN'],
            'PT' => ['PT-BR', 'PT-PT'],
            'PT-BR', 'PT-PT' => ['PT'],
            'ES' => ['ES-419'],
            'ES-419' => ['ES'],
            default => [],
        };

        $foundEquivalent = false;
        $enabledEquivalent = false;

        foreach ($equivalentCodes as $equivalentCode) {
            if (array_key_exists($equivalentCode, $enabledLookup)) {
                $foundEquivalent = true;
                $enabledEquivalent = $enabledEquivalent || (bool)$enabledLookup[$equivalentCode];
            }
        }

        return $foundEquivalent ? $enabledEquivalent : null;
    }

    private function sortLanguages(array $languages, bool $enabledFirst): array
    {
        usort(
            $languages,
            static function (array $left, array $right) use ($enabledFirst): int {
                if ($enabledFirst) {
                    $enabledCompare = ((int)($right['enabled'] ?? false)) <=> ((int)($left['enabled'] ?? false));
                    if ($enabledCompare !== 0) {
                        return $enabledCompare;
                    }
                }

                $nameCompare = strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
                if ($nameCompare !== 0) {
                    return $nameCompare;
                }

                return strcasecmp((string)($left['code'] ?? ''), (string)($right['code'] ?? ''));
            }
        );

        return $languages;
    }

    private function sortLanguageOptions(array $languages): array
    {
        asort($languages, SORT_NATURAL | SORT_FLAG_CASE);

        return $languages;
    }

    private function normalizeSourceCode(string $code): string
    {
        return match (true) {
            $code === 'DE-DE' => 'DE',
            str_starts_with($code, 'EN-') => 'EN',
            str_starts_with($code, 'PT-') => 'PT',
            str_starts_with($code, 'ES-') => 'ES',
            $code === 'ZH-HANS' || $code === 'ZH-HANT' => 'ZH',
            default => $code,
        };
    }

    private function getStorageFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::STORAGE_DIRECTORY . '/' . self::STORAGE_FILE;
    }

    private function hasStorageFile(): bool
    {
        $this->migrateLegacyStorageFileIfNeeded();

        return is_file($this->getStorageFilePath());
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

    private function fetchApiLanguages(string $authKey): array
    {
        $sourceLanguages = $this->getApiClient()->listTextTranslationLanguages($authKey, 'source');
        $targetLanguages = $this->getApiClient()->listTextTranslationLanguages($authKey, 'target');
        $languages = [];

        foreach ($sourceLanguages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = $this->normalizeCode((string)($language['language'] ?? $language['lang'] ?? ''));
            if ($code === '') {
                continue;
            }

            $languages[$code] = [
                'code' => $code,
                'name' => (string)($language['name'] ?? $code),
                'enabled' => false,
                'supportsSource' => true,
                'supportsTarget' => false,
            ];
        }

        foreach ($targetLanguages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $code = $this->normalizeCode((string)($language['language'] ?? $language['lang'] ?? ''));
            if ($code === '') {
                continue;
            }

            if (!isset($languages[$code])) {
                $languages[$code] = [
                    'code' => $code,
                    'name' => (string)($language['name'] ?? $code),
                    'enabled' => false,
                    'supportsSource' => false,
                    'supportsTarget' => true,
                ];
                continue;
            }

            $languages[$code]['name'] = (string)($language['name'] ?? $languages[$code]['name']);
            $languages[$code]['supportsTarget'] = true;
        }

        return array_values($languages);
    }

    public function normalizeSourceLanguage(string $language): string
    {
        $language = strtoupper($language);

        return match (true) {
            $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'EN-') => 'EN',
            str_starts_with($language, 'PT-') => 'PT',
            str_starts_with($language, 'ES-') => 'ES',
            $language === 'ZH-HANS' || $language === 'ZH-HANT' => 'ZH',
            str_contains($language, '-') => explode('-', $language, 2)[0],
            default => $language,
        };
    }

    public function normalizeTargetLanguage(string $language): string
    {
        return match (strtoupper($language)) {
            'EN' => 'EN-GB',
            'PT' => 'PT-PT',
            'DE-DE' => 'DE',
            default => strtoupper($language),
        };
    }

    public function normalizeGlossaryLanguage(string $language): string
    {
        return $this->normalizeSourceLanguage($language);
    }

    public function normalizeStyleRuleLanguage(string $language): string
    {
        return match (true) {
            str_starts_with(strtoupper($language), 'EN') => 'EN',
            strtoupper($language) === 'DE' || strtoupper($language) === 'DE-DE' => 'DE',
            str_starts_with(strtoupper($language), 'ES') => 'ES',
            str_starts_with(strtoupper($language), 'FR') => 'FR',
            str_starts_with(strtoupper($language), 'IT') => 'IT',
            str_starts_with(strtoupper($language), 'JA') => 'JA',
            str_starts_with(strtoupper($language), 'KO') => 'KO',
            str_starts_with(strtoupper($language), 'ZH') => 'ZH',
            default => '',
        };
    }

    public function buildGlossaryCombinationKey(string $sourceLanguage, string $targetLanguage): string
    {
        return $this->normalizeGlossaryLanguage($sourceLanguage)
            . ':'
            . $this->normalizeGlossaryLanguage($targetLanguage);
    }

    private function getApiClient(): DeeplApiClientService
    {
        if ($this->apiClient === null) {
            $this->apiClient = GeneralUtility::makeInstance(DeeplApiClientService::class);
        }

        return $this->apiClient;
    }
}
