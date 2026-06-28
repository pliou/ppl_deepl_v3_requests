<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Domain\Dto;

final class RetryPolicy
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_INITIAL_DELAY_MILLISECONDS = 500;
    private const DEFAULT_MAX_DELAY_MILLISECONDS = 5000;

    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $initialDelayMilliseconds,
        public readonly int $maxDelayMilliseconds
    ) {}

    /**
     * Sensible defaults for retrying transient DeepL failures (HTTP 429/5xx)
     * with exponential backoff, as recommended by DeepL.
     */
    public static function default(): self
    {
        return new self(
            self::DEFAULT_MAX_ATTEMPTS,
            self::DEFAULT_INITIAL_DELAY_MILLISECONDS,
            self::DEFAULT_MAX_DELAY_MILLISECONDS
        );
    }

    /**
     * Exponential backoff delay (in milliseconds) for the given attempt number.
     * Attempts are 1-based; the delay before attempt N uses 2^(N-2) growth and
     * is capped at the configured maximum.
     */
    public function delayMillisecondsForAttempt(int $attempt): int
    {
        if ($attempt <= 1 || $this->initialDelayMilliseconds <= 0) {
            return max(0, $this->initialDelayMilliseconds);
        }

        $delay = $this->initialDelayMilliseconds * (2 ** ($attempt - 2));

        return (int)min($delay, $this->maxDelayMilliseconds);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'maxAttempts' => $this->maxAttempts,
            'initialDelayMilliseconds' => $this->initialDelayMilliseconds,
            'maxDelayMilliseconds' => $this->maxDelayMilliseconds,
        ];
    }
}
