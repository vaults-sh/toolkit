<?php

declare(strict_types=1);

namespace Vaults\Project;

final readonly class ProjectManifest
{
    public const Filename = '.vaults.json';

    public function load(string $directory): ?string
    {
        $decoded = $this->read($directory);

        return is_string($decoded['project'] ?? null) ? $decoded['project'] : null;
    }

    public function team(string $directory): ?string
    {
        $decoded = $this->read($directory);

        return is_string($decoded['team'] ?? null) ? $decoded['team'] : null;
    }

    public function write(string $directory, string $projectUuid, ?string $teamUuid = null): void
    {
        $payload = ['project' => $projectUuid];

        if ($teamUuid !== null) {
            $payload['team'] = $teamUuid;
        }

        file_put_contents(
            $directory.DIRECTORY_SEPARATOR.self::Filename,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $directory): array
    {
        $path = $directory.DIRECTORY_SEPARATOR.self::Filename;

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        $decoded = $contents === false ? null : json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
