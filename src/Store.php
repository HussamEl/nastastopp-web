<?php
declare(strict_types=1);

namespace Nasta;

use PDO;

/**
 * The devices, their enrollment codes and how many requests each made a day. Nothing else: no
 * names of passengers, no addresses, no positions, no request bodies. Tokens and codes are kept
 * only as their SHA-256.
 */
final class Store
{
    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->migrate();
    }

    public static function open(Config $config): self
    {
        return new self(new PDO($config->string('db_dsn'), $config->string('db_user'), $config->string('db_pass')));
    }

    private function migrate(): void
    {
        // Plain SQL that MySQL and SQLite (the tests) both read.
        $this->db->exec('CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY ' . $this->autoIncrement() . ',
            token_hash CHAR(64) NOT NULL UNIQUE,
            label VARCHAR(80) NOT NULL,
            role VARCHAR(16) NOT NULL,
            active SMALLINT NOT NULL DEFAULT 1,
            created_at VARCHAR(20) NOT NULL,
            last_seen VARCHAR(20) NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS codes (
            code_hash CHAR(64) NOT NULL PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            expires_at VARCHAR(20) NOT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS usage_counts (
            device_id INTEGER NOT NULL,
            day CHAR(10) NOT NULL,
            service VARCHAR(24) NOT NULL,
            requests INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (device_id, day, service)
        )');
    }

    private function autoIncrement(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT';
    }

    /** A new single-use code for a device to enroll with, valid [days]. Returned once, kept only as its hash. */
    public function newCode(string $label, int $days = 7): string
    {
        $code = self::readableCode();
        $this->db->prepare('INSERT INTO codes (code_hash, label, expires_at) VALUES (?, ?, ?)')
            ->execute([hash('sha256', $code), self::clean($label), gmdate('Y-m-d H:i:s', time() + $days * 86400)]);
        return $code;
    }

    /** Eight letters and digits a person can read out and type: no 0/O, 1/I/L. */
    private static function readableCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    /** Enrolls a device with [code]: its token (returned once), or null for an unknown or expired code. */
    public function enroll(string $code, string $role): ?string
    {
        $hash = hash('sha256', strtoupper(trim($code)));
        $this->db->beginTransaction();
        try {
            $row = $this->db->prepare('SELECT label, expires_at FROM codes WHERE code_hash = ?');
            $row->execute([$hash]);
            $found = $row->fetch();
            if ($found === false || $found['expires_at'] < gmdate('Y-m-d H:i:s')) {
                $this->db->rollBack();
                return null;
            }
            // A code is used once.
            $this->db->prepare('DELETE FROM codes WHERE code_hash = ?')->execute([$hash]);
            $token = bin2hex(random_bytes(32));
            $this->db->prepare('INSERT INTO devices (token_hash, label, role, active, created_at) VALUES (?, ?, ?, 1, ?)')
                ->execute([hash('sha256', $token), $found['label'], $role, gmdate('Y-m-d H:i:s')]);
            $this->db->commit();
            return $token;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** The active device holding [token], or null. */
    public function device(string $token): ?array
    {
        $row = $this->db->prepare('SELECT id, label, role FROM devices WHERE token_hash = ? AND active = 1');
        $row->execute([hash('sha256', $token)]);
        $found = $row->fetch();
        if ($found === false) {
            return null;
        }
        $this->db->prepare('UPDATE devices SET last_seen = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $found['id']]);
        return $found;
    }

    /** Counts one request of [device] to [service] today; false once it is over [limit]. */
    public function count(int $device, string $service, int $limit): bool
    {
        $day = gmdate('Y-m-d');
        $update = $this->db->prepare('UPDATE usage_counts SET requests = requests + 1 WHERE device_id = ? AND day = ? AND service = ?');
        $update->execute([$device, $day, $service]);
        if ($update->rowCount() === 0) {
            $this->db->prepare('INSERT INTO usage_counts (device_id, day, service, requests) VALUES (?, ?, ?, 1)')
                ->execute([$device, $day, $service]);
        }
        $now = $this->db->prepare('SELECT requests FROM usage_counts WHERE device_id = ? AND day = ? AND service = ?');
        $now->execute([$device, $day, $service]);
        return (int) $now->fetchColumn() <= $limit;
    }

    public function setActive(int $device, bool $active): void
    {
        $this->db->prepare('UPDATE devices SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $device]);
    }

    /** Every device, with its requests today and this month. */
    public function devices(): array
    {
        $today = gmdate('Y-m-d');
        $month = gmdate('Y-m') . '%';
        $rows = $this->db->query('SELECT id, label, role, active, created_at, last_seen FROM devices ORDER BY id')->fetchAll();
        $usage = $this->db->prepare('SELECT service, SUM(CASE WHEN day = ? THEN requests ELSE 0 END) AS today, SUM(requests) AS month
            FROM usage_counts WHERE device_id = ? AND day LIKE ? GROUP BY service');
        foreach ($rows as &$row) {
            $usage->execute([$today, $row['id'], $month]);
            $row['usage'] = $usage->fetchAll();
        }
        return $rows;
    }

    /** The codes not used yet. */
    public function openCodes(): array
    {
        return $this->db->query('SELECT label, expires_at FROM codes ORDER BY expires_at')->fetchAll();
    }

    private static function clean(string $label): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $label) ?? ''), 0, 80);
    }
}
