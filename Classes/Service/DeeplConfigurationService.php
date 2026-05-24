<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

final class DeeplConfigurationService
{
    private const EXTENSION_KEY = 'ppl_deepl_v3_requests';
    private const DEFAULT_API_BASE_URL = 'https://api.deepl.com';

    public function getAuthKey(array $settings = []): string
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY] ?? [];
        if (is_array($extensionConfiguration)) {
            return trim((string)($extensionConfiguration['authKey'] ?? ''));
        }

        return '';
    }

    public function getApiBaseUrl(array $settings = []): string
    {
        $settingsApiBaseUrl = trim((string)($settings['apiBaseUrl'] ?? ''));
        if ($settingsApiBaseUrl !== '') {
            return $this->normalizeApiBaseUrl($settingsApiBaseUrl);
        }

        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY] ?? [];
        if (is_array($extensionConfiguration)) {
            $configuredApiBaseUrl = trim((string)($extensionConfiguration['apiBaseUrl'] ?? ''));
            if ($configuredApiBaseUrl !== '') {
                return $this->normalizeApiBaseUrl($configuredApiBaseUrl);
            }
        }

        $environmentApiBaseUrl = trim((string)(getenv('DEEPL_API_BASE_URL') ?: ''));
        if ($environmentApiBaseUrl !== '') {
            return $this->normalizeApiBaseUrl($environmentApiBaseUrl);
        }

        return self::DEFAULT_API_BASE_URL;
    }

    public function getLoginPageUid(array $settings = []): int
    {
        if (isset($settings['loginPageUid']) && (int)$settings['loginPageUid'] > 0) {
            return (int)$settings['loginPageUid'];
        }

        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY] ?? [];
        if (is_array($extensionConfiguration) && (int)($extensionConfiguration['loginPageUid'] ?? 0) > 0) {
            return (int)$extensionConfiguration['loginPageUid'];
        }

        return 95;
    }

    private function normalizeApiBaseUrl(string $apiBaseUrl): string
    {
        $apiBaseUrl = rtrim($apiBaseUrl, '/');

        return $apiBaseUrl !== '' ? $apiBaseUrl : self::DEFAULT_API_BASE_URL;
    }
}
