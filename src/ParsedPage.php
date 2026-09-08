<?php

declare(strict_types=1);

namespace App;

/**
 * Structured result of parsing a single CallConnect page.
 */
final class ParsedPage
{
    /**
     * @param array<int, string>                    $tabs
     * @param array<int, array<string, mixed>>      $fields
     * @param array<int, array{url: string, text: string}> $links
     * @param array<int, array{action: string, method: string, name: string}> $forms
     * @param array<int, mixed>                     $jsonBlocks
     */
    public function __construct(
        public readonly string $title = '',
        public readonly array $tabs = [],
        public readonly array $fields = [],
        public readonly array $links = [],
        public readonly array $forms = [],
        public readonly array $jsonBlocks = [],
    ) {
    }
}
