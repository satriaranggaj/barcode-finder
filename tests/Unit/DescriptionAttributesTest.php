<?php

namespace Tests\Unit;

use App\Services\DescriptionAttributes;
use Tests\TestCase;

class DescriptionAttributesTest extends TestCase
{
    private function values(array $records, string $key): array
    {
        $values = [];
        foreach ($records as $record) {
            if ($record['key'] === $key) {
                $values[] = $record['value'];
            }
        }
        sort($values);

        return $values;
    }

    public function test_screwdriver_variants_mirror_python_registry(): void
    {
        $plus = DescriptionAttributes::parse('Obeng Plus PH2 150MM gagang hitam 1pcs');
        $this->assertSame(['PH2', 'PHILLIPS'], $this->values($plus, 'drive'));
        $this->assertSame(['150MM'], $this->values($plus, 'measurement'));
        $flat = DescriptionAttributes::parse('Obeng Minus FLAT 6x150mm isi 2PCS');
        $this->assertSame(['FLAT', 'FLAT'], $this->values($flat, 'drive'));
        $this->assertSame(['6X150MM'], $this->values($flat, 'dimension'));
        $phillips = DescriptionAttributes::parse('PHILLIPS SCREWDRIVER PH1 100MM');
        $this->assertSame(['PH1', 'PHILLIPS'], $this->values($phillips, 'drive'));
    }

    public function test_obeng_synonyms_and_length_mirror_python(): void
    {
        $this->assertSame(['PHILLIPS'], $this->values(DescriptionAttributes::parse('Obeng Plus'), 'drive'));
        $this->assertSame(['FLAT'], $this->values(DescriptionAttributes::parse('Obeng Minus'), 'drive'));
        $this->assertSame(['PHILLIPS'], $this->values(DescriptionAttributes::parse('Obeng Kembang'), 'drive'));
        $this->assertSame(['LONG'], $this->values(DescriptionAttributes::parse('Obeng Plus Panjang 150MM'), 'length'));
        $this->assertSame(['SHORT'], $this->values(DescriptionAttributes::parse('Obeng Minus Pendek 100MM'), 'length'));
        $this->assertSame(['6X150MM'], $this->values(DescriptionAttributes::parse('Obeng 6x150mm'), 'dimension'));
        $this->assertSame(['6"'], $this->values(DescriptionAttributes::parse("COL.SCREWDRIVER 6'"), 'measurement'));
        $this->assertContains('JC403-4', $this->values(DescriptionAttributes::parse('SCREWDRIVER JC403-4'), 'model'));
        $this->assertContains('JC403-6', $this->values(DescriptionAttributes::parse('SCREWDRIVER JC403-6'), 'model'));
    }

    public function test_measurements_sizes_and_bare_numbers(): void
    {
        $records = DescriptionAttributes::parse('Kunci pas 10MM, 12 MM, kabel 1,5M, pipa 2 INCH, kuas 1" dan 1.5"');
        $this->assertSame(['1"', '1.5"', '1.5M', '10MM', '12MM', '2INCH'], $this->values($records, 'measurement'));
        $cued = DescriptionAttributes::parse('Sandal Swallow model 41 SIZE 40 hitam');
        $this->assertSame(['SIZE40'], $this->values($cued, 'size'));
        $bare = DescriptionAttributes::parse('Sandal Swallow 38 39 40 41 biru');
        $this->assertSame([], $this->values($bare, 'size'));
        $this->assertSame(['BIRU'], $this->values($bare, 'color'));
    }

    public function test_model_quantity_color_and_record_shape(): void
    {
        $records = DescriptionAttributes::parse('Kuas cat 2" JC414 putih 1 set, cat MERAH 5M');
        $this->assertContains('JC414', $this->values($records, 'model'));
        $this->assertSame(['MERAH', 'PUTIH'], $this->values($records, 'color'));
        $this->assertSame(['1SET'], $this->values($records, 'quantity'));
        foreach ($records as $record) {
            $this->assertSame(['key', 'value', 'raw', 'source', 'rule'], array_keys($record));
            $this->assertSame('description_parser', $record['source']);
        }
        $drives = array_values(array_filter($records, fn ($record) => $record['key'] === 'drive'));
        $this->assertSame([], $drives);
    }

    public function test_raw_preserved_and_empty_inputs(): void
    {
        $records = DescriptionAttributes::parse('obeng ph2 150mm');
        $byKey = [];
        foreach ($records as $record) {
            $byKey[$record['key']] = $record;
        }
        $this->assertSame('ph2', $byKey['drive']['raw']);
        $this->assertSame('PH2', $byKey['drive']['value']);
        $this->assertSame('drive', $byKey['drive']['rule']);
        $this->assertSame([], DescriptionAttributes::parse(null));
        $this->assertSame([], DescriptionAttributes::parse(''));
        $this->assertSame([], DescriptionAttributes::parse('   '));
    }
}
