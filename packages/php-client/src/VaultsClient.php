<?php

declare(strict_types=1);

namespace Vaults;

use Vaults\Exception\ApiException;
use Vaults\Exception\AuthenticationException;
use Vaults\Result\CheckResult;
use Vaults\Result\DepositRun;
use Vaults\Result\DeviceCodePair;
use Vaults\Result\PollResult;
use Vaults\Result\Project;
use Vaults\Result\RewrittenLock;
use Vaults\Result\TeamIdentity;
use Vaults\Transport\HttpRequest;
use Vaults\Transport\HttpResponse;
use Vaults\Transport\StreamTransport;
use Vaults\Transport\Transport;

final class VaultsClient
{
    public const DefaultBaseUrl = 'https://api.vaults.sh';

    public const DefaultAuthBaseUrl = 'https://auth.vaults.sh';

    private readonly string $baseUrl;

    private readonly string $authBaseUrl;

    private readonly Transport $transport;

    public function __construct(
        private ?string $token = null,
        ?string $baseUrl = null,
        ?Transport $transport = null,
        ?string $authBaseUrl = null,
    ) {
        $envBaseUrl = getenv('VAULTS_API_URL');
        $envAuthBaseUrl = getenv('VAULTS_AUTH_URL');

        $this->baseUrl = rtrim($baseUrl ?? (is_string($envBaseUrl) && $envBaseUrl !== '' ? $envBaseUrl : self::DefaultBaseUrl), '/');
        $this->authBaseUrl = rtrim($authBaseUrl ?? (is_string($envAuthBaseUrl) && $envAuthBaseUrl !== '' ? $envAuthBaseUrl : self::DefaultAuthBaseUrl), '/');
        $this->transport = $transport ?? new StreamTransport;
    }

    public function withToken(string $token): self
    {
        return new self($token, $this->baseUrl, $this->transport, $this->authBaseUrl);
    }

    public function ping(): bool
    {
        $data = $this->request('GET', '/api/v1/ping');

        return ($data['status'] ?? null) === 'ok';
    }

    public function whoami(): TeamIdentity
    {
        $data = $this->request('GET', '/api/v1/token');

        return TeamIdentity::fromArray(is_array($data['team'] ?? null) ? $data['team'] : []);
    }

    public function createDeviceCode(?string $name = null): DeviceCodePair
    {
        $response = $this->send('POST', '/api/v1/device-codes', $name === null ? [] : ['name' => $name], $this->authBaseUrl);

        return DeviceCodePair::fromArray($this->decode($response));
    }

    public function pollDeviceCode(string $deviceCode): PollResult
    {
        $response = $this->send('POST', '/api/v1/device-codes/poll', ['device_code' => $deviceCode], $this->authBaseUrl);

        if ($response->status === 410) {
            return PollResult::expired();
        }

        if ($response->status === 404) {
            return PollResult::denied();
        }

        return PollResult::fromArray($this->decode($response));
    }

    /**
     * @return list<Project>
     */
    public function listProjects(): array
    {
        $response = $this->send('GET', '/api/v1/projects');
        $data = $this->decodeEnvelope($response);

        return array_values(array_map(
            fn (array $project): Project => Project::fromArray($project),
            array_filter(is_array($data) ? $data : [], 'is_array'),
        ));
    }

    public function createProject(string $name, ?string $description = null, ?string $repositoryUrl = null): Project
    {
        $payload = array_filter([
            'name' => $name,
            'description' => $description,
            'repository_url' => $repositoryUrl,
        ], fn (?string $value): bool => $value !== null);

        return Project::fromArray($this->request('POST', '/api/v1/projects', $payload));
    }

    public function deposit(string $projectUuid, string $composerLock): DepositRun
    {
        return DepositRun::fromArray(
            $this->request('POST', '/api/v1/projects/'.$projectUuid.'/deposit', ['composer_lock' => $composerLock]),
        );
    }

    public function depositCheck(string $projectUuid, string $composerLock): CheckResult
    {
        return CheckResult::fromArray(
            $this->request('POST', '/api/v1/projects/'.$projectUuid.'/deposit/check', ['composer_lock' => $composerLock]),
        );
    }

    public function getRun(string $runUuid): DepositRun
    {
        return DepositRun::fromArray($this->request('GET', '/api/v1/deposit-runs/'.$runUuid));
    }

    public function getRewrittenLock(string $runUuid): RewrittenLock
    {
        $response = $this->send('GET', '/api/v1/deposit-runs/'.$runUuid.'/lock');
        $this->guard($response);

        return RewrittenLock::fromArray($response->json());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $response = $this->send($method, $path, $payload === [] ? null : $payload);

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $method, string $path, ?array $payload = null, ?string $base = null): HttpResponse
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'vaults-php-client',
        ];

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        $body = null;

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        return $this->transport->send(new HttpRequest($method, ($base ?? $this->baseUrl).$path, $headers, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $this->guard($response);

        $data = $response->json()['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function decodeEnvelope(HttpResponse $response): mixed
    {
        $this->guard($response);

        return $response->json()['data'] ?? null;
    }

    private function guard(HttpResponse $response): void
    {
        if ($response->status < 400) {
            return;
        }

        $body = $response->json();
        $message = is_string($body['message'] ?? null) && $body['message'] !== ''
            ? $body['message']
            : 'The Vaults API responded with status '.$response->status.'.';

        if ($response->status === 401) {
            throw new AuthenticationException($message, $response->status, $body);
        }

        throw new ApiException($message, $response->status, $body);
    }
}
