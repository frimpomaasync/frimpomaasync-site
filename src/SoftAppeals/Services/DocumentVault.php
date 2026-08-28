<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;

/**
 * The private vault. Every document body, every executed record, every
 * signature payload.
 *
 * Three properties, and the class exists to hold all three in one place rather
 * than have each caller remember them.
 *
 * Nothing here is ever a URL. Callers hand over a relative path and get back a
 * hash; there is no method that turns a stored file into a link, so there is no
 * code path that could accidentally publish one. Reading a document back is
 * always the application reading it and then deciding who may see it.
 *
 * Every directory it writes into is deny-all on the server. The .htaccess files
 * are committed, and they are .htaccess rather than .gitkeep because the FTPS
 * deploy excludes every path matching a dot-git glob and silently dropped each
 * one of those folders the first time. See the note in the agreements folder's
 * own .htaccess.
 *
 * A path is checked before it is used. A relative path is the only thing
 * accepted, it may hold no dot segment, and the resolved absolute path has to
 * still sit under the vault root. That is three checks for a value that in this
 * application is always built by the code and never by a request, which is the
 * right amount for the one directory holding executed agreements.
 */
final class DocumentVault
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function root(): string
    {
        return rtrim($this->config->privateStoragePath(), '/');
    }

    /**
     * Write a file and return its SHA-256.
     *
     * The hash is taken of the bytes as written, not of the string as passed,
     * so a write that half-succeeded cannot be recorded as a clean one.
     */
    public function write(string $relativePath, string $contents): string
    {
        $absolute = $this->absolute($relativePath);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The vault directory could not be created.');
        }

        if (@file_put_contents($absolute, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('The vault could not be written to.');
        }
        @chmod($absolute, 0640);

        $written = @file_get_contents($absolute);
        if ($written === false) {
            throw new \RuntimeException('The vault file could not be read back after writing.');
        }

        return hash('sha256', $written);
    }

    public function read(string $relativePath): ?string
    {
        $absolute = $this->absolute($relativePath);
        if (!is_file($absolute)) {
            return null;
        }
        $contents = @file_get_contents($absolute);
        return $contents === false ? null : $contents;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->absolute($relativePath));
    }

    /**
     * Read a file back and check it against the hash it was stored with.
     *
     * This is the acceptance criterion "final executed representation reopens
     * and matches stored hashes", and it is a method rather than a line in a
     * test because she needs to be able to press it herself on any document,
     * years later, and be told yes or no.
     *
     * @return array{found:bool,matches:bool,sha256:?string}
     */
    public function verify(string $relativePath, ?string $expectedSha256): array
    {
        $contents = $this->read($relativePath);
        if ($contents === null) {
            return ['found' => false, 'matches' => false, 'sha256' => null];
        }
        $actual = hash('sha256', $contents);
        return [
            'found'   => true,
            'matches' => $expectedSha256 !== null && hash_equals($expectedSha256, $actual),
            'sha256'  => $actual,
        ];
    }

    /**
     * Where one document's body lives.
     *
     * The engagement reference is in the path so that a person looking at the
     * vault directly can tell whose agreements these are without opening one.
     */
    public static function documentPath(string $engagementRef, string $documentRef, int $version): string
    {
        return 'agreements/' . self::segment($engagementRef) . '/'
            . self::segment($documentRef) . '-v' . max(1, $version) . '.txt';
    }

    public static function executedPath(string $engagementRef, string $documentRef, int $version): string
    {
        return 'agreements/' . self::segment($engagementRef) . '/'
            . self::segment($documentRef) . '-v' . max(1, $version) . '-executed.html';
    }

    public static function signaturePath(string $documentRef, string $party): string
    {
        return 'signatures/' . self::segment($documentRef) . '-' . self::segment($party) . '.json';
    }

    /** One path segment, reduced to characters that cannot mean anything else. */
    private static function segment(string $raw): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
        if ($clean === '') {
            throw new \RuntimeException('That is not a usable vault path segment.');
        }
        return $clean;
    }

    private function absolute(string $relativePath): string
    {
        $relative = trim($relativePath);
        if ($relative === '' || str_starts_with($relative, '/')) {
            throw new \RuntimeException('The vault takes a relative path.');
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('That vault path is not one this application builds.');
            }
        }

        $root = $this->root();
        $absolute = $root . '/' . $relative;

        // The resolved parent has to still be inside the vault. A symlink is
        // the one way the checks above can be satisfied by a path that leaves,
        // and realpath is what sees through it.
        $parent = realpath(dirname($absolute));
        if ($parent !== false) {
            $realRoot = realpath($root);
            if ($realRoot !== false && !str_starts_with($parent . '/', $realRoot . '/')) {
                throw new \RuntimeException('That vault path leaves the vault.');
            }
        }

        return $absolute;
    }
}
