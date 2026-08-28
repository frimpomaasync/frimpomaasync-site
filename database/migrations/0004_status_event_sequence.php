<?php
declare(strict_types=1);

/**
 * Migration 0004 · A sequence on the client timeline.
 *
 * Seen on screen 2026-08-28, in a real Recovery Room. The practice's own
 * history read:
 *
 *     Your assessment terms are being prepared.
 *     Your enquiry was reviewed for fit.
 *     Your enquiry was received and opened for review.
 *
 * which is backwards. Opening an engagement writes those three events inside
 * one transaction, timestamps are stored to the second, and the tie was broken
 * by `id`, which is a random UUIDv4. Three events in the same second therefore
 * sorted at random, and the only history a client is ever shown was scrambled
 * whenever the machine was quick, which is always.
 *
 * A sequence number fixes it at the layer the ordering actually happens. It is
 * per engagement and it is assigned when the row is written, so it says what
 * order things were recorded in rather than what order the clock could see.
 *
 * No index. The ordering is (created_at, seq) inside one engagement, the
 * existing sa_status_eng_idx already covers engagement_id and created_at, and
 * an engagement carries a handful of events. An index here would be ceremony.
 *
 * The backfill repairs what is already stored. Within a second it uses the
 * state machine's own order for the three events that are always written
 * together, which is the known cause, and falls back to created_at and id for
 * everything else. It cannot invent an order it was never told, and it does not
 * pretend to: two unrelated events genuinely written in the same second keep
 * whatever order they already had, frozen rather than random from then on.
 */

return [
    'name' => '0004_status_event_sequence',

    'up' => static function (\SoftAppeals\Database $db): void {
        $db->run('ALTER TABLE sa_status_events ADD COLUMN seq INTEGER NOT NULL DEFAULT 0');

        // The order the three opening events are actually written in, from
        // EngagementService::openFromIntake. Anything not listed sorts after
        // them, on the timestamp it carries.
        $rank = [
            'engagement.opened'      => 1,
            'engagement.fit_review'  => 2,
            'engagement.terms_ready' => 3,
        ];

        $rows = $db->all(
            'SELECT id, engagement_id, event_type, created_at FROM sa_status_events'
        );

        // Group by engagement, then sort each group the way the timeline should
        // have read all along.
        $byEngagement = [];
        foreach ($rows as $row) {
            $byEngagement[(string) $row['engagement_id']][] = $row;
        }

        foreach ($byEngagement as $events) {
            usort($events, static function (array $a, array $b) use ($rank): int {
                $byTime = strcmp((string) $a['created_at'], (string) $b['created_at']);
                if ($byTime !== 0) {
                    return $byTime;
                }
                $rankA = $rank[(string) $a['event_type']] ?? 50;
                $rankB = $rank[(string) $b['event_type']] ?? 50;
                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }
                return strcmp((string) $a['id'], (string) $b['id']);
            });

            $seq = 0;
            foreach ($events as $event) {
                $seq++;
                $db->update('sa_status_events', ['seq' => $seq], ['id' => (string) $event['id']]);
            }
        }
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        $db->run('ALTER TABLE sa_status_events DROP COLUMN seq');
    },
];
