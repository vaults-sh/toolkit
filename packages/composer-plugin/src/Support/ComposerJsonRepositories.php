<?php

declare(strict_types=1);

namespace Vaults\ComposerPlugin\Support;

use Composer\Json\JsonManipulator;

final class ComposerJsonRepositories
{
    /**
     * @param  array<string, mixed>  $repository
     */
    public static function has(string $directory, array $repository): bool
    {
        $wantedUrl = rtrim((string) ($repository['url'] ?? ''), '/');

        if ($wantedUrl === '') {
            return false;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($path)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $repositories = is_array($decoded) && is_array($decoded['repositories'] ?? null) ? $decoded['repositories'] : [];

        foreach ($repositories as $entry) {
            if (is_array($entry) && rtrim((string) ($entry['url'] ?? ''), '/') === $wantedUrl) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    public static function add(string $directory, string $name, array $repository): bool
    {
        $path = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (! is_file($path)) {
            return false;
        }

        $manipulator = new JsonManipulator((string) file_get_contents($path));

        if (! $manipulator->addRepository($name, $repository, true)) {
            return false;
        }

        file_put_contents($path, $manipulator->getContents());

        return true;
    }
}
