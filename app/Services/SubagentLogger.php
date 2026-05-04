<?php

namespace App\Services;

class SubagentLogger
{
    /**
     * Append a JSON line to storage/logs/agent-debug.log. Always succeeds (or
     * silently swallows so logging never breaks an agent call).
     *
     * @param array<string, mixed> $entry
     */
    public static function write(array $entry): void
    {
        try {
            $entry = ['ts' => now()->toIso8601String()] + $entry;
            file_put_contents(
                storage_path('logs/agent-debug.log'),
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable) {
            // never let logging break the call
        }
    }

    /**
     * Generate a short correlation id for a single sub-agent call so the
     * 'start', 'attempt', and 'end' entries can be paired in queries.
     */
    public static function newCallId(): string
    {
        return bin2hex(random_bytes(4));
    }
}
