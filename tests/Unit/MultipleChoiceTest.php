<?php

namespace Tests\Unit;

use App\Services\Quality\Expression\ExpressionEvaluator;
use App\Support\MultipleChoice;
use PHPUnit\Framework\TestCase;

/** Questions à choix multiple (campagnes de vaccination : « rr-bj opv-bj », code « opv - bj » dans la liste). */
class MultipleChoiceTest extends TestCase
{
    private const CAMPAIGNS = ['rr-bj' => 'Rougeole/Rubéole', 'opv - bj' => 'Oral Polio', 'other-bj' => 'Other'];

    public function test_one_or_several_codes_match_the_choice_list(): void
    {
        $this->assertSame(['rr-bj', 'opv - bj'], MultipleChoice::split('rr-bj opv-bj', self::CAMPAIGNS));
        $this->assertSame(['rr-bj', 'opv - bj'], MultipleChoice::split('rr-bj opv - bj', self::CAMPAIGNS));
        $this->assertSame(['opv - bj'], MultipleChoice::split('opv-bj', self::CAMPAIGNS));
        $this->assertSame(['rr-bj'], MultipleChoice::split(' rr-bj ', self::CAMPAIGNS));
        $this->assertSame(['rr-bj', 'xx'], MultipleChoice::split('rr-bj xx', self::CAMPAIGNS));
        $this->assertSame([], MultipleChoice::split('', self::CAMPAIGNS));
    }

    public function test_checked_boxes_become_an_odk_value(): void
    {
        $this->assertSame('rr-bj opv-bj', MultipleChoice::join(['', 'rr-bj', 'opv - bj']));
        $this->assertSame('', MultipleChoice::join(['']));
    }

    public function test_gps_coordinates_are_not_merged(): void
    {
        $this->assertSame(['6.5', '-2.3', '0', '5'], ExpressionEvaluator::splitMultiple('6.5 -2.3 0 5'));
    }
}
