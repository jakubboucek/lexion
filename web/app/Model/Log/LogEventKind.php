<?php declare(strict_types=1);

namespace App\Model\Log;


/**
 * Typed identity of a logged event: the backed enum value is the `action`,
 * resource() names the domain. Every logged domain owns one such enum next
 * to its other enums (e.g. Sync\SyncLogKind) so resource/action never become
 * free-floating magic strings; LogService::logRaw() stays as the escape
 * hatch. Pattern taken from the skradbuza LogModel.
 */
interface LogEventKind
{
    public string $value { get; }

    public function resource(): string;
}
