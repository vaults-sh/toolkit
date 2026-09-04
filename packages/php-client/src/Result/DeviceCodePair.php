<?php

declare(strict_types=1);

namespace Vaults\Result;

final readonly class DeviceCodePair
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public string $verificationUriComplete,
        public int $expiresIn,
        public int $interval,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['device_code'] ?? ''),
            (string) ($data['user_code'] ?? ''),
            (string) ($data['verification_uri'] ?? ''),
            (string) ($data['verification_uri_complete'] ?? ''),
            (int) ($data['expires_in'] ?? 900),
            (int) ($data['interval'] ?? 5),
        );
    }
}
