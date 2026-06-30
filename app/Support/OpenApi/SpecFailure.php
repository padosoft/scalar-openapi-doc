<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

/**
 * A categorized, already-redacted description of why the OpenAPI spec could not
 * be loaded. Safe to surface in the UI: it never carries the upstream URL, DB
 * host, or auth token (the message is passed through OpenApiSpecService's
 * redaction before construction).
 */
final readonly class SpecFailure
{
    public function __construct(
        public SpecFailureCategory $category,
        public string $exceptionClass,
        public string $message,
        public ?int $httpStatus = null,
    ) {}

    public function label(): string
    {
        return $this->category->label();
    }

    /**
     * Shape handed to the front-end (Inertia prop) and JSON responses.
     *
     * @return array{category: string, label: string, httpStatus: int|null, exceptionClass: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'label' => $this->label(),
            'httpStatus' => $this->httpStatus,
            'exceptionClass' => $this->exceptionClass,
            'message' => $this->message,
        ];
    }
}
