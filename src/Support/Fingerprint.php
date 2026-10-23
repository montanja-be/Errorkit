<?php

namespace LaraBug\Support;

class Fingerprint
{
    /**
     * Identify an error so its repeats can be grouped.
     *
     * The message is normalised first: ids, numbers, uuids, hashes and quoted
     * values differ between occurrences of what is really the same error
     * ("No query results for model [Order] 42" and "... 43"). The file is made
     * relative to the project, so a new release directory does not count as a
     * new error.
     *
     * @param string|null $class
     * @param string|null $file
     * @param int|null $line
     * @param string|null $message
     * @return string
     */
    public static function make($class, $file, $line, $message): string
    {
        return sha1(implode('|', [
            (string) $class,
            self::relativeFile((string) $file),
            (int) $line,
            self::normalizeMessage((string) $message),
        ]));
    }

    /**
     * @param string $message
     * @return string
     */
    public static function normalizeMessage(string $message): string
    {
        $message = mb_substr($message, 0, 1000);

        $patterns = [
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b[0-9a-f]{16,}\b/i' => '{hash}',
            '/\'[^\']*\'/' => '{str}',
            '/"[^"]*"/' => '{str}',
            '/\d+(\.\d+)?/' => '{n}',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;
    }

    /**
     * @param string $file
     * @return string
     */
    public static function relativeFile(string $file): string
    {
        $base = function_exists('base_path') ? rtrim(base_path(), '/').'/' : '';

        if ($base !== '/' && $base !== '' && strpos($file, $base) === 0) {
            return substr($file, strlen($base));
        }

        // Releases deployed as releases/{timestamp}/ still differ per deploy.
        return preg_replace('~^.*/releases/[^/]+/~', '', $file) ?? $file;
    }
}
