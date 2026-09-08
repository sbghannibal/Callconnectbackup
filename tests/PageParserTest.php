<?php

declare(strict_types=1);

namespace App\Tests;

use App\PageParser;
use PHPUnit\Framework\TestCase;

final class PageParserTest extends TestCase
{
    private function parse(string $fixture = 'user_detail.html'): \App\ParsedPage
    {
        $html = (string) file_get_contents(__DIR__ . '/fixtures/pages/' . $fixture);

        return (new PageParser())->parse($html, 'https://portal.example/portal/users/A1');
    }

    private function fields(\App\ParsedPage $page, string $kind): array
    {
        return array_values(array_filter(
            $page->fields,
            static fn (array $field): bool => $field['kind'] === $kind
        ));
    }

    private function find(\App\ParsedPage $page, string $label): array
    {
        foreach ($page->fields as $field) {
            if ($field['label'] === $label) {
                return $field;
            }
        }

        $this->fail(sprintf('Field "%s" not found', $label));
    }

    public function testTitleAndTabsAreExtracted(): void
    {
        $page = $this->parse();

        $this->assertSame('User A1 - Reception', $page->title);
        $this->assertSame(['Details', 'Licenses', 'Phone numbers', 'Incoming calls'], $page->tabs);
    }

    public function testDetailPairsAreExtractedWithTheirSection(): void
    {
        $page = $this->parse();
        $email = $this->find($page, 'E-mail address');

        $this->assertSame('detail', $email['kind']);
        $this->assertSame('Details', $email['section']);
        $this->assertSame('anna.peeters@example.be', $email['value']);
    }

    public function testTableRowsAreExtractedWithColumnHeadersAsLabels(): void
    {
        $page = $this->parse();
        $extension = $this->find($page, 'Extension');

        $this->assertSame('table', $extension['kind']);
        $this->assertSame('Phone numbers', $extension['section']);
        $this->assertSame('1001', $extension['value']);
    }

    public function testListItemsBecomeLabelValuePairs(): void
    {
        $page = $this->parse();
        $license = $this->find($page, 'Standard');

        $this->assertSame('list', $license['kind']);
        $this->assertSame('Licenses', $license['section']);
        $this->assertSame('yes', $license['value']);
    }

    public function testFormFieldsIncludeTypeOptionsAndSelectedState(): void
    {
        $page = $this->parse();

        $dnd = $this->find($page, 'Do Not Disturb');
        $this->assertSame('form_field', $dnd['kind']);
        $this->assertSame('checkbox', $dnd['type']);
        $this->assertSame('do_not_disturb', $dnd['name']);
        $this->assertSame('dnd', $dnd['element_id']);
        $this->assertTrue($dnd['selected']);
        $this->assertSame('Incoming calls', $dnd['section']);

        $waiting = $this->find($page, 'Allow second incoming call');
        $this->assertFalse($waiting['selected']);

        $plan = $this->find($page, 'Incoming calling plan');
        $this->assertSame('select', $plan['type']);
        $this->assertSame('internal', $plan['value']);
        $this->assertSame(
            [['value' => 'all', 'label' => 'All calls'], ['value' => 'internal', 'label' => 'Internal only']],
            $plan['options']
        );
    }

    public function testPasswordFieldsAreNeverExtracted(): void
    {
        $names = array_column($this->parse()->fields, 'name');

        $this->assertContains('csrf', $names);
        $this->assertNotContains('password', $names);
    }

    public function testLinksFormsAndEmbeddedJsonAreCollected(): void
    {
        $page = $this->parse();

        $this->assertContains('https://portal.example/portal/users/A1?tab=licenses', array_column($page->links, 'url'));
        $this->assertSame(
            [['action' => '/portal/users/A1/settings', 'method' => 'POST', 'name' => 'incoming-calls']],
            $page->forms
        );
        $this->assertSame([['userId' => 'A1', 'sequentialRinging' => false, 'pushToTalk' => true]], $page->jsonBlocks);
    }

    public function testRelativeLinksAreResolvedAgainstThePageUrl(): void
    {
        $page = (new PageParser())->parse(
            '<html><body><a href="settings">Settings</a><a href="#skip">Skip</a>'
            . '<a href="javascript:void(0)">JS</a></body></html>',
            'https://portal.example/portal/users/A1'
        );

        $this->assertSame(
            [['url' => 'https://portal.example/portal/users/settings', 'text' => 'Settings']],
            $page->links
        );
    }

    public function testJsonResponsesAreFlattenedIntoFields(): void
    {
        $page = (new PageParser())->parse('{"id": "A1", "settings": {"dnd": true}}');

        $this->assertSame(
            [
                ['kind' => 'json', 'label' => 'id', 'value' => 'A1'],
                ['kind' => 'json', 'label' => 'settings.dnd', 'value' => '1'],
            ],
            array_map(
                static fn (array $field): array => [
                    'kind' => $field['kind'],
                    'label' => $field['label'],
                    'value' => $field['value'],
                ],
                $page->fields
            )
        );
    }

    public function testEmptyPagesDoNotFail(): void
    {
        $page = (new PageParser())->parse('   ');

        $this->assertSame('', $page->title);
        $this->assertSame([], $page->fields);
    }
}
