<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class DeeplConfigurationService
{
    private const EXTENSION_KEY = 'ppl_deepl_v3_requests';
    private const DEFAULT_API_BASE_URL = 'https://api.deepl.com';

    public function getAuthKey(array $settings = []): string
    {
        $settingsAuthKey = trim((string)($settings['authKey'] ?? ''));
        if ($settingsAuthKey !== '') {
            return $settingsAuthKey;
        }

        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY] ?? [];
        if (is_array($extensionConfiguration)) {
            $configuredAuthKey = trim((string)($extensionConfiguration['authKey'] ?? ''));
            if ($configuredAuthKey !== '') {
                return $configuredAuthKey;
            }
        }

        $environmentAuthKey = trim((string)(getenv('DEEPL_AUTH_KEY') ?: ''));
        if ($environmentAuthKey !== '') {
            return $environmentAuthKey;
        }

        return $this->getTypoScriptFallbackAuthKey();
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

        $typoScriptApiBaseUrl = $this->getTypoScriptFallbackValue('apiBaseUrl');
        if ($typoScriptApiBaseUrl !== '') {
            return $this->normalizeApiBaseUrl($typoScriptApiBaseUrl);
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

    private function getTypoScriptFallbackAuthKey(): string
    {
        return $this->getTypoScriptFallbackValue('authKey');
    }

    private function getTypoScriptFallbackValue(string $settingName): string
    {
        $constantsFile = ExtensionManagementUtility::extPath('ppl_deepl_v3_requests') . 'Configuration/TypoScript/constants.typoscript';
        if (!is_file($constantsFile)) {
            return '';
        }

        $contents = (string)file_get_contents($constantsFile);
        if (!preg_match('/^plugin\\.tx_ppldeeplv3requests\\.settings\\.' . preg_quote($settingName, '/') . '\\s*=\\s*(\\S+)\\s*$/m', $contents, $matches)) {
            return '';
        }

        return trim((string)($matches[1] ?? ''));
    }

    private function normalizeApiBaseUrl(string $apiBaseUrl): string
    {
        $apiBaseUrl = rtrim($apiBaseUrl, '/');

        return $apiBaseUrl !== '' ? $apiBaseUrl : self::DEFAULT_API_BASE_URL;
    }
}
