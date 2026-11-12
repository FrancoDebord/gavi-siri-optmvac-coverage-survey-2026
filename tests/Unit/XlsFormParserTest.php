<?php

namespace Tests\Unit;

use App\Services\Dictionary\XlsFormParser;
use Tests\TestCase;

/** Lecture du XLSForm de l'enquête (storage/app/public/settings/cov26c4v3.xls). */
class XlsFormParserTest extends TestCase
{
    private static ?array $parsed = null;

    private function parsed(): array
    {
        $path = config('gavi.settings_path').'/'.config('gavi.default_dictionary');
        if (! is_file($path)) {
            $this->markTestSkipped('XLSForm par défaut absent.');
        }

        return self::$parsed ??= (new XlsFormParser)->parseFile($path);
    }

    private function variable(string $name): array
    {
        return collect($this->parsed()['variables'])->firstWhere('name', $name) ?? $this->fail("Variable {$name} introuvable");
    }

    public function test_settings_are_read(): void
    {
        $settings = $this->parsed()['settings'];
        $this->assertSame('cov26c4v2', $settings['form_id']);
        $this->assertEquals('3', $settings['version']);
        $this->assertSame('French (fr)', $settings['default_language']);
    }

    public function test_types_lists_and_required_flags(): void
    {
        $status = $this->variable('household_survey_status');
        $this->assertSame('select_one', $status['base_type']);
        $this->assertSame('survey_status', $status['list_name']);
        $this->assertTrue($status['required']);

        $zone = $this->variable('l1');
        $this->assertSame('select_one_from_file', $zone['base_type']);
        $this->assertSame('l1.csv', $zone['list_name']);

        // « required » avec espace parasite et nom suivi d'un espace dans le fichier source.
        $opv0 = $this->variable('opv0date');
        $this->assertSame('date', $opv0['base_type']);
        $this->assertTrue($opv0['required']);
        $this->assertSame('. <= today()', $opv0['constraint']);
    }

    public function test_labels_use_the_default_language(): void
    {
        $this->assertStringContainsString('Date de VPO-0', $this->variable('opv0date')['label']);
    }

    public function test_groups_and_repeats_are_tracked(): void
    {
        $dob = $this->variable('child_dob');
        $this->assertSame('smc_cov_hh_cg_child_2023', $dob['repeat_name']);
        $this->assertStringStartsWith('section2/cg/cg_consent/smc_cov_hh_cg_child_2023', $dob['group_path']);
        $this->assertSame('(${child_dob_documented}=\'1\')', $dob['relevant']);

        $this->assertSame('cg', $this->variable('participation_agreed')['repeat_name']);
        $this->assertNull($this->variable('household')['repeat_name']);

        $repeats = collect($this->parsed()['variables'])->where('base_type', 'begin repeat')->pluck('name')->all();
        $this->assertSame(['smc_cov_bednets_2023', 'smc_cov_roster_2023', 'cg', 'smc_cov_hh_cg_child_2023', 'SES'], $repeats);
    }

    public function test_choices_are_parsed(): void
    {
        $status = collect($this->parsed()['choices'])->where('list_name', 'survey_status')->pluck('label', 'name')->all();
        $this->assertSame('A accepté de participer à l\'enquête', $status['0']);
        $this->assertCount(6, $status);
    }

    public function test_type_splitting(): void
    {
        $parser = new XlsFormParser;
        $this->assertSame(['begin group', null], $parser->splitType('begin_group'));
        $this->assertSame(['select_multiple', 'reason_under_vacc'], $parser->splitType('select_multiple reason_under_vacc'));
        $this->assertSame(['integer', null], $parser->splitType('integer '));
    }

    public function test_kobo_asset_content_is_supported(): void
    {
        $parsed = (new XlsFormParser)->parseKoboContent([
            'translations' => ['English (en)', 'French (fr)'],
            'settings' => ['default_language' => 'French (fr)', 'form_id' => 'demo', 'version' => '1'],
            'survey' => [
                ['type' => 'begin_repeat', 'name' => 'enfants', 'label' => ['Children', 'Enfants']],
                ['type' => 'select_one', 'select_from_list_name' => 'yn', '$autoname' => 'vaccine', 'label' => ['Vaccinated?', 'Vacciné ?'], 'required' => true, 'relevant' => '${age} > 1'],
                ['type' => 'end_repeat'],
            ],
            'choices' => [['list_name' => 'yn', 'name' => '1', 'label' => ['Yes', 'Oui']]],
        ]);

        $vaccine = collect($parsed['variables'])->firstWhere('name', 'vaccine');
        $this->assertSame('select_one', $vaccine['base_type']);
        $this->assertSame('yn', $vaccine['list_name']);
        $this->assertSame('Vacciné ?', $vaccine['label']);
        $this->assertTrue($vaccine['required']);
        $this->assertSame('enfants', $vaccine['repeat_name']);
        $this->assertSame('Oui', $parsed['choices'][0]['label']);
    }
}
