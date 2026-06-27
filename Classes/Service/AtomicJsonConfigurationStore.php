<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3Requests\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AtomicJsonConfigurationStore
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public function readJsonFile(string $absoluteFilePath): ?array
    {
        if (!is_file($absoluteFilePath)) {
            return null;
        }

        $contents = file_get_contents($absoluteFilePath);
        if (!is_string($contents)) {
            return null;
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function copyJsonFile(string $sourceFilePath, string $targetFilePath): void
    {
        $data = $this->readJsonFile($sourceFilePath);
        if ($data === null) {
            return;
        }

        $this->writeJsonFile($targetFilePath, $data);
    }

    public function writeJsonFile(string $absoluteFilePath, array $payload): void
    {
        $storageDirectory = dirname($absoluteFilePath);
        if (!is_dir($storageDirectory)) {
            GeneralUtility::mkdir_deep($storageDirectory);
        }

        $lockHandle = fopen($absoluteFilePath . '.lock', 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException('Could not open configuration lock file.', 1781869301);
        }

        $temporaryFilePath = $absoluteFilePath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $encodedPayload = json_encode($payload, self::JSON_FLAGS);
        $expectedHash = hash('sha256', $encodedPayload);
        $permissions = is_file($absoluteFilePath) ? (fileperms($absoluteFilePath) & 0777) : 0664;

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock configuration file.', 1781869302);
            }

            $bytesWritten = file_put_contents($temporaryFilePath, $encodedPayload);
            if ($bytesWritten === false || $bytesWritten !== strlen($encodedPayload)) {
                throw new \RuntimeException('Could not write temporary configuration file.', 1781869303);
            }

            $writtenContents = file_get_contents($temporaryFilePath);
            if (!is_string($writtenContents) || hash('sha256', $writtenContents) !== $expectedHash) {
                throw new \RuntimeException('Configuration write verification failed.', 1781869304);
            }

            json_decode($writtenContents, true, 512, JSON_THROW_ON_ERROR);
            chmod($temporaryFilePath, $permissions);

            if (!rename($temporaryFilePath, $absoluteFilePath)) {
                throw new \RuntimeException('Could not replace configuration file atomically.', 1781869305);
            }

            $storedContents = file_get_contents($absoluteFilePath);
            if (!is_string($storedContents) || hash('sha256', $storedContents) !== $expectedHash) {
                throw new \RuntimeException('Stored configuration verification failed.', 1781869306);
            }
        } finally {
            if (is_file($temporaryFilePath)) {
                unlink($temporaryFilePath);
            }

            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
