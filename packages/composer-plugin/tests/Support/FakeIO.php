<?php

declare(strict_types=1);

namespace Tests\Support;

use Composer\IO\NullIO;

final class FakeIO extends NullIO
{
    /** @var list<mixed> */
    private array $answers = [];

    /** @var list<string> */
    public array $questions = [];

    public function queue(mixed $answer): self
    {
        $this->answers[] = $answer;

        return $this;
    }

    public function isInteractive(): bool
    {
        return true;
    }

    public function ask($question, $default = null)
    {
        $this->questions[] = $question;

        return array_shift($this->answers) ?? $default;
    }

    public function askConfirmation($question, $default = true): bool
    {
        $this->questions[] = $question;

        $answer = array_shift($this->answers);

        return $answer === null ? $default : (bool) $answer;
    }

    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid', $multiselect = false)
    {
        $this->questions[] = $question;

        $answer = array_shift($this->answers);

        return $answer === null ? $default : $answer;
    }
}
