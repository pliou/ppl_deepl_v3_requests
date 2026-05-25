<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeeplCustomInstructionConfigurationService
{
    private const STORAGE_DIRECTORY = 'ppl_deepl_v3_requests';
    private const LEGACY_STORAGE_DIRECTORY = 'ppl_deepl_v3_translate';
    private const STORAGE_FILE = 'custom-instructions.json';

    public function getSavedCustomInstructions(): array
    {
        $this->migrateLegacyStorageFileIfNeeded();
        $storageFile = $this->getStorageFilePath();
        if (!is_file($storageFile)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($storageFile), true);
        if (!is_array($data) || !is_array($data['customInstructions'] ?? null)) {
            return [];
        }

        return $this->normalizeInstructionRecords($data['customInstructions']);
    }

    public function saveCustomInstructions(array|string $instructions): array
    {
        $records = [];

        foreach ($this->normalizeCustomInstructions($instructions) as $instruction) {
            $records[] = [
                'id' => sha1($instruction),
                'text' => $instruction,
                'enabled' => true,
            ];
        }

        $storageDirectory = dirname($this->getStorageFilePath());
        if (!is_dir($storageDirectory)) {
            GeneralUtility::mkdir_deep($storageDirectory);
        }

        file_put_contents(
            $this->getStorageFilePath(),
            json_encode(
                [
                    'savedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'customInstructions' => $records,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        return $records;
    }

    public function getEnabledCustomInstructionTexts(): array
    {
        return array_values(array_filter(array_map(
            static fn(array $instruction): string => (bool)($instruction['enabled'] ?? false) ? (string)($instruction['text'] ?? '') : '',
            $this->getSavedCustomInstructions()
        )));
    }

    public function normalizeCustomInstructions(array|string $instructions): array
    {
        if (is_string($instructions)) {
            $instructions = preg_split('/\R+/', $instructions) ?: [];
        }

        $normalized = [];
        foreach ($instructions as $instruction) {
            $instruction = trim((string)$instruction);
            if ($instruction !== '') {
                $normalized[] = substr($instruction, 0, 300);
            }
        }

        return array_slice(array_values(array_unique($normalized)), 0, 10);
    }

    private function normalizeInstructionRecords(array $instructions): array
    {
        $records = [];

        foreach ($instructions as $instruction) {
            if (is_string($instruction)) {
                $text = trim($instruction);
                if ($text !== '') {
                    $records[] = [
                        'id' => sha1($text),
                        'text' => substr($text, 0, 300),
                        'enabled' => true,
                    ];
                }
                continue;
            }

            if (!is_array($instruction)) {
                continue;
            }

            $text = trim((string)($instruction['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $records[] = [
                'id' => (string)($instruction['id'] ?? sha1($text)),
                'text' => substr($text, 0, 300),
                'enabled' => array_key_exists('enabled', $instruction) ? (bool)$instruction['enabled'] : true,
            ];
        }

        return $records;
    }

    private function getStorageFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::STORAGE_DIRECTORY . '/' . self::STORAGE_FILE;
    }

    private function getLegacyStorageFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::LEGACY_STORAGE_DIRECTORY . '/' . self::STORAGE_FILE;
    }

    private function migrateLegacyStorageFileIfNeeded(): void
    {
        $storageFile = $this->getStorageFilePath();
        if (is_file($storageFile)) {
            return;
        }

        $legacyStorageFile = $this->getLegacyStorageFilePath();
        if (!is_file($legacyStorageFile)) {
            return;
        }

        $storageDirectory = dirname($storageFile);
        if (!is_dir($storageDirectory)) {
            GeneralUtility::mkdir_deep($storageDirectory);
        }

        copy($legacyStorageFile, $storageFile);
    }
}
