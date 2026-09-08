<?php

declare(strict_types=1);

namespace Vaults\Auth;

use Vaults\Result\TeamIdentity;

final readonly class TokenStore
{
    public const EnvVariable = 'VAULTS_TOKEN';

    private const Anonymous = 'default';

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

        $current = $this->read()['current'] ?? null;

        return is_string($current) ? $this->tokenFor($current) : null;
    }

    public function tokenFor(string $teamUuid): ?string
    {
        $fromEnv = getenv(self::EnvVariable);

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $token = $this->read()['teams'][$teamUuid]['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function team(): ?TeamIdentity
    {
        $stored = $this->read();
        $current = $stored['current'] ?? null;

        if (! is_string($current)) {
            return null;
        }

        return $this->identity($current);
    }

    public function identity(string $teamUuid): ?TeamIdentity
    {
        $entry = $this->read()['teams'][$teamUuid] ?? null;

        return is_array($entry) && $teamUuid !== self::Anonymous ? new TeamIdentity($teamUuid, $entry['name'] ?? null) : null;
    }

    /**
     * @return list<TeamIdentity>
     */
    public function teams(): array
    {
        $teams = [];

        foreach ($this->read()['teams'] ?? [] as $uuid => $entry) {
            if ($uuid !== self::Anonymous) {
                $teams[] = new TeamIdentity($uuid, $entry['name'] ?? null);
            }
        }

        return $teams;
    }

    public function has(string $teamUuid): bool
    {
        return $this->tokenFor($teamUuid) !== null && ! $this->envOverrides();
    }

    public function save(string $token, ?TeamIdentity $team = null): void
    {
        $stored = $this->read();
        $uuid = $team === null || $team->uuid === null ? self::Anonymous : $team->uuid;
        $teams = is_array($stored['teams'] ?? null) ? $stored['teams'] : [];
        $teams[$uuid] = ['token' => $token, 'name' => $team?->name];

        $this->write(['current' => $uuid, 'teams' => $teams]);
    }

    public function use(string $teamUuid): bool
    {
        $stored = $this->read();

        if (! isset($stored['teams'][$teamUuid])) {
            return false;
        }

        $this->write(['current' => $teamUuid, 'teams' => $stored['teams']]);

        return true;
    }

    public function forget(string $teamUuid): void
    {
        $stored = $this->read();
        $teams = is_array($stored['teams'] ?? null) ? $stored['teams'] : [];
        unset($teams[$teamUuid]);

        if ($teams === []) {
            $this->clear();

            return;
        }

        $current = ($stored['current'] ?? null) === $teamUuid ? (string) array_key_first($teams) : $stored['current'];

        $this->write(['current' => $current, 'teams' => $teams]);
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

    private function envOverrides(): bool
    {
        $fromEnv = getenv(self::EnvVariable);

        return is_string($fromEnv) && $fromEnv !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
        chmod($this->path, 0600);
    }

    /**
     * @return array{current?: string|null, teams?: array<string, array{token?: string, name?: string|null}>}
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        $decoded = $contents === false ? null : json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (is_string($decoded['token'] ?? null)) {
            $team = is_array($decoded['team'] ?? null) ? $decoded['team'] : [];
            $uuid = is_string($team['uuid'] ?? null) ? $team['uuid'] : self::Anonymous;

            return ['current' => $uuid, 'teams' => [$uuid => ['token' => $decoded['token'], 'name' => $team['name'] ?? null]]];
        }

        return $decoded;
    }
}
