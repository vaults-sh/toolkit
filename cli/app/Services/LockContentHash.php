<?php

declare(strict_types=1);

namespace App\Services;

class LockContentHash
{
    public function refresh(string $lockJson, string $composerJsonPath): string
    {
        if (! is_file($composerJsonPath)) {
            return $lockJson;
        }

        $hash = $this->contentHash((string) file_get_contents($composerJsonPath));

        return (string) preg_replace('/"content-hash":\s*"[a-f0-9]+"/', '"content-hash": "'.$hash.'"', $lockJson, 1);
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
