<?php

declare(strict_types=1);

namespace Vaults\Auth;

use Vaults\Result\TeamIdentity;

final readonly class TokenStore
{
    public const EnvVariable = 'VAULTS_TOKEN';

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? self::defaultPath();
    }

    public function token(): ?string
    {
        $fromEnv = getenv(self::EnvVariable);

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $stored = $this->read();

        return is_string($stored['token'] ?? null) ? $stored['token'] : null;
    }

    public function team(): ?TeamIdentity
    {
        $stored = $this->read();

        return is_array($stored['team'] ?? null) ? TeamIdentity::fromArray($stored['team']) : null;
    }

    public function save(string $token, ?TeamIdentity $team = null): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $payload = ['token' => $token];

        if ($team !== null) {
            $payload['team'] = ['uuid' => $team->uuid, 'name' => $team->name];
        }

        file_put_contents($this->path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
        chmod($this->path, 0600);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public static function defaultPath(): string
    {
        $appData = getenv('APPDATA');

        if (PHP_OS_FAMILY === 'Windows' && is_string($appData) && $appData !== '') {
            return $appData.DIRECTORY_SEPARATOR.'Vaults'.DIRECTORY_SEPARATOR.'config.json';
        }

        $configHome = getenv('XDG_CONFIG_HOME');

        if (! is_string($configHome) || $configHome === '') {
            $configHome = (getenv('HOME') ?: '~').'/.config';
        }

        return $configHome.'/vaults/config.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
