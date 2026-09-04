<?php

declare(strict_types=1);

namespace Vaults\Exception;

class ApiException extends VaultsException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    public function isValidationError(): bool
    {
        return $this->status === 422;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $errors = $this->body['errors'] ?? [];

        if (! is_array($errors)) {
            return [];
        }

        $normalised = [];

        foreach ($errors as $field => $messages) {
            $normalised[(string) $field] = array_values(array_filter(
                is_array($messages) ? $messages : [$messages],
                'is_string',
            ));
        }

        return $normalised;
    }

    public function firstError(string $field): ?string
    {
        return $this->errors()[$field][0] ?? null;
    }
}
