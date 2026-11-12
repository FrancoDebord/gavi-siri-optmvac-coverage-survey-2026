<?php

namespace Tests\Unit;

use App\Support\HouseholdCode;
use Tests\TestCase;

class HouseholdCodeTest extends TestCase
{
    public function test_parses_zone_village_cluster_and_order(): void
    {
        $this->assertSame(
            ['zone' => 'TCH', 'village' => 'SEB', 'cluster' => 7, 'sub_cluster' => null, 'order' => 1, 'cluster_code' => 'TCH-SEB-07'],
            HouseholdCode::parse('TCH-SEB-07-001'),
        );
        $this->assertSame('DAG-ASS-01', HouseholdCode::parse(' dag-ass-1-12 ')['cluster_code']);
        $this->assertSame('DAG-MIN-03', HouseholdCode::parse('DAG MIN 03 001')['cluster_code']);
    }

    public function test_optional_sub_cluster_component(): void
    {
        foreach (['TCH-SEB-07-S2-001', 'TCH-SEB-07S2-001', 'tch-seb-07-s2-001', 'TCH-SEB-07-001-S2', 'TCH-SEB-07 - S 2 - 001'] as $code) {
            $parsed = HouseholdCode::parse($code);
            $this->assertNotNull($parsed, $code);
            $this->assertSame(['TCH-SEB-07', 2, 1], [$parsed['cluster_code'], $parsed['sub_cluster'], $parsed['order']], $code);
        }
        $this->assertSame(12, HouseholdCode::parse('DAG-ASS-01-S12-003')['sub_cluster']);
    }

    public function test_sub_cluster_written_with_parentheses(): void
    {
        foreach (['DAG-HOI-01(S1)-004' => 4, 'DAG-HOI-01S(1)001' => 1, 'DAG-HOI-01 (S1) 006' => 6, 'TCH-BEE-01-S02-003' => 3] as $code => $order) {
            $parsed = HouseholdCode::parse($code);
            $this->assertNotNull($parsed, $code);
            $this->assertSame([substr($code, 0, 10), $order], [$parsed['cluster_code'], $parsed['order']], $code);
            $this->assertContains($parsed['sub_cluster'], [1, 2], $code);
        }
        // Le S d'un code village (ASS, SEB) n'est pas une sous-grappe.
        $this->assertNull(HouseholdCode::parse('DAG-ASS 01 002')['sub_cluster']);
        $this->assertSame('DAG-ASS-01', HouseholdCode::parse('DAG-ASS 01 002')['cluster_code']);
    }

    public function test_rejects_other_formats(): void
    {
        foreach ([null, '', '1', 'HH-A', 'TCH-SEB-001', 'TC-SEB-01-001', 'TCH-SEB-XX-001', 'TCH-SEB-07-S1-001-S2', 'TCH-SEB-07-S-001', 'TCH-SEB-07-X1-001',
            // Saisies erronées relevées sur le terrain : bloc en trop, numéro de grappe manquant.
            'DAG-HOI-01(S1)-002-01', 'DAG-HOI-01(S1)01-007', 'DAG-HOI-S(1)003'] as $code) {
            $this->assertNull(HouseholdCode::parse($code), (string) $code);
        }
    }

    public function test_zone_prefix_maps_to_health_zone_code(): void
    {
        $this->assertSame('1', HouseholdCode::zoneCode('tch'));
        $this->assertSame('2', HouseholdCode::zoneCode('DAG'));
        $this->assertNull(HouseholdCode::zoneCode('XYZ'));
    }
}
