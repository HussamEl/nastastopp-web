<?php
declare(strict_types=1);

namespace Nasta;

/**
 * The server's settings and secrets, from nastastopp-config.php: found by walking up from this
 * folder, so it lies above public_html and is never served or committed.
 */
final class Config
{
    public const FILE = 'nastastopp-config.php';

    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public static function load(?string $path = null): self
    {
        $path ??= getenv('NASTA_CONFIG') ?: self::find(__DIR__);
        if ($path === null || !is_file($path)) {
            throw new \RuntimeException('config missing');
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('config is not an array');
        }
        return new self($values);
    }

    private static function find(string $from): ?string
    {
        for ($dir = $from; ; $dir = dirname($dir)) {
            $file = $dir . DIRECTORY_SEPARATOR . self::FILE;
            if (is_file($file)) {
                return $file;
            }
            if (dirname($dir) === $dir) {
                return null;
            }
        }
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** How many requests a device may make a day to [service] ([DEFAULT_LIMIT] when the config does not say). */
    public function dailyLimit(string $service): int
    {
        $limits = $this->values['daily_limits'] ?? [];
        return (int) ($limits[$service] ?? self::DEFAULT_LIMIT);
    }

    public const DEFAULT_LIMIT = 500;
}
