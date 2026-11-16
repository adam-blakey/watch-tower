<?php

namespace App\Watch;

class Fingerprinter
{
    /**
     * Build a deterministic fingerprint for an exception payload so that
     * occurrences sharing class + location collapse into a single ErrorGroup.
     *
     * @param  array<string, mixed>  $exception
     */
    public function forException(array $exception): string
    {
        $class = $exception['class'] ?? $exception['exception_class'] ?? 'UnknownException';
        $file = $exception['file'] ?? $this->firstFrameValue($exception, 'file') ?? '';
        $line = $exception['line'] ?? $this->firstFrameValue($exception, 'line') ?? '';

        return hash('sha256', implode('|', array_map(fn (mixed $part): string => is_scalar($part) ? (string) $part : '', [$class, $file, $line])));
    }

    /**
     * @param  array<string, mixed>  $exception
     */
    private function firstFrameValue(array $exception, string $key): ?string
    {
        $stack = $exception['stacktrace'] ?? $exception['trace'] ?? [];

        if (! is_array($stack) || $stack === []) {
            return null;
        }

        $first = $stack[0] ?? null;

        return is_array($first) && is_scalar($first[$key] ?? null) ? (string) $first[$key] : null;
    }
}
