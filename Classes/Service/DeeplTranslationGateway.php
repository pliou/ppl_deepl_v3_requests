<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use Ppl\PplDeeplV3Requests\Domain\Dto\DeepLRequestContext;

final class DeeplTranslationGateway implements TranslationGatewayInterface
{
    public function __construct(
        private readonly DeeplApiClientService $apiClient,
        private readonly DeeplConfigurationService $configurationService,
        private readonly DeepLRequestContextFactory $contextFactory
    ) {}

    /**
     * @param string[] $texts
     * @param string[] $customInstructions
     * @return string[]
     */
    public function translateTexts(
        array $texts,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId = null,
        string $styleRuleId = '',
        array $customInstructions = [],
        string $tagHandling = '',
        ?DeepLRequestContext $context = null
    ): array {
        $context ??= $this->contextFactory->createDefaultContext();
        $this->contextFactory->assertCurrent($context);

        $authKey = $this->configurationService->getAuthKey();
        if ($authKey === '') {
            throw new \RuntimeException('No DeepL auth key is configured.', 1771334002);
        }

        return $this->apiClient->translateTexts(
            $authKey,
            $texts,
            $sourceLanguage,
            $targetLanguage,
            $glossaryId,
            $styleRuleId,
            $customInstructions,
            $tagHandling
        );
    }
}
