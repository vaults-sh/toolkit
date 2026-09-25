<?php

declare(strict_types=1);

namespace Vaults\Composer;

final class LockContentHash
{
    public function refresh(string $lockJson, string $composerJsonPath): string
    {
        if (! is_file($composerJsonPath)) {
            return $lockJson;
        }

        $hash = $this->contentHash((string) file_get_contents($composerJsonPath));

        return (string) preg_replace('/"content-hash":\s*"[a-f0-9]+"/', '"content-hash": "'.$hash.'"', $lockJson, 1);
    }

    /**
     * Rewrites composer.lock's content-hash after composer.json changed, so composer install
     * never warns about a stale lock because of an edit we made.
     */
    public function refreshFile(string $directory): bool
    {
        $lockPath = $directory.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($lockPath)) {
            return false;
        }

        $current = (string) file_get_contents($lockPath);
        $refreshed = $this->refresh($current, $directory.DIRECTORY_SEPARATOR.'composer.json');

        if ($refreshed === $current) {
            return false;
        }

        return file_put_contents($lockPath, $refreshed) !== false;
    }

    public function contentHash(string $composerJsonContents): string
    {
        $content = json_decode($composerJsonContents, true);
        $content = is_array($content) ? $content : [];

        $relevantKeys = [
            'name',
            'version',
            'require',
            'require-dev',
            'conflict',
            'replace',
            'provide',
            'minimum-stability',
            'prefer-stable',
            'repositories',
            'extra',
        ];

        $relevantContent = [];

        foreach (array_intersect($relevantKeys, array_keys($content)) as $key) {
            $relevantContent[$key] = $content[$key];
        }

        if (isset($content['config']['platform'])) {
            $relevantContent['config']['platform'] = $content['config']['platform'];
        }

        ksort($relevantContent);

        return hash('md5', (string) json_encode($relevantContent));
    }
}
