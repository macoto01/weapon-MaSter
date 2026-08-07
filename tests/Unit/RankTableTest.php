<?php

namespace Tests\Unit;

use App\Domain\Rank\RankTable;
use PHPUnit\Framework\TestCase;

/**
 * Step 8-1の「完了の定義」(保有ポイントに応じて正しい階級が算出される)を検証する。
 */
class RankTableTest extends TestCase
{
    public function test_zero_points_is_bronze(): void
    {
        $result = (new RankTable)->resolve(0);

        $this->assertSame('bronze', $result['tier_key']);
        $this->assertSame(0, $result['points_in_tier']);
        $this->assertSame(5, $result['points_to_next']);
    }

    public function test_points_at_tier_boundary_promotes_to_next_tier(): void
    {
        // ブロンズの必要pt(5)ちょうどでシルバーに昇格する
        $result = (new RankTable)->resolve(5);

        $this->assertSame('silver', $result['tier_key']);
        $this->assertSame(0, $result['points_in_tier']);
        $this->assertSame(8, $result['points_to_next']);
    }

    public function test_points_within_a_tier_do_not_promote(): void
    {
        $result = (new RankTable)->resolve(4);

        $this->assertSame('bronze', $result['tier_key']);
        $this->assertSame(4, $result['points_in_tier']);
        $this->assertSame(1, $result['points_to_next']);
    }

    public function test_challenger_is_the_top_tier_with_no_further_promotion(): void
    {
        // 5+8+12+17+23+30+40 = 135 でチャレンジャーに到達
        $result = (new RankTable)->resolve(135);

        $this->assertSame('challenger', $result['tier_key']);
        $this->assertNull($result['points_to_next']);

        $beyond = (new RankTable)->resolve(500);
        $this->assertSame('challenger', $beyond['tier_key']);
        $this->assertNull($beyond['points_to_next']);
    }
}
