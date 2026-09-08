<?php

declare(strict_types=1);

namespace App;

/**
 * Parses a CallConnect HTML page with DOMDocument/DOMXPath and pulls out
 * everything that could be relevant later: the page title, tab/section names,
 * label + value pairs from detail cards, tables and lists, all form fields
 * (including their options and selected state), links and JSON blocks that are
 * embedded in the page.
 */
final class PageParser
{
    public function parse(string $html, string $baseUrl = ''): ParsedPage
    {
        $html = trim($html);
        if ($html === '') {
            return new ParsedPage();
        }

        $decoded = json_decode($html, true);
        if (is_array($decoded)) {
            return new ParsedPage('', [], $this->fieldsFromJson($decoded, 'json'), [], [], [$decoded]);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);

        $fields = array_merge(
            $this->definitionLists($xpath),
            $this->tables($xpath),
            $this->lists($xpath),
            $this->formFields($xpath),
        );

        return new ParsedPage(
            $this->title($xpath),
            $this->tabs($xpath),
            $fields,
            $this->links($xpath, $baseUrl),
            $this->forms($xpath),
            $this->jsonBlocks($xpath),
        );
    }

    /* ----------------------------------------------------------- page meta */

    private function title(\DOMXPath $xpath): string
    {
        foreach (['//title', '//h1', '//h2'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $text = $this->text($nodes->item(0));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function tabs(\DOMXPath $xpath): array
    {
        $query = '//*[@role="tab"]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " tab ")]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " nav-tabs ")]/*'
            . ' | //*[@data-tab]';

        $tabs = [];
        $nodes = $xpath->query($query);
        foreach ($nodes ?: [] as $node) {
            $text = $this->text($node);
            if ($text !== '' && !in_array($text, $tabs, true)) {
                $tabs[] = $text;
            }
        }

        return $tabs;
    }

    /* -------------------------------------------------------------- fields */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitionLists(\DOMXPath $xpath): array
    {
        $fields = [];
        $nodes = $xpath->query('//dt');
        foreach ($nodes ?: [] as $term) {
            $value = $term->nextSibling;
            while ($value !== null && !($value instanceof \DOMElement && strtolower($value->nodeName) === 'dd')) {
                $value = $value->nextSibling;
            }

            $label = $this->text($term);
            if ($label === '') {
                continue;
            }

            $fields[] = $this->field('detail', $this->section($xpath, $term), $label, $this->text($value));
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tables(\DOMXPath $xpath): array
    {
        $fields = [];
        $tables = $xpath->query('//table');
        foreach ($tables ?: [] as $index => $table) {
            $section = $this->section($xpath, $table);
            $headers = [];
            foreach ($xpath->query('.//tr[1]/th', $table) ?: [] as $header) {
                $headers[] = $this->text($header);
            }

            foreach ($xpath->query('.//tr', $table) ?: [] as $rowIndex => $row) {
                $cells = $xpath->query('./th | ./td', $row);
                if ($cells === false || $cells->length === 0) {
                    continue;
                }

                // Two column layouts are label/value pairs.
                if ($headers === [] && $cells->length === 2) {
                    $label = $this->text($cells->item(0));
                    if ($label !== '') {
                        $fields[] = $this->field('table', $section, $label, $this->text($cells->item(1)));
                    }
                    continue;
                }

                foreach ($cells as $cellIndex => $cell) {
                    if ($cell->nodeName === 'th' && $rowIndex === 0) {
                        continue;
                    }
                    $label = $headers[$cellIndex] ?? sprintf('kolom %d', $cellIndex + 1);
                    $value = $this->text($cell);
                    if ($value === '') {
                        continue;
                    }
                    $fields[] = $this->field(
                        'table',
                        $section,
                        $label,
                        $value,
                        sprintf('table%d.row%d.col%d', $index + 1, $rowIndex + 1, $cellIndex + 1)
                    );
                }
            }
        }

        return $fields;
    }

    /**
     * List items such as "Do Not Disturb: off" or "Standard" inside a section.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lists(\DOMXPath $xpath): array
    {
        $fields = [];
        foreach ($xpath->query('//ul/li | //ol/li') ?: [] as $item) {
            if ($xpath->query('.//a | .//input | .//select | .//textarea', $item)->length > 0) {
                continue;
            }

            $text = $this->text($item);
            if ($text === '') {
                continue;
            }

            $label = $text;
            $value = '';
            if (str_contains($text, ':')) {
                [$label, $value] = array_map('trim', explode(':', $text, 2));
            }

            $fields[] = $this->field('list', $this->section($xpath, $item), $label, $value);
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formFields(\DOMXPath $xpath): array
    {
        $fields = [];
        foreach ($xpath->query('//input | //select | //textarea') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->nodeName);
            $type = $tag === 'input' ? strtolower($node->getAttribute('type') ?: 'text') : $tag;
            if ($type === 'password') {
                continue;
            }

            $name = $node->getAttribute('name');
            $elementId = $node->getAttribute('id');
            $options = [];
            $selected = null;
            $value = '';

            if ($tag === 'select') {
                foreach ($xpath->query('.//option', $node) ?: [] as $option) {
                    if (!$option instanceof \DOMElement) {
                        continue;
                    }
                    $optionValue = $option->hasAttribute('value')
                        ? $option->getAttribute('value')
                        : $this->text($option);
                    $options[] = ['value' => $optionValue, 'label' => $this->text($option)];
                    if ($option->hasAttribute('selected')) {
                        $value = $optionValue;
                        $selected = true;
                    }
                }
            } elseif ($tag === 'textarea') {
                $value = $this->text($node);
            } else {
                $value = $node->getAttribute('value');
                if (in_array($type, ['checkbox', 'radio'], true)) {
                    $selected = $node->hasAttribute('checked');
                }
            }

            $fields[] = $this->field(
                'form_field',
                $this->section($xpath, $node),
                $this->fieldLabel($xpath, $node),
                $value,
                $name !== '' ? $name : $elementId,
                [
                    'element_id' => $elementId,
                    'type' => $type,
                    'options' => $options,
                    'selected' => $selected,
                ]
            );
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function field(string $kind, string $section, string $label, string $value, string $name = '', array $extra = []): array
    {
        return array_merge([
            'kind' => $kind,
            'section' => $section,
            'label' => $label,
            'name' => $name,
            'element_id' => '',
            'type' => '',
            'value' => $value,
            'options' => [],
            'selected' => null,
        ], $extra);
    }

    /**
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fieldsFromJson(array $data, string $section, string $prefix = ''): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $fields = array_merge($fields, $this->fieldsFromJson($value, $section, $name));
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $fields[] = $this->field('json', $section, $name, $value === null ? '' : (string) $value, $name);
        }

        return $fields;
    }

    private function fieldLabel(\DOMXPath $xpath, \DOMElement $node): string
    {
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $labels = $xpath->query(sprintf('//label[@for=%s]', $this->quote($id)));
            if ($labels !== false && $labels->length > 0) {
                return $this->text($labels->item(0));
            }
        }

        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement) {
            if (strtolower($parent->nodeName) === 'label') {
                return $this->text($parent);
            }
            $parent = $parent->parentNode;
        }

        foreach (['aria-label', 'placeholder', 'name', 'id'] as $attribute) {
            $value = $node->getAttribute($attribute);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Nearest section/tab/card heading above the given node.
     */
    private function section(\DOMXPath $xpath, ?\DOMNode $node): string
    {
        $current = $node?->parentNode;
        while ($current instanceof \DOMElement) {
            foreach (['data-section', 'data-tab', 'aria-label'] as $attribute) {
                $value = $current->getAttribute($attribute);
                if ($value !== '') {
                    return $value;
                }
            }

            $headings = $xpath->query('./h1 | ./h2 | ./h3 | ./h4 | ./h5 | ./h6 | ./legend | ./caption', $current);
            if ($headings !== false && $headings->length > 0) {
                $text = $this->text($headings->item(0));
                if ($text !== '') {
                    return $text;
                }
            }

            $current = $current->parentNode;
        }

        return '';
    }

    /* ------------------------------------------------------- links & forms */

    /**
     * @return array<int, array{url: string, text: string}>
     */
    private function links(\DOMXPath $xpath, string $baseUrl): array
    {
        $links = [];
        $seen = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('#^(javascript|mailto|tel):#i', $href) === 1) {
                continue;
            }

            $url = Url::resolve($baseUrl, $href);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $links[] = ['url' => $url, 'text' => $this->text($node)];
        }

        return $links;
    }

    /**
     * @return array<int, array{action: string, method: string, name: string}>
     */
    private function forms(\DOMXPath $xpath): array
    {
        $forms = [];
        foreach ($xpath->query('//form') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $forms[] = [
                'action' => $node->getAttribute('action'),
                'method' => strtoupper($node->getAttribute('method') ?: 'GET'),
                'name' => $node->getAttribute('name') ?: $node->getAttribute('id'),
            ];
        }

        return $forms;
    }

    /**
     * @return array<int, mixed>
     */
    private function jsonBlocks(\DOMXPath $xpath): array
    {
        $blocks = [];
        foreach ($xpath->query('//script[@type]') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $type = strtolower($node->getAttribute('type'));
            if (!str_contains($type, 'json')) {
                continue;
            }

            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }

        return $blocks;
    }

    /* -------------------------------------------------------------- helpers */

    private function text(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private function quote(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        return '"' . str_replace('"', '', $value) . '"';
    }
}
