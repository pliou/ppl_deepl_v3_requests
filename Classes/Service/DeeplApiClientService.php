<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use Ppl\PplDeeplV3Requests\Domain\Dto\RetryPolicy;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class DeeplApiClientService
{
    private const DOCUMENT_STATUS_SLEEP_SECONDS = 2;
    private const MAX_RETRY_AFTER_SECONDS = 60;
    private const DOCUMENT_STATUS_ATTEMPTS = 60;
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 10.0;
    private const HTTP_TIMEOUT_SECONDS = 60.0;
    private const DOCUMENT_HTTP_TIMEOUT_SECONDS = 180.0;
    private const MAX_TEXT_ITEMS = 50;
    private const MAX_TEXT_PAYLOAD_BYTES = 126976;
    private const MAX_CUSTOM_INSTRUCTIONS = 10;
    private const MAX_CUSTOM_INSTRUCTION_CHARACTERS = 300;
    private const MAX_ERROR_BODY_BYTES = 4096;
    private const MAX_JSON_RESPONSE_BYTES = 1048576;
    private const MAX_DOCUMENT_RESPONSE_BYTES = 104857600;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly DeeplConfigurationService $configurationService
    ) {}

    public function translateText(
        string $authKey,
        string $text,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId = null,
        string $styleRuleId = '',
        array $customInstructions = []
    ): string {
        if ($text === '') {
            return '';
        }

        $translations = $this->translateTexts(
            $authKey,
            [$text],
            $sourceLanguage,
            $targetLanguage,
            $glossaryId,
            $styleRuleId,
            $customInstructions
        );

        return (string)($translations[0] ?? '');
    }

    /**
     * @param string[] $texts
     * @return string[]
     */
    public function translateTexts(
        string $authKey,
        array $texts,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId = null,
        string $styleRuleId = '',
        array $customInstructions = [],
        string $tagHandling = ''
    ): array {
        $texts = array_values(array_map(static fn($text): string => (string)$text, $texts));
        $customInstructions = $this->normalizeCustomInstructions($customInstructions);

        if ($texts === []) {
            return [];
        }

        $translatedTexts = [];
        foreach ($this->buildTextPayloadChunks($texts, $sourceLanguage, $targetLanguage, $glossaryId, $styleRuleId, $customInstructions, $tagHandling) as $payload) {
            $response = $this->requestClassicJson($authKey, 'POST', '/v2/translate', $payload);
            $translations = $response['translations'] ?? [];
            if (!is_array($translations)) {
                throw new \RuntimeException('DeepL did not return text translations.');
            }

            $chunkTranslations = [];
            foreach ($translations as $translation) {
                if (!is_array($translation) || !isset($translation['text'])) {
                    throw new \RuntimeException('DeepL did not return a text translation.');
                }

                $chunkTranslations[] = (string)$translation['text'];
            }

            if (count($chunkTranslations) !== count($payload['text'])) {
                throw new \RuntimeException(sprintf(
                    'DeepL returned %d translations for %d request texts.',
                    count($chunkTranslations),
                    count($payload['text'])
                ));
            }

            array_push($translatedTexts, ...$chunkTranslations);
        }

        if (count($translatedTexts) !== count($texts)) {
            throw new \RuntimeException(sprintf(
                'DeepL returned %d translations for %d request texts.',
                count($translatedTexts),
                count($texts)
            ));
        }

        return $translatedTexts;
    }

    public function translateDocument(
        string $authKey,
        string $sourcePath,
        string $targetPath,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId = null
    ): void {
        $document = $this->uploadDocument(
            $authKey,
            $sourcePath,
            $sourceLanguage,
            $targetLanguage,
            $glossaryId
        );

        $documentId = (string)($document['document_id'] ?? '');
        $documentKey = (string)($document['document_key'] ?? '');
        if ($documentId === '' || $documentKey === '') {
            throw new \RuntimeException('DeepL did not return a document ID.');
        }

        for ($attempt = 0; $attempt < self::DOCUMENT_STATUS_ATTEMPTS; $attempt++) {
            $status = $this->getDocumentStatus($authKey, $documentId, $documentKey);
            $state = (string)($status['status'] ?? '');

            if ($state === 'done') {
                $this->downloadDocument($authKey, $documentId, $documentKey, $targetPath);
                return;
            }

            if ($state === 'error') {
                throw new \RuntimeException((string)($status['message'] ?? 'DeepL could not translate the document.'));
            }

            sleep(self::DOCUMENT_STATUS_SLEEP_SECONDS);
        }

        throw new \RuntimeException('Timed out while waiting for DeepL document translation.');
    }

    public function listGlossaries(string $authKey): array
    {
        return $this->requestJson($authKey, 'GET', '/v3/glossaries');
    }

    public function listStyleRules(string $authKey): array
    {
        return $this->requestJson($authKey, 'GET', '/v3/style_rules');
    }

    public function listLanguages(string $authKey, string $resource): array
    {
        $query = http_build_query(['resource' => $resource], '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson($authKey, 'GET', '/v3/languages?' . $query);
    }

    public function listTextTranslationLanguages(string $authKey, string $type): array
    {
        if (!in_array($type, ['source', 'target'], true)) {
            throw new \InvalidArgumentException('Language type must be source or target.');
        }

        $languages = $this->languageRowsFromResponse($this->listLanguages($authKey, 'translate_text'));
        $usableFlag = $type === 'source' ? 'usable_as_source' : 'usable_as_target';

        return array_values(array_filter(
            $languages,
            static fn(array $language): bool => (bool)($language[$usableFlag] ?? false)
        ));
    }

    private function uploadDocument(
        string $authKey,
        string $sourcePath,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId
    ): array {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Source file was not found.');
        }

        $fileHandle = fopen($sourcePath, 'rb');
        if ($fileHandle === false) {
            throw new \RuntimeException('Source file could not be read.');
        }

        $multipart = [
            [
                'name' => 'source_lang',
                'contents' => $sourceLanguage,
            ],
            [
                'name' => 'target_lang',
                'contents' => $targetLanguage,
            ],
            [
                'name' => 'file',
                'contents' => $fileHandle,
                'filename' => basename($sourcePath),
            ],
        ];

        if ($glossaryId !== null && $glossaryId !== '') {
            $multipart[] = [
                'name' => 'glossary_id',
                'contents' => $glossaryId,
            ];
        }

        try {
            $response = $this->sendWithRetry(
                $this->buildClassicUrl('/v2/document'),
                'POST',
                $this->httpOptions(self::DOCUMENT_HTTP_TIMEOUT_SECONDS) + [
                    'headers' => $this->buildHeaders($authKey, false),
                    'http_errors' => false,
                    'multipart' => $multipart,
                ]
            );
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }

        return $this->decodeJsonResponse($response);
    }

    private function getDocumentStatus(string $authKey, string $documentId, string $documentKey): array
    {
        return $this->requestClassicJson($authKey, 'POST', '/v2/document/' . rawurlencode($documentId), [
            'document_key' => $documentKey,
        ]);
    }

    private function downloadDocument(string $authKey, string $documentId, string $documentKey, string $targetPath): void
    {
        $response = $this->sendWithRetry(
            $this->buildClassicUrl('/v2/document/' . rawurlencode($documentId) . '/result'),
            'POST',
            $this->httpOptions(self::DOCUMENT_HTTP_TIMEOUT_SECONDS) + [
                'headers' => $this->buildHeaders($authKey),
                'http_errors' => false,
                'json' => [
                    'document_key' => $documentKey,
                ],
            ]
        );

        $this->assertSuccessfulResponse($response);
        $this->writeResponseBody($response, $targetPath);
    }

    private function requestJson(string $authKey, string $method, string $path, array $payload = []): array
    {
        return $this->requestJsonFromUrl($authKey, $method, $this->buildUrl($path), $payload);
    }

    private function requestClassicJson(string $authKey, string $method, string $path, array $payload = []): array
    {
        return $this->requestJsonFromUrl($authKey, $method, $this->buildClassicUrl($path), $payload);
    }

    private function requestJsonFromUrl(string $authKey, string $method, string $url, array $payload = []): array
    {
        $options = $this->httpOptions() + [
            'headers' => $this->buildHeaders($authKey, $payload !== []),
            'http_errors' => false,
        ];

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        $response = $this->sendWithRetry($url, $method, $options);

        return $this->decodeJsonResponse($response);
    }

    /**
     * Sends the HTTP request, retrying transient failures (HTTP 429 and 5xx, and
     * transport exceptions) with exponential backoff per the retry policy. The
     * returned response is handed back unchanged so the existing assertion path
     * produces the same exception type/message on permanent or exhausted failures.
     *
     * @param array<string, mixed> $options
     */
    private function sendWithRetry(string $url, string $method, array $options): ResponseInterface
    {
        $retryPolicy = RetryPolicy::default();
        $maxAttempts = max(1, $retryPolicy->maxAttempts);

        for ($attempt = 1; ; $attempt++) {
            $this->rewindMultipartStreams($options);

            try {
                $response = $this->requestFactory->request($url, $method, $options);
            } catch (\Throwable $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }
                $this->sleepMilliseconds($retryPolicy->delayMillisecondsForAttempt($attempt + 1));
                continue;
            }

            if ($attempt >= $maxAttempts || !$this->isRetryableStatus($response->getStatusCode())) {
                return $response;
            }

            $delayMilliseconds = $this->retryDelayMilliseconds($retryPolicy, $response, $attempt + 1);
            $this->sleepMilliseconds($delayMilliseconds);
        }
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    private function retryDelayMilliseconds(RetryPolicy $retryPolicy, ResponseInterface $response, int $nextAttempt): int
    {
        $backoffMilliseconds = $retryPolicy->delayMillisecondsForAttempt($nextAttempt);

        if ($response->getStatusCode() === 429) {
            $retryAfterMilliseconds = $this->retryAfterMilliseconds($response);
            if ($retryAfterMilliseconds !== null) {
                return max($backoffMilliseconds, $retryAfterMilliseconds);
            }
        }

        return $backoffMilliseconds;
    }

    private function retryAfterMilliseconds(ResponseInterface $response): ?int
    {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if ($retryAfter === '' || !ctype_digit($retryAfter)) {
            return null;
        }

        $seconds = min((int)$retryAfter, self::MAX_RETRY_AFTER_SECONDS);

        return $seconds * 1000;
    }

    private function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    /**
     * Rewinds any seekable multipart stream contents so a retried request
     * re-sends the full body (e.g. an uploaded document file handle).
     *
     * @param array<string, mixed> $options
     */
    private function rewindMultipartStreams(array $options): void
    {
        if (!is_array($options['multipart'] ?? null)) {
            return;
        }

        foreach ($options['multipart'] as $part) {
            $contents = is_array($part) ? ($part['contents'] ?? null) : null;
            if (is_resource($contents)) {
                @rewind($contents);
            }
        }
    }

    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $this->assertSuccessfulResponse($response);

        $contents = $this->readLimitedBody($response, self::MAX_JSON_RESPONSE_BYTES);
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function assertSuccessfulResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        $body = $this->readBodyUpTo($response, self::MAX_ERROR_BODY_BYTES + 1);
        $message = 'DeepL V3 API HTTP ' . $statusCode;

        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['message'])) {
                    $message .= ': ' . $this->truncateForMessage((string)$decoded['message']);
                } else {
                    $message .= ': ' . $this->truncateForMessage($body);
                }
            } catch (\JsonException) {
                $message .= ': ' . $this->truncateForMessage($body);
            }
        }

        throw new \RuntimeException($message);
    }

    private function buildUrl(string $path): string
    {
        return $this->configurationService->getApiBaseUrl() . '/' . ltrim($path, '/');
    }

    private function buildClassicUrl(string $path): string
    {
        return $this->configurationService->getApiBaseUrl() . '/' . ltrim($path, '/');
    }

    private function httpOptions(float $timeout = self::HTTP_TIMEOUT_SECONDS): array
    {
        return [
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            'timeout' => $timeout,
        ];
    }

    /**
     * @param string[] $texts
     * @param string[] $customInstructions
     * @return array<int, array<string, mixed>>
     */
    private function buildTextPayloadChunks(
        array $texts,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId,
        string $styleRuleId,
        array $customInstructions,
        string $tagHandling
    ): array {
        $this->assertTextItemsAreAllowed($texts);
        $this->assertTagHandlingIsAllowed($tagHandling);

        $chunks = [];
        $currentTexts = [];

        foreach ($texts as $text) {
            $candidateTexts = [...$currentTexts, $text];
            $candidatePayload = $this->buildTextPayload(
                $candidateTexts,
                $sourceLanguage,
                $targetLanguage,
                $glossaryId,
                $styleRuleId,
                $customInstructions,
                $tagHandling
            );

            if (count($candidateTexts) > self::MAX_TEXT_ITEMS || !$this->isTextPayloadAllowed($candidatePayload)) {
                if ($currentTexts === []) {
                    throw new \InvalidArgumentException('DeepL text payload is too large for a single text entry.');
                }

                $chunks[] = $this->buildTextPayload(
                    $currentTexts,
                    $sourceLanguage,
                    $targetLanguage,
                    $glossaryId,
                    $styleRuleId,
                    $customInstructions,
                    $tagHandling
                );
                $currentTexts = [$text];
                $singlePayload = $this->buildTextPayload(
                    $currentTexts,
                    $sourceLanguage,
                    $targetLanguage,
                    $glossaryId,
                    $styleRuleId,
                    $customInstructions,
                    $tagHandling
                );
                if (!$this->isTextPayloadAllowed($singlePayload)) {
                    throw new \InvalidArgumentException('DeepL text payload is too large for a single text entry.');
                }
                continue;
            }

            $currentTexts = $candidateTexts;
        }

        if ($currentTexts !== []) {
            $chunks[] = $this->buildTextPayload(
                $currentTexts,
                $sourceLanguage,
                $targetLanguage,
                $glossaryId,
                $styleRuleId,
                $customInstructions,
                $tagHandling
            );
        }

        return $chunks;
    }

    /**
     * @param string[] $texts
     * @param string[] $customInstructions
     * @return array<string, mixed>
     */
    private function buildTextPayload(
        array $texts,
        string $sourceLanguage,
        string $targetLanguage,
        ?string $glossaryId,
        string $styleRuleId,
        array $customInstructions,
        string $tagHandling
    ): array {
        $payload = [
            'text' => $texts,
            'source_lang' => $sourceLanguage,
            'target_lang' => $targetLanguage,
        ];

        if ($glossaryId !== null && $glossaryId !== '') {
            $payload['glossary_id'] = $glossaryId;
        }

        if ($styleRuleId !== '') {
            $payload['style_id'] = $styleRuleId;
        }

        if ($customInstructions !== []) {
            $payload['custom_instructions'] = $customInstructions;
        }

        if ($tagHandling !== '') {
            $payload['tag_handling'] = $tagHandling;
        }

        return $payload;
    }

    /**
     * @param string[] $texts
     */
    private function assertTextItemsAreAllowed(array $texts): void
    {
        foreach ($texts as $text) {
            if ($text === '') {
                throw new \InvalidArgumentException('DeepL text payload must not contain empty text entries.');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isTextPayloadAllowed(array $payload): bool
    {
        return strlen(json_encode($payload, JSON_THROW_ON_ERROR)) <= self::MAX_TEXT_PAYLOAD_BYTES;
    }

    private function assertTagHandlingIsAllowed(string $tagHandling): void
    {
        if (!in_array($tagHandling, ['', 'html', 'xml'], true)) {
            throw new \InvalidArgumentException('DeepL tag handling must be html, xml or empty.');
        }
    }

    /**
     * @param mixed[] $customInstructions
     * @return string[]
     */
    private function normalizeCustomInstructions(array $customInstructions): array
    {
        $instructions = [];
        foreach ($customInstructions as $customInstruction) {
            $instruction = trim((string)$customInstruction);
            if ($instruction === '') {
                continue;
            }
            if (mb_strlen($instruction, 'UTF-8') > self::MAX_CUSTOM_INSTRUCTION_CHARACTERS) {
                throw new \InvalidArgumentException('DeepL custom instruction is too large.');
            }
            $instructions[] = $instruction;
        }

        if (count($instructions) > self::MAX_CUSTOM_INSTRUCTIONS) {
            throw new \InvalidArgumentException('DeepL text payload contains too many custom instructions.');
        }

        return $instructions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function languageRowsFromResponse(array $response): array
    {
        $languages = is_array($response['languages'] ?? null) ? $response['languages'] : $response;
        $rows = [];

        foreach ($languages as $language) {
            if (is_array($language)) {
                $rows[] = $language;
            }
        }

        return $rows;
    }

    private function writeResponseBody(ResponseInterface $response, string $targetPath): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Target directory could not be created.');
        }

        $temporaryPath = $targetPath . '.tmp.' . bin2hex(random_bytes(6));
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            throw new \RuntimeException('Translated document could not be opened for writing.');
        }

        try {
            $bytesWritten = 0;
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                $bytesWritten += strlen($chunk);
                if ($bytesWritten > self::MAX_DOCUMENT_RESPONSE_BYTES) {
                    throw new \RuntimeException('Translated document response is too large.');
                }
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Translated document could not be written.');
                }
            }
            fclose($target);
            $target = null;
            if (!rename($temporaryPath, $targetPath)) {
                throw new \RuntimeException('Translated document could not be moved into place.');
            }
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function truncateForMessage(string $body): string
    {
        if (strlen($body) <= self::MAX_ERROR_BODY_BYTES) {
            return $body;
        }

        return substr($body, 0, self::MAX_ERROR_BODY_BYTES) . '... [truncated]';
    }

    private function readLimitedBody(ResponseInterface $response, int $maxBytes): string
    {
        $body = $this->readBodyUpTo($response, $maxBytes + 1);
        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException('DeepL response is too large.');
        }

        return $body;
    }

    private function readBodyUpTo(ResponseInterface $response, int $maxBytes): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $contents = '';
        while (!$body->eof() && strlen($contents) < $maxBytes) {
            $remainingBytes = $maxBytes - strlen($contents);
            $chunk = $body->read(min(8192, $remainingBytes));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    private function buildHeaders(string $authKey, bool $json = true): array
    {
        $authKey = $this->resolveAuthKey($authKey);
        $headers = [
            'Authorization' => 'DeepL-Auth-Key ' . $authKey,
            'Accept' => 'application/json',
        ];

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function resolveAuthKey(string $authKey): string
    {
        $configuredAuthKey = trim($this->configurationService->getAuthKey());
        if ($configuredAuthKey === '') {
            throw new \RuntimeException('No DeepL auth key is configured.', 1771334002);
        }

        $providedAuthKey = trim($authKey);
        if ($providedAuthKey !== '' && !hash_equals($configuredAuthKey, $providedAuthKey)) {
            throw new \RuntimeException('DeepL auth key must come from the central request configuration.', 1771334003);
        }

        return $configuredAuthKey;
    }
}
