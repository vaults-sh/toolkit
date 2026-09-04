<?php

declare(strict_types=1);

namespace Vaults\Project;

final readonly class ProjectManifest
{
    public const Filename = '.vaults.json';

    public function load(string $directory): ?string
    {
        $path = $directory.DIRECTORY_SEPARATOR.self::Filename;

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) && is_string($decoded['project'] ?? null) ? $decoded['project'] : null;
    }

    public function write(string $directory, string $projectUuid): void
    {
        file_put_contents(
            $directory.DIRECTORY_SEPARATOR.self::Filename,
            json_encode(['project' => $projectUuid], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL,
        );
    }
}
