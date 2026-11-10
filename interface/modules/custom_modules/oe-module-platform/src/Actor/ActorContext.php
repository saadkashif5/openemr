<?php

/**
 * The actor behind a request. For a person, userId is themselves. For an agent, userId is the person it
 * acts for (its token was issued for that user) and clientId names the agent; it can never do more than
 * that person may. Built once per request and passed to anything that decides or records.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Platform\Actor;

final readonly class ActorContext
{
    private function __construct(
        public ActorType $type,
        public int $userId,
        public string $username,
        public ?string $clientId,
        public string $correlationId,
    ) {
    }

    public static function human(int $userId, string $username, string $correlationId): self
    {
        return new self(ActorType::Human, $userId, $username, null, $correlationId);
    }

    public static function agent(string $clientId, int $onBehalfOfUserId, string $onBehalfOfUsername, string $correlationId): self
    {
        if ($clientId === '') {
            throw new \InvalidArgumentException('An agent needs a client id');
        }
        return new self(ActorType::Agent, $onBehalfOfUserId, $onBehalfOfUsername, $clientId, $correlationId);
    }

    public function isHuman(): bool
    {
        return $this->type === ActorType::Human;
    }

    /** Short text for log lines: "human:admin_a" or "agent:<client> for:admin_a". */
    public function label(): string
    {
        return $this->clientId === null
            ? 'human:' . $this->username
            : 'agent:' . $this->clientId . ' for:' . $this->username;
    }
}
