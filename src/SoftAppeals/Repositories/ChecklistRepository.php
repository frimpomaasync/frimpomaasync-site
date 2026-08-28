<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\Checklist;
use SoftAppeals\Support\Uuid;

/**
 * Checklist items, section 15.6. One row per item per engagement.
 */
final class ChecklistRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_checklist_items';
    }

    /** @return list<array<string,mixed>> in display order */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_checklist_items WHERE engagement_id = :e ORDER BY display_order ASC',
            ['e' => $engagementId]
        );
    }

    /** @return array<string,mixed>|null */
    public function item(string $engagementId, string $key): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_checklist_items WHERE engagement_id = :e AND item_key = :k',
            ['e' => $engagementId, 'k' => $key]
        );
    }

    /**
     * Plant a list of items, skipping any already there. Returns how many
     * were added, so a caller can tell a first seed from a repeat.
     *
     * @param list<array{key:string,label:string,category:string}> $items
     */
    public function seed(string $engagementId, array $items, int $orderFrom = 1): int
    {
        $added = 0;
        $order = $orderFrom;
        foreach ($items as $item) {
            if (!Checklist::isValidKey($item['key']) || !Checklist::isValidCategory($item['category'])) {
                throw new \RuntimeException('Unknown checklist item: ' . $item['key']);
            }
            if ($this->item($engagementId, $item['key']) === null) {
                $this->db->insert('sa_checklist_items', [
                    'id'            => Uuid::v4(),
                    'engagement_id' => $engagementId,
                    'item_key'      => $item['key'],
                    'label'         => $item['label'],
                    'category'      => $item['category'],
                    'display_order' => $order,
                    'created_at'    => $this->clock->nowUtc(),
                ]);
                $added++;
            }
            $order++;
        }
        return $added;
    }

    /**
     * Mark one item done. Idempotent: an item already complete keeps its
     * original stamp, because the first time it happened is the true time.
     *
     * @return bool true when this call completed it, false when it already was
     */
    public function complete(
        string $engagementId,
        string $key,
        ?string $whenUtc = null,
        ?string $userId = null,
        ?string $sourceEventId = null
    ): bool {
        $item = $this->item($engagementId, $key);
        if ($item === null || $item['completed_at'] !== null) {
            return false;
        }
        $this->db->update('sa_checklist_items', [
            'completed_at'    => $whenUtc ?? $this->clock->nowUtc(),
            'completed_by'    => $userId,
            'source_event_id' => $sourceEventId,
        ], ['id' => (string) $item['id']]);
        return true;
    }

    public function hasKey(string $engagementId, string $key): bool
    {
        return $this->item($engagementId, $key) !== null;
    }
}
