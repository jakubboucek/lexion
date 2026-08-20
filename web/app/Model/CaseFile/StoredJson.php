<?php declare(strict_types=1);

namespace App\Model\CaseFile;

use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Reader of the raw JSON columns of a case file (proceeding.infosoud_json,
 * proceeding_event.detail_json). We encoded those payloads ourselves, so
 * damaged content means corruption on our side - and it must never pass
 * silently: the projection used to `return` on a non-array payload, leaving
 * the derived tables stale with nobody the wiser (tech-debt MISC-2).
 *
 * Both failure modes - an unparseable string and a payload that is not an
 * object - raise the same exception carrying the caller's context, mirroring
 * how InfosoudClient wraps a malformed upstream body.
 */
final class StoredJson
{
    /**
     * @param string $context what is being read, e.g. "case file #12 (infosoud_json)"
     * @return array<mixed> empty for a NULL column (nothing stored yet)
     * @throws StoredJsonException
     */
    public static function decode(?string $json, string $context): array
    {
        if ($json === null) {
            return [];
        }
        try {
            $decoded = Json::decode($json, forceArrays: true);
        } catch (JsonException $e) {
            throw new StoredJsonException("Stored JSON of $context is not valid JSON.", previous: $e);
        }
        if (!is_array($decoded)) {
            throw new StoredJsonException("Stored JSON of $context is not an object.");
        }
        return $decoded;
    }
}
