<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use Ppl\PplDeeplV3Requests\Domain\Dto\DeepLRequestContext;
use Ppl\PplDeeplV3Requests\Domain\Dto\RetryPolicy;

final class DeepLRequestContextFactory
{
    private const PROFILE_ID = 'default';
    private const CONNECT_TIMEOUT_SECONDS = 10.0;
    private const TIMEOUT_SECONDS = 60.0;
    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private readonly DeeplConfigurationService $configurationService
    ) {}

    public function createDefaultContext(): DeepLRequestContext
    {
        $baseUri = $this->configurationService->getApiBaseUrl();
        $credentialReference = $this->credentialReference();
        $retryPolicy = RetryPolicy::default();

        return new DeepLRequestContext(
            self::PROFILE_ID,
            $this->configurationVersion($baseUri, $credentialReference, $retryPolicy),
            $baseUri,
            $credentialReference,
            self::CONNECT_TIMEOUT_SECONDS,
            self::TIMEOUT_SECONDS,
            self::MAX_RESPONSE_BYTES,
            $retryPolicy,
            bin2hex(random_bytes(16))
        );
    }

    public function assertCurrent(DeepLRequestContext $context): void
    {
        $current = $this->createDefaultContext();
        if (!hash_equals($current->configurationVersion, $context->configurationVersion)) {
            throw new \RuntimeException('DeepL request context is no longer current.', 1771334101);
        }
    }

    private function credentialReference(): string
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ppl_deepl_v3_requests'] ?? [];
        if (is_array($extensionConfiguration) && trim((string)($extensionConfiguration['authKey'] ?? '')) !== '') {
            return 'extension:ppl_deepl_v3_requests.authKey';
        }

        if (trim((string)(getenv('DEEPL_AUTH_KEY') ?: '')) !== '') {
            return 'env:DEEPL_AUTH_KEY';
        }

        throw new \RuntimeException('No DeepL credential reference is configured.', 1771334100);
    }

    private function configurationVersion(string $baseUri, string $credentialReference, RetryPolicy $retryPolicy): string
    {
        return hash('sha256', json_encode([
            'profileId' => self::PROFILE_ID,
            'baseUri' => $baseUri,
            'credentialReference' => $credentialReference,
            'connectTimeoutSeconds' => self::CONNECT_TIMEOUT_SECONDS,
            'timeoutSeconds' => self::TIMEOUT_SECONDS,
            'maxResponseBytes' => self::MAX_RESPONSE_BYTES,
            'retryPolicy' => $retryPolicy->toArray(),
        ], JSON_THROW_ON_ERROR));
    }
}
