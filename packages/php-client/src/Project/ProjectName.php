<?php

declare(strict_types=1);

namespace Vaults\Project;

final class ProjectName
{
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 64;

    public const string RULE = 'Use lowercase letters, digits and single hyphens, like checkout-api.';

    public static function normalise(string $value): string
    {
        $name = strtolower(trim($value));
        $name = (string) preg_replace('/[\s_]+/', '-', $name);
        $name = (string) preg_replace('/[^a-z0-9-]/', '', $name);
        $name = (string) preg_replace('/-{2,}/', '-', $name);
        $name = trim($name, '-');

        return substr($name, 0, self::MAX_LENGTH);
    }

    public static function isValid(string $value): bool
    {
        $length = strlen($value);

        return preg_match(self::PATTERN, $value) === 1
            && $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH;
    }

    public static function suggest(string $directory): string
    {
        $composerJson = $directory.DIRECTORY_SEPARATOR.'composer.json';

        if (is_file($composerJson)) {
            $decoded = json_decode((string) file_get_contents($composerJson), true);
            $name = is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : '';

            if (str_contains($name, '/')) {
                $suggested = self::normalise(explode('/', $name, 2)[1]);

                if (self::isValid($suggested)) {
                    return $suggested;
                }
            }
        }

        $fallback = self::normalise(basename($directory));

        return self::isValid($fallback) ? $fallback : 'my-project';
    }
}
