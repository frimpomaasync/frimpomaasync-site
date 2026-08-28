<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Config;

/**
 * The handful of values she sets from the Desk.
 *
 * The private config file on the server is the right home for a secret and
 * the wrong home for a company name: it can only be edited through the host's
 * file manager, and SA_LEGAL_ENTITY sat unset there for a day while every
 * document on staging named a placeholder. A key/value row the owner writes
 * from a screen she already has is the fix. The config file still wins for
 * nothing: a value set here takes precedence, and a blank here falls back to
 * the file, and a blank in both is still the refusal Config::legalEntity()
 * has always been.
 *
 * Nothing secret goes in this table. The keys are an allowlist.
 */
final class SettingsRepository extends Repository
{
    public const LEGAL_ENTITY = 'legal_entity';
    public const TRADE_NAME   = 'trade_name';

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::LEGAL_ENTITY, self::TRADE_NAME];
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    protected function table(): string
    {
        return 'sa_settings';
    }

    public function get(string $key): ?string
    {
        if (!self::isValidKey($key)) {
            throw new \RuntimeException('Unknown setting: ' . $key);
        }
        $row = $this->db->one(
            'SELECT setting_value FROM sa_settings WHERE setting_key = :k',
            ['k' => $key]
        );
        if ($row === null || $row['setting_value'] === null) {
            return null;
        }
        $value = trim((string) $row['setting_value']);
        return $value === '' ? null : $value;
    }

    /** Write one value. An empty string clears it. */
    public function set(string $key, ?string $value, ?string $userId = null): void
    {
        if (!self::isValidKey($key)) {
            throw new \RuntimeException('Unknown setting: ' . $key);
        }
        $value = $value === null ? null : mb_substr(trim($value), 0, 500);
        if ($value === '') {
            $value = null;
        }

        $now = $this->clock->nowUtc();
        if ($this->db->exists('SELECT setting_key FROM sa_settings WHERE setting_key = :k', ['k' => $key])) {
            $this->db->update('sa_settings', [
                'setting_value' => $value,
                'updated_by'    => $userId,
                'updated_at'    => $now,
            ], ['setting_key' => $key]);
            return;
        }
        $this->db->insert('sa_settings', [
            'setting_key'   => $key,
            'setting_value' => $value,
            'updated_by'    => $userId,
            'updated_at'    => $now,
        ]);
    }

    /** @return array<string,mixed>|null the row, for the Desk to say when and by whom */
    public function row(string $key): ?array
    {
        if (!self::isValidKey($key)) {
            return null;
        }
        return $this->db->one('SELECT * FROM sa_settings WHERE setting_key = :k', ['k' => $key]);
    }

    /**
     * The legal party name for a document: the Desk value, else the config
     * file, else Config's own answer for a blank (a refusal on production, a
     * named placeholder anywhere else).
     */
    public function legalEntity(Config $config): string
    {
        return $this->get(self::LEGAL_ENTITY) ?? $config->legalEntity();
    }

    public function tradeName(Config $config): string
    {
        return $this->get(self::TRADE_NAME) ?? $config->tradeName();
    }

    /**
     * Where the effective legal entity name comes from, for the settings
     * screen to say out loud.
     */
    public function legalEntitySource(Config $config): string
    {
        if ($this->get(self::LEGAL_ENTITY) !== null) {
            return 'desk';
        }
        if (trim($config->string('SA_LEGAL_ENTITY')) !== '') {
            return 'config';
        }
        return 'none';
    }
}
