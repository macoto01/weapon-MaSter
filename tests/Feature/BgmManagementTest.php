<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 管理ツール(tool/bgm)でのBGM追加・画面への割当・削除と、
 * ゲーム側の読み取り専用API(/api/bgm/active)の挙動を検証する。
 * database/data/bgm.json・public/audio/bgm/を直接読み書きするため、
 * テスト前後で内容を退避・復元しリポジトリへの副作用を残さない。
 */
class BgmManagementTest extends TestCase
{
    private string $bgmJsonPath;

    private string $bgmJsonOriginal;

    private array $filesBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bgmJsonPath = database_path('data/bgm.json');
        $this->bgmJsonOriginal = file_get_contents($this->bgmJsonPath);
        $this->filesBefore = glob(public_path('audio/bgm/*'));
    }

    protected function tearDown(): void
    {
        file_put_contents($this->bgmJsonPath, $this->bgmJsonOriginal);
        $filesAfter = glob(public_path('audio/bgm/*'));
        foreach (array_diff($filesAfter, $this->filesBefore) as $leftover) {
            @unlink($leftover);
        }
        parent::tearDown();
    }

    public function test_admin_can_add_assign_and_delete_a_bgm_track(): void
    {
        $file = UploadedFile::fake()->create('test-theme.wav', 50, 'audio/wav');

        $this->post(route('tool.bgm.store'), [
            'name' => 'テスト用テーマ',
            'file' => $file,
        ])->assertRedirect(route('tool.bgm.index'));

        $data = json_decode(file_get_contents($this->bgmJsonPath), true);
        $track = collect($data['tracks'])->firstWhere('name', 'テスト用テーマ');
        $this->assertNotNull($track);
        $this->assertFileExists(public_path('audio/bgm/'.$track['file']));

        $this->post(route('tool.bgm.assign'), [
            'screen' => 'battle',
            'key' => $track['key'],
        ])->assertRedirect(route('tool.bgm.index'));

        $active = $this->getJson('/api/bgm/active?screen=battle')->assertOk()->json();
        $this->assertSame(asset('audio/bgm/'.$track['file']), $active['url']);

        $this->delete(route('tool.bgm.destroy', $track['key']))
            ->assertRedirect(route('tool.bgm.index'));

        $this->assertFileDoesNotExist(public_path('audio/bgm/'.$track['file']));

        $afterDelete = $this->getJson('/api/bgm/active?screen=battle')->assertOk()->json();
        $this->assertNull($afterDelete['url']);
    }

    public function test_active_endpoint_returns_null_when_no_track_assigned_to_screen(): void
    {
        $response = $this->getJson('/api/bgm/active?screen=title')->assertOk();
        $this->assertNull($response->json('url'));
    }
}
