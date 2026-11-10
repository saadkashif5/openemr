<?php

/**
 * A per-request identifier that ties together everything one request causes. A caller may supply one in the
 * X-Correlation-Id header (so a chain of calls shares it); anything that is not a UUID is replaced.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Actor;

final class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /** The caller's id if it is a valid UUID, otherwise a new one. */
    public static function fromHeaderOrNew(?string $header): string
    {
        $candidate = $header === null ? '' : strtolower(trim($header));
        return self::isValid($candidate) ? $candidate : self::generate();
    }
}
