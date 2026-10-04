<?php

declare(strict_types=1);

namespace App\Clock;

use Psr\Clock\ClockInterface;

/**
 * A file-backed clock, so the demo can move time forward instead of waiting for it.
 *
 * The state is a file rather than a property on purpose: the kernel is rebooted
 * between requests, so anything kept in memory would reset and the sudo windows
 * could never expire during a test run.
 */
final class TestClock implements ClockInterface
{
    public function __construct(private readonly string $stateFile)
    {
    }

    public function now(): \DateTimeImmutable
    {
        $timestamp = is_file($this->stateFile) ? trim((string) file_get_contents($this->stateFile)) : '';

        if ('' === $timestamp) {
            $this->reset();

            return new \DateTimeImmutable('@'.time());
        }

        return new \DateTimeImmutable('@'.$timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->write($this->now()->getTimestamp() + $seconds);
    }

    public function reset(): void
    {
        $this->write(time());
    }

    private function write(int $timestamp): void
    {
        $directory = \dirname($this->stateFile);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
        }

        file_put_contents($this->stateFile, (string) $timestamp);
    }
}
