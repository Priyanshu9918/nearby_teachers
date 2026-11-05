<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SeoSubjectCatalogTest extends TestCase
{
    public function test_every_seeded_subject_has_an_unambiguous_english_catalog_entry(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = json_decode(file_get_contents($root.'/resources/seo/subjects.json'), true, flags: JSON_THROW_ON_ERROR);
        $aliases = [];
        foreach ($catalog as $key => $definition) {
            $this->assertNotEmpty($definition['en'], $key);
            foreach ($definition['aliases'] as $alias) {
                $this->assertArrayNotHasKey($alias, $aliases, 'Ambiguous alias: '.$alias);
                $aliases[$alias] = $key;
            }
        }
        preg_match_all("/SELECT '((?:''|[^'])*)' AS name/", file_get_contents($root.'/database/sql/seed.mysql.sql'), $matches);
        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $raw) {
            $name = str_replace("''", "'", $raw);
            $this->assertArrayHasKey(Str::slug($name), $aliases, 'Missing subject: '.$name);
        }
    }
}
