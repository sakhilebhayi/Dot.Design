<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The editor page carries one JSON data block (script#studio-boot). These helpers read it back the way the browser does.
 */
trait ReadsEditorBoot
{
    /** @return array<string, mixed> */
    protected function editorBoot(string $html): array
    {
        $matched = preg_match('#<script type="application/json" id="studio-boot">(.*?)</script>#s', $html, $m);
        Assert::assertSame(1, $matched, 'The editor page must carry the studio-boot JSON block.');

        $data = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        Assert::assertIsArray($data, 'The studio-boot block must be valid JSON.');

        return $data;
    }
}
