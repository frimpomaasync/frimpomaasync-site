<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Repositories\IntakeRepository;

/**
 * Her old leads, brought into the Desk.
 *
 * Since the day sa-lead.php went up, every Soft Appeals submission has been
 * written to two places on the server: one skimmable line in
 * fs-metrics/sa-leads.log, and one full copy in fs-metrics/sa-leads/*.txt. Both
 * are still the record of every enquiry she has ever had, and until now the
 * only way to read them was to open them.
 *
 * Section 21.2, and this class follows it step for step:
 *
 *   read only. Nothing here writes to, moves, renames or deletes a single file
 *   under fs-metrics. The originals stay exactly as they are, before the
 *   import, during it, and after it.
 *
 *   dry run first. inspect() returns the counts, the duplicates and the records
 *   it cannot read, and it writes nothing. She approves what she sees, and only
 *   then does import() run.
 *
 *   a hash on every record. The archive file is hashed as bytes; a log-only
 *   lead is hashed from its line. That hash is the intake's idempotency key and
 *   it carries a unique constraint, so running the import twice imports
 *   nothing twice.
 *
 *   reconciliation. The report counts the source and counts what landed, and
 *   says both numbers rather than reporting success.
 *
 * Two kinds of lead come out of this. Most have an archive file and arrive
 * complete. A few have only a log line, because the archive is pruned to the
 * most recent 400 files and the oldest ones are long gone. Those are imported
 * as their own source, `legacy-log`, carrying only the five things the line
 * actually holds. The Desk labels them for what they are rather than implying
 * a fuller record exists somewhere.
 */
final class LegacyLeadImporter
{
    private IntakeRepository $intakes;
    private AuditService $audit;
    private string $metricsPath;

    public function __construct(IntakeRepository $intakes, AuditService $audit, ?string $metricsPath = null)
    {
        $this->intakes = $intakes;
        $this->audit = $audit;
        $this->metricsPath = rtrim($metricsPath ?? dirname(__DIR__, 3) . '/fs-metrics', '/');
    }

    public function metricsPath(): string
    {
        return $this->metricsPath;
    }

    public function archivePath(): string
    {
        return $this->metricsPath . '/sa-leads';
    }

    public function logPath(): string
    {
        return $this->metricsPath . '/sa-leads.log';
    }

    /**
     * Look, and write nothing.
     *
     * @return array{
     *   archive_files:int, archive_read:int, archive_unreadable:list<string>,
     *   log_lines:int, log_unmatched:int, log_malformed:int,
     *   candidates:list<array<string,mixed>>, new:int, duplicates:int, invalid:int,
     *   source_total:int, already_imported:int, available:bool, notes:list<string>
     * }
     */
    public function inspect(): array
    {
        $notes = [];
        $candidates = [];
        $unreadable = [];

        $archiveFiles = $this->archiveFiles();
        $archiveRead = 0;

        /** @var array<string,true> $seenStamps stamp => matched, for the log pass */
        $seenStamps = [];

        foreach ($archiveFiles as $path) {
            $record = $this->readArchiveFile($path);
            if ($record === null) {
                $unreadable[] = basename($path);
                continue;
            }
            $archiveRead++;
            $seenStamps[$record['stamp']] = true;
            $candidates[] = $record;
        }

        $logLines = 0;
        $logUnmatched = 0;
        $logMalformed = 0;

        foreach ($this->logRecords() as $line) {
            $logLines++;
            if ($line === null) {
                $logMalformed++;
                continue;
            }
            // An archive file already carries this submission in full. The log
            // line adds nothing, so it is skipped rather than imported twice
            // under two different hashes.
            if (isset($seenStamps[$line['stamp']])) {
                continue;
            }
            $logUnmatched++;
            $candidates[] = $line;
        }

        $new = 0;
        $duplicates = 0;
        $invalid = 0;
        foreach ($candidates as $index => $candidate) {
            if (!$this->isUsable($candidate)) {
                $candidates[$index]['verdict'] = 'invalid';
                $invalid++;
                continue;
            }
            if ($this->intakes->findByPayloadHash((string) $candidate['hash']) !== null) {
                $candidates[$index]['verdict'] = 'already imported';
                $duplicates++;
                continue;
            }
            $candidates[$index]['verdict'] = 'new';
            $new++;
        }

        if ($archiveFiles === [] && $logLines === 0) {
            $notes[] = 'Nothing found at ' . $this->metricsPath
                . '. On this machine that is expected: the leads live on the server, '
                . 'and this page has to run there to see them.';
        }
        if ($unreadable !== []) {
            $notes[] = count($unreadable) . ' archive file(s) could not be read as a lead. '
                . 'They are listed below and nothing was imported from them.';
        }
        if ($logMalformed > 0) {
            $notes[] = $logMalformed . ' line(s) in sa-leads.log are not in the five-column '
                . 'shape the log has always used, and were left alone.';
        }

        return [
            'archive_files'      => count($archiveFiles),
            'archive_read'       => $archiveRead,
            'archive_unreadable' => $unreadable,
            'log_lines'          => $logLines,
            'log_unmatched'      => $logUnmatched,
            'log_malformed'      => $logMalformed,
            'candidates'         => $candidates,
            'new'                => $new,
            'duplicates'         => $duplicates,
            'invalid'            => $invalid,
            'source_total'       => count($candidates),
            'already_imported'   => $this->intakes->importedCount(),
            'available'          => is_dir($this->metricsPath),
            'notes'              => $notes,
        ];
    }

    /**
     * Do it. Returns the same report, plus what actually landed.
     *
     * @return array<string,mixed>
     */
    public function import(): array
    {
        $report = $this->inspect();

        $created = 0;
        $skipped = 0;
        $failed = [];

        foreach ($report['candidates'] as $candidate) {
            if (($candidate['verdict'] ?? '') !== 'new') {
                $skipped++;
                continue;
            }
            try {
                $result = $this->intakes->record(
                    (string) $candidate['source'],
                    $candidate['answers'],
                    (string) $candidate['hash'],
                    (string) $candidate['submitted_at'],
                    (string) $candidate['path']
                );
                if ($result['created']) {
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable) {
                // Named, not swallowed. One bad record must not stop the rest,
                // and she has to be able to see which one it was.
                $failed[] = (string) $candidate['path'];
            }
        }

        $report['created'] = $created;
        $report['skipped'] = $skipped;
        $report['failed'] = $failed;
        $report['imported_total'] = $this->intakes->importedCount();
        // The reconciliation section 21.2 step 6 asks for, stated as two
        // numbers rather than as a claim that it worked.
        $report['reconciled'] = $report['imported_total'] === ($report['source_total'] - $report['invalid']);

        $this->audit->record('intake.import', $failed === [] ? 'success' : 'failure', 'intake', null, [
            'count'  => $created,
            'source' => 'fs-metrics',
            'reason' => $failed === []
                ? 'imported ' . $created . ' of ' . $report['source_total'] . ' source records'
                : count($failed) . ' record(s) could not be stored',
        ]);

        return $report;
    }

    // ------------------------------------------------------------------
    // Reading the originals. Everything below this line opens files and
    // nothing below this line writes one.
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function archiveFiles(): array
    {
        $found = glob($this->archivePath() . '/*.txt');
        if (!is_array($found)) {
            return [];
        }
        sort($found, SORT_STRING);
        return array_values($found);
    }

    /**
     * One archived lead, read back into named answers.
     *
     * The file's shape has not changed since sa-lead.php was written:
     *
     *     Form:  Denial review request
     *     When:  2026-08-15 14:32 UTC
     *
     *     Organization: Tidewater Behavioral Health
     *     Your name: Rosalind Achebe
     *     ...
     *
     * The labels are the ones the person saw on screen, so they are matched
     * back to field keys through IntakeForms rather than guessed at. A line
     * that does not start a known label is a continuation of the answer above
     * it, which is what keeps a pasted paragraph in one piece.
     *
     * @return array<string,mixed>|null
     */
    private function readArchiveFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        $ownerLabel = null;
        $whenUtc = null;
        $bodyStart = 0;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'Form:')) {
                $ownerLabel = trim(substr($line, 5));
            } elseif (str_starts_with($line, 'When:')) {
                $whenUtc = trim(substr($line, 5));
            } elseif (trim($line) === '' && $ownerLabel !== null && $whenUtc !== null) {
                $bodyStart = $index + 1;
                break;
            }
        }

        if ($ownerLabel === null) {
            return null;
        }
        $source = IntakeForms::sourceForOwnerLabel($ownerLabel);
        if ($source === null) {
            return null;
        }

        $keysByLabel = IntakeForms::keysByLabel($source);
        $answers = [];
        $currentKey = null;

        foreach (array_slice($lines, $bodyStart) as $line) {
            $matched = false;
            foreach ($keysByLabel as $label => $key) {
                $prefix = $label . ': ';
                if (str_starts_with($line, $prefix)) {
                    $currentKey = $key;
                    $answers[$key] = substr($line, strlen($prefix));
                    $matched = true;
                    break;
                }
            }
            if ($matched || $currentKey === null) {
                continue;
            }
            // A continuation line. Blank lines inside a pasted paragraph are
            // kept, because the person put them there.
            $answers[$currentKey] .= "\n" . $line;
        }

        foreach ($answers as $key => $value) {
            $answers[$key] = trim((string) $value);
        }

        return [
            'kind'         => 'archive',
            'source'       => $source,
            'answers'      => $answers,
            // The bytes of the original, so the same file always produces the
            // same key no matter how many times the import runs.
            'hash'         => hash('sha256', $raw),
            'submitted_at' => $this->normalizeWhen($whenUtc, $path),
            'path'         => 'fs-metrics/sa-leads/' . basename($path),
            'stamp'        => $this->stampFromFilename(basename($path)),
            'label'        => $ownerLabel,
        ];
    }

    /**
     * The log, line by line. Null in the place of a line that is not in the
     * five-column shape the log has always used.
     *
     * @return list<array<string,mixed>|null>
     */
    private function logRecords(): array
    {
        $path = $this->logPath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $out = [];
        $number = 0;
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $number++;
            if (trim($line) === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 5) {
                $out[] = null;
                continue;
            }
            [$when, $source, $name, $email, $organization] = array_slice($parts, 0, 5);

            $email = strtolower(trim($email));
            $source = trim($source);

            $answers = array_filter([
                'name'         => trim($name),
                'email'        => $email,
                'organization' => trim($organization),
            ], static fn (string $v): bool => $v !== '');

            $out[] = [
                'kind'    => 'log',
                // Kept as its own source so the Desk never implies the rest of
                // the answers exist somewhere. They do not: the archive file
                // for this one was pruned years of leads ago.
                'source'  => IntakeForms::SOURCE_LEGACY_LOG,
                'answers' => $answers,
                'hash'    => hash('sha256', 'sa-leads.log|' . trim($line)),
                'submitted_at' => $this->normalizeWhen(trim($when), ''),
                'path'    => 'fs-metrics/sa-leads.log#' . $number,
                // The same stamp an archive file for this submission would
                // carry, which is how a line that already has a full record is
                // recognised and left alone.
                'stamp'   => $this->stampFor(trim($when), $source, $email),
                'label'   => IntakeForms::ownerLabel($source),
                'original_source' => $source,
            ];
        }
        return $out;
    }

    /**
     * The stamp sa-lead.php builds for an archive filename:
     * Ymd-His of the moment, then eight hex of sha256(email . source).
     *
     * The log stores the time to the minute and the filename to the second, so
     * only the minute prefix and the hash are compared. Two submissions from
     * the same address to the same form inside one minute would collide, which
     * would mean skipping a log line that already has an archive file. That is
     * the safe direction: the archive file is the fuller record and it is the
     * one kept.
     */
    private function stampFor(string $whenUtc, string $source, string $email): string
    {
        $minute = preg_replace('/[^0-9]/', '', substr($whenUtc, 0, 16)) ?? '';
        return $minute . '-' . substr(hash('sha256', $email . $source), 0, 8);
    }

    /** The same key, taken from an archive filename. */
    private function stampFromFilename(string $filename): string
    {
        // 20260815-143201-abcd1234.txt => 202608151432-abcd1234
        if (preg_match('/^(\d{8})-(\d{2})(\d{2})\d{2}-([0-9a-f]{8})\.txt$/', $filename, $m) === 1) {
            return $m[1] . $m[2] . $m[3] . '-' . $m[4];
        }
        return 'unmatched-' . $filename;
    }

    /**
     * "2026-08-15 14:32 UTC" into the storage format. A missing or unreadable
     * timestamp falls back to the file's own modification time rather than to
     * now, because "now" would put a two-year-old lead at the top of her board.
     */
    private function normalizeWhen(?string $whenUtc, string $path): string
    {
        $whenUtc = trim((string) $whenUtc);
        $whenUtc = trim(str_ireplace('UTC', '', $whenUtc));

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat(
                $format,
                $whenUtc,
                new \DateTimeZone('UTC')
            );
            if ($parsed !== false) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        $fallback = ($path !== '' && is_file($path)) ? (int) filemtime($path) : time();
        return gmdate('Y-m-d H:i:s', $fallback);
    }

    /** @param array<string,mixed> $candidate */
    private function isUsable(array $candidate): bool
    {
        $answers = is_array($candidate['answers'] ?? null) ? $candidate['answers'] : [];
        $email = trim((string) ($answers['email'] ?? ''));
        $name = trim((string) ($answers['name'] ?? ''));
        return $name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
