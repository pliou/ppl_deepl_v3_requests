<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

final class DeeplConfigurationService
{
    private const EXTENSION_KEY = 'ppl_deepl_v3_requests';
    private const DEFAULT_API_BASE_URL = 'https://api.deepl.com';
    private const ALLOWED_API_BASE_URLS = [
        'api.deepl.com' => 'https://api.deepl.com',
        'api-free.deepl.com' => 'https://api-free.deepl.com',
        'api-us.deepl.com' => 'https://api-us.deepl.com',
        'api-jp.deepl.com' => 'https://api-jp.deepl.com',
    ];

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

        return trim((string)(getenv('DEEPL_AUTH_KEY') ?: ''));
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

    private function normalizeApiBaseUrl(string $apiBaseUrl): string
    {
        $apiBaseUrl = rtrim(trim($apiBaseUrl), '/');
        if ($apiBaseUrl === '') {
            return self::DEFAULT_API_BASE_URL;
        }

        $parts = parse_url($apiBaseUrl);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('DeepL API base URL is invalid.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            throw new \InvalidArgumentException('DeepL API base URL must use HTTPS.');
        }

        $path = trim((string)($parts['path'] ?? ''), '/');
        if (
            $path !== ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('DeepL API base URL must only contain scheme and host.');
        }

        if (!isset(self::ALLOWED_API_BASE_URLS[$host])) {
            throw new \InvalidArgumentException('DeepL API base URL must be https://api.deepl.com, https://api-free.deepl.com, https://api-us.deepl.com or https://api-jp.deepl.com.');
        }

        return self::ALLOWED_API_BASE_URLS[$host];
    }
}
