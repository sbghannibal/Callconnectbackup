<?php

declare(strict_types=1);

namespace App;

/**
 * Small URL helpers for the discovery crawler: resolving relative links and
 * checking whether a URL stays inside the configured CallConnect base URL.
 */
final class Url
{
    public static function resolve(string $baseUrl, string $link): string
    {
        $link = trim($link);
        if ($link === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $link) === 1) {
            return self::normalize($link);
        }

        if (str_starts_with($link, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return self::normalize($scheme . ':' . $link);
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($link, '/')) {
            return self::normalize($root . $link);
        }

        $directory = rtrim(str_replace('\\', '/', dirname($parts['path'] ?? '/')), '/');

        return self::normalize($root . $directory . '/' . $link);
    }

    /**
     * Removes the fragment and a trailing slash on the path, so the crawler
     * does not visit the same page twice.
     */
    public static function normalize(string $url): string
    {
        $url = preg_replace('/#.*$/', '', $url) ?? $url;
        if (!str_contains($url, '?') && preg_match('#^(https?://[^/]+)/+$#i', $url) !== 1) {
            $url = rtrim($url, '/');
        }

        return $url;
    }

    public static function isInside(string $baseUrl, string $url): bool
    {
        $base = rtrim($baseUrl, '/');

        return $url === $base || str_starts_with($url, $base . '/') || str_starts_with($url, $base . '?');
    }

    /**
     * Path (including query string) of a URL, relative to the base URL.
     */
    public static function pathOf(string $baseUrl, string $url): string
    {
        if (self::isInside($baseUrl, $url)) {
            $path = substr($url, strlen(rtrim($baseUrl, '/')));

            return $path === '' ? '/' : $path;
        }

        $parts = parse_url($url);

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Matches a path against a list of patterns that may contain "*".
     * An empty list allows everything.
     *
     * @param array<int, string> $patterns
     */
    public static function matchesAny(string $path, array $patterns): bool
    {
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $path) || str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
