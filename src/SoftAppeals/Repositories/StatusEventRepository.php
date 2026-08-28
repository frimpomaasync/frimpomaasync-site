<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * The business timeline. Append-only, and the only history a client is ever
 * shown.
 *
 * Deliberately not the audit trail. sa_audit_events is internal security
 * history: it holds refusals, digests of addresses, permission names. This
 * holds the story of an engagement, written to be read by the practice, so
 * every label on it is a sentence a person would say out loud.
 *
 * The metadata allowlist is the same idea as the audit service's, for the same
 * reason: a caller must not be able to smuggle a patient name into a client-
 * visible record by naming a field something plausible.
 */
final class StatusEventRepository extends Repository
{
    public const ACTOR_STAFF  = 'staff';
    public const ACTOR_CLIENT = 'client';
    public const ACTOR_SYSTEM = 'system';

    /**
     * Every key that may be written. Business level throughout, and short
     * enough that adding one is a decision somebody makes on purpose.
     */
    private const ALLOWED_METADATA_KEYS = [
        'fee_basis',
        'assessment_window',
        'secure_channel',
        'cadence',
        'template_key',
        'source',
        'count',
        'reason',

        // Phase 4. A client reading their own timeline should see which
        // agreement moved and which version of it, because "an agreement was
        // executed" on an engagement that has been through a correction is a
        // line that answers nothing.
        'document_kind',
        'document_version',
    ];

    protected function table(): string
    {
        return 'sa_status_events';
    }

    /**
     * @param array<string,scalar|null> $metadata filtered against the allowlist
     */
    public function record(
        string $engagementId,
        string $eventType,
        string $publicLabel,
        ?string $fromStage = null,
        ?string $toStage = null,
        string $actorType = self::ACTOR_STAFF,
        ?string $actorId = null,
        array $metadata = []
    ): string {
        if (!in_array($actorType, [self::ACTOR_STAFF, self::ACTOR_CLIENT, self::ACTOR_SYSTEM], true)) {
            throw new \RuntimeException('Unknown actor type: ' . $actorType);
        }

        $id = Uuid::v4();
        $this->db->insert('sa_status_events', [
            'id'            => $id,
            'engagement_id' => $engagementId,
            'seq'           => $this->nextSequence($engagementId),
            'event_type'    => mb_substr($eventType, 0, 60),
            'public_label'  => mb_substr($publicLabel, 0, 160),
            'from_stage'    => $fromStage,
            'to_stage'      => $toStage,
            'actor_type'    => $actorType,
            'actor_id'      => $actorId,
            'metadata'      => $this->encodeMetadata($metadata),
            'created_at'    => $this->clock->nowUtc(),
        ]);
        return $id;
    }

    /**
     * Oldest first, because a timeline read top to bottom is a story and read
     * bottom to top is a log.
     *
     * @return list<array<string,mixed>>
     */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_status_events WHERE engagement_id = :e'
            . ' ORDER BY created_at ASC, seq ASC, id ASC',
            ['e' => $engagementId]
        );
    }

    /** @return list<array<string,mixed>> newest first, across every engagement */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all(
            'SELECT s.*, o.legal_name, o.display_name, e.public_ref AS engagement_ref'
            . ' FROM sa_status_events s'
            . ' JOIN sa_engagements e ON e.id = s.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' ORDER BY s.created_at DESC, s.seq DESC, s.id DESC LIMIT ' . $limit
        );
    }

    /**
     * The next position in this engagement's story.
     *
     * Timestamps are stored to the second and three events are written inside
     * one transaction when an engagement opens, so the clock cannot tell them
     * apart and `id` is a random UUID. Ordering on that put a practice's own
     * history in a random order, which was on screen on 2026-08-28.
     *
     * This is read inside the caller's transaction, so two events written by
     * one request cannot be handed the same number. Two separate requests
     * racing on the same engagement could, and the timestamp separates those:
     * the pair is what orders the row, never the sequence alone.
     */
    private function nextSequence(string $engagementId): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(MAX(seq), 0) + 1 FROM sa_status_events WHERE engagement_id = :e',
            ['e' => $engagementId]
        );
    }

    /** @param array<string,scalar|null> $metadata */
    private function encodeMetadata(array $metadata): ?string
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!in_array($key, self::ALLOWED_METADATA_KEYS, true) || $value === null) {
                continue;
            }
            if (is_bool($value)) {
                $clean[$key] = $value ? 'true' : 'false';
                continue;
            }
            $string = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value) ?? '';
            $clean[$key] = mb_substr(trim($string), 0, 200);
        }
        if ($clean === []) {
            return null;
        }
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 2000);
    }

    /** @return list<string> */
    public static function allowedMetadataKeys(): array
    {
        return self::ALLOWED_METADATA_KEYS;
    }
}
