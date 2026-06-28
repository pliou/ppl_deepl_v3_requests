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

    private ?AtomicJsonConfigurationStore $configurationStore = null;

    public function getSavedCustomInstructions(): array
    {
        $this->migrateLegacyStorageFileIfNeeded();
        $storageFile = $this->getStorageFilePath();
        if (!is_file($storageFile)) {
            return [];
        }

        $data = $this->getConfigurationStore()->readJsonFile($storageFile);
        if ($data === null || !is_array($data['customInstructions'] ?? null)) {
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

        $this->getConfigurationStore()->writeJsonFile(
            $this->getStorageFilePath(),
            [
                'savedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'customInstructions' => $records,
            ]
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
            $instruction = $this->normalizeCustomInstructionText((string)$instruction);
            if ($instruction !== '') {
                $normalized[] = $instruction;
            }
        }

        return array_slice(array_values(array_unique($normalized)), 0, 10);
    }

    private function normalizeInstructionRecords(array $instructions): array
    {
        $records = [];

        foreach ($instructions as $instruction) {
            if (is_string($instruction)) {
                $text = $this->normalizeStoredInstructionText($instruction);
                if ($text === null) {
                    continue;
                }
                $records[] = [
                    'id' => sha1($text),
                    'text' => $text,
                    'enabled' => true,
                ];
                continue;
            }

            if (!is_array($instruction)) {
                continue;
            }

            $text = $this->normalizeStoredInstructionText((string)($instruction['text'] ?? ''));
            if ($text === null) {
                continue;
            }

            $records[] = [
                'id' => (string)($instruction['id'] ?? sha1($text)),
                'text' => $text,
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

        $this->getConfigurationStore()->copyJsonFile($legacyStorageFile, $storageFile);
    }

    private function normalizeCustomInstructionText(string $instruction): string
    {
        $instruction = trim($instruction);
        if ($instruction === '') {
            return '';
        }

        if (!$this->isValidUtf8($instruction)) {
            throw new \InvalidArgumentException('Custom instructions must be valid UTF-8.', 1781869310);
        }

        return $this->truncateUtf8($instruction, 300);
    }

    private function normalizeStoredInstructionText(string $instruction): ?string
    {
        try {
            $instruction = $this->normalizeCustomInstructionText($instruction);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $instruction !== '' ? $instruction : null;
    }

    private function truncateUtf8(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        preg_match_all('/./us', $value, $characters);

        return implode('', array_slice($characters[0] ?? [], 0, $length));
    }

    private function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private function getConfigurationStore(): AtomicJsonConfigurationStore
    {
        if ($this->configurationStore === null) {
            $this->configurationStore = GeneralUtility::makeInstance(AtomicJsonConfigurationStore::class);
        }

        return $this->configurationStore;
    }
}
