<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class DeeplApiClientService
{
    private const DOCUMENT_STATUS_SLEEP_SECONDS = 2;
    private const DOCUMENT_STATUS_ATTEMPTS = 60;

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
        $texts = array_values(array_filter(
            array_map(static fn($text): string => (string)$text, $texts),
            static fn(string $text): bool => $text !== ''
        ));

        if ($texts === []) {
            return [];
        }

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
            $payload['custom_instructions'] = array_values($customInstructions);
        }

        if ($tagHandling !== '') {
            $payload['tag_handling'] = $tagHandling;
        }

        $response = $this->requestJson($authKey, 'POST', '/v2/translate', $payload);
        $translations = $response['translations'] ?? [];
        if (!is_array($translations)) {
            throw new \RuntimeException('DeepL did not return text translations.');
        }

        $translatedTexts = [];
        foreach ($translations as $translation) {
            if (!is_array($translation) || !isset($translation['text'])) {
                throw new \RuntimeException('DeepL did not return a text translation.');
            }

            $translatedTexts[] = (string)$translation['text'];
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
            $response = $this->requestFactory->request(
                $this->buildUrl('/v2/document'),
                'POST',
                [
                    'headers' => $this->buildHeaders($authKey, false),
                    'http_errors' => false,
                    'multipart' => $multipart,
                ]
            );
        } finally {
            fclose($fileHandle);
        }

        return $this->decodeJsonResponse($response);
    }

    private function getDocumentStatus(string $authKey, string $documentId, string $documentKey): array
    {
        return $this->requestJson($authKey, 'POST', '/v2/document/' . rawurlencode($documentId), [
            'document_key' => $documentKey,
        ]);
    }

    private function downloadDocument(string $authKey, string $documentId, string $documentKey, string $targetPath): void
    {
        $response = $this->requestFactory->request(
            $this->buildUrl('/v2/document/' . rawurlencode($documentId) . '/result'),
            'POST',
            [
                'headers' => $this->buildHeaders($authKey),
                'http_errors' => false,
                'json' => [
                    'document_key' => $documentKey,
                ],
            ]
        );

        $this->assertSuccessfulResponse($response);
        file_put_contents($targetPath, (string)$response->getBody());
    }

    private function requestJson(string $authKey, string $method, string $path, array $payload = []): array
    {
        $options = [
            'headers' => $this->buildHeaders($authKey),
            'http_errors' => false,
        ];

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        $response = $this->requestFactory->request($this->buildUrl($path), $method, $options);

        return $this->decodeJsonResponse($response);
    }

    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $this->assertSuccessfulResponse($response);

        $contents = (string)$response->getBody();
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

        $body = (string)$response->getBody();
        $message = 'DeepL V3 API HTTP ' . $statusCode;

        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['message'])) {
                    $message .= ': ' . (string)$decoded['message'];
                } else {
                    $message .= ': ' . $body;
                }
            } catch (\JsonException) {
                $message .= ': ' . $body;
            }
        }

        throw new \RuntimeException($message);
    }

    private function buildUrl(string $path): string
    {
        return $this->configurationService->getApiBaseUrl() . '/' . ltrim($path, '/');
    }

    private function buildHeaders(string $authKey, bool $json = true): array
    {
        $headers = [
            'Authorization' => 'DeepL-Auth-Key ' . $authKey,
            'Accept' => 'application/json',
        ];

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }
}
