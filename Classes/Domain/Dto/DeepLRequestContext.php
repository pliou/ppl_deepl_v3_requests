<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Domain\Dto;

final class DeepLRequestContext
{
    public function __construct(
        public readonly string $profileId,
        public readonly string $configurationVersion,
        public readonly string $baseUri,
        public readonly string $credentialReference,
        public readonly float $connectTimeoutSeconds,
        public readonly float $timeoutSeconds,
        public readonly int $maxResponseBytes,
        public readonly RetryPolicy $retryPolicy,
        public readonly string $correlationId
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profileId' => $this->profileId,
            'configurationVersion' => $this->configurationVersion,
            'baseUri' => $this->baseUri,
            'credentialReference' => $this->credentialReference,
            'connectTimeoutSeconds' => $this->connectTimeoutSeconds,
            'timeoutSeconds' => $this->timeoutSeconds,
            'maxResponseBytes' => $this->maxResponseBytes,
            'retryPolicy' => $this->retryPolicy->toArray(),
            'correlationId' => $this->correlationId,
        ];
    }
}
