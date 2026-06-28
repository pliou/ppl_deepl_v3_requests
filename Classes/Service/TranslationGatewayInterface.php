<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use Ppl\PplDeeplV3Requests\Domain\Dto\DeepLRequestContext;

interface TranslationGatewayInterface
{
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
    ): array;
}
