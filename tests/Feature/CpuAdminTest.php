<?php

namespace Tests\Feature;

use App\Models\CpuPattern;
use Database\Seeders\CpuPatternSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理ツール(tool/cpu)でのCPU対戦相手データの作成・更新・削除と、
 * layoutのグリッド範囲外・重なりバリデーションを検証する。
 */
class CpuAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CpuPatternSeeder::class);
    }

    public function test_admin_can_create_update_and_delete_a_cpu_pattern(): void
    {
        $layout = json_encode([
            ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        ]);

        $this->post(route('tool.cpu.store'), [
            'key' => 'cpu_test_rookie',
            'name' => 'CPU-テスト',
            'description' => 'テスト用の説明',
            'difficulty_tier' => 1,
            'layout' => $layout,
        ])->assertRedirect(route('tool.cpu.index'));

        $this->assertDatabaseHas('cpu_patterns', ['key' => 'cpu_test_rookie', 'name' => 'CPU-テスト']);

        $updatedLayout = json_encode([
            ['item_type' => 'weapon', 'item_key' => 'mace', 'x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
        ]);

        $this->put(route('tool.cpu.update', 'cpu_test_rookie'), [
            'name' => 'CPU-テスト改',
            'description' => '更新後の説明',
            'difficulty_tier' => 3,
            'layout' => $updatedLayout,
        ])->assertRedirect(route('tool.cpu.index'));

        $this->assertDatabaseHas('cpu_patterns', [
            'key' => 'cpu_test_rookie',
            'name' => 'CPU-テスト改',
            'difficulty_tier' => 3,
        ]);

        $this->delete(route('tool.cpu.destroy', 'cpu_test_rookie'))
            ->assertRedirect(route('tool.cpu.index'));

        $this->assertDatabaseMissing('cpu_patterns', ['key' => 'cpu_test_rookie']);
    }

    public function test_out_of_bounds_layout_is_rejected(): void
    {
        $layout = json_encode([
            ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 7, 'y' => 6, 'width' => 2, 'height' => 2],
        ]);

        $this->post(route('tool.cpu.store'), [
            'key' => 'cpu_test_invalid',
            'name' => 'CPU-範囲外',
            'description' => 'テスト',
            'difficulty_tier' => 1,
            'layout' => $layout,
        ])->assertSessionHasErrors('layout');

        $this->assertDatabaseMissing('cpu_patterns', ['key' => 'cpu_test_invalid']);
    }

    public function test_overlapping_layout_is_rejected(): void
    {
        $layout = json_encode([
            ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            ['item_type' => 'weapon', 'item_key' => 'dagger', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        ]);

        $this->post(route('tool.cpu.store'), [
            'key' => 'cpu_test_overlap',
            'name' => 'CPU-重なり',
            'description' => 'テスト',
            'difficulty_tier' => 1,
            'layout' => $layout,
        ])->assertSessionHasErrors('layout');

        $this->assertDatabaseMissing('cpu_patterns', ['key' => 'cpu_test_overlap']);
    }

    public function test_unknown_item_key_is_rejected(): void
    {
        $layout = json_encode([
            ['item_type' => 'weapon', 'item_key' => 'does_not_exist', 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        ]);

        $this->post(route('tool.cpu.store'), [
            'key' => 'cpu_test_unknown',
            'name' => 'CPU-不明武器',
            'description' => 'テスト',
            'difficulty_tier' => 1,
            'layout' => $layout,
        ])->assertSessionHasErrors('layout');

        $this->assertDatabaseMissing('cpu_patterns', ['key' => 'cpu_test_unknown']);
    }

    public function test_deleting_currently_used_pattern_does_not_break_existing_patterns(): void
    {
        $before = CpuPattern::count();

        $this->delete(route('tool.cpu.destroy', 'cpu_lone_sword'))->assertRedirect();

        $this->assertSame($before - 1, CpuPattern::count());
        $this->assertDatabaseMissing('cpu_patterns', ['key' => 'cpu_lone_sword']);
    }
}
