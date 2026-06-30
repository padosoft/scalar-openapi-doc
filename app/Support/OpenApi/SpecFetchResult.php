<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use LogicException;

/**
 * Outcome of a non-throwing spec load: either a spec, or a categorized failure.
 * Exactly one of the two is set; the accessors enforce that invariant so callers
 * (and the type checker) get a non-null value after the matching ok() check.
 */
final readonly class SpecFetchResult
{
    /**
     * @param  array<string, mixed>|null  $spec
     */
    private function __construct(
        private ?array $spec,
        private ?SpecFailure $failureValue,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     */
    public static function success(array $spec): self
    {
        return new self($spec, null);
    }

    public static function failure(SpecFailure $failure): self
    {
        return new self(null, $failure);
    }

    public function ok(): bool
    {
        return $this->failureValue === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function specOrFail(): array
    {
        return $this->spec ?? throw new LogicException('SpecFetchResult::specOrFail() called on a failed result.');
    }

    public function failureOrFail(): SpecFailure
    {
        return $this->failureValue ?? throw new LogicException('SpecFetchResult::failureOrFail() called on a successful result.');
    }
}
