<?php

declare(strict_types=1);

use App\Services\AlbumLayoutService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class AlbumLayoutServiceTest extends TestCase
{
    private Database $db;
    private AlbumLayoutService $layouts;

    protected function setUp(): void
    {
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->db->execute("INSERT INTO albums (id,title,slug,category_id) VALUES (81,'Layout','layout',1), (82,'Other','other',1)");
        $this->layouts = new AlbumLayoutService($this->db);
    }

    private function layout(): array
    {
        return $this->layouts->normalize([
            'blocks' => [
                ['id' => 'lead', 'type' => 'photo', 'images' => [11]],
                ['id' => 'copy', 'type' => 'text', 'text' => '<script>alert(1)</script>'],
                ['id' => 'grid', 'type' => 'grid', 'images' => [12,13], 'columns' => 2],
            ],
            'include_remaining' => true,
        ], [11,12,13]);
    }

    public function testOldInstallationUsesDefaultWithoutCreatingTables(): void
    {
        $this->db->pdo()->exec('DROP TABLE album_layout_versions; DROP TABLE album_layouts');
        self::assertNull($this->layouts->state(81)['published']);
        self::assertFalse($this->db->query("SELECT name FROM sqlite_master WHERE name='album_layouts'")->fetchColumn());
        $this->layouts->initialize(81);
        $this->layouts->initialize(81);
        self::assertSame(0, $this->layouts->state(81)['revision']);
    }

    public function testDraftIsPrivateUntilPublishedAndDefaultKeepsTheDraft(): void
    {
        $this->layouts->initialize(81);
        $layout = $this->layout();
        self::assertSame(1, $this->layouts->save(81, 0, $layout, 'draft'));
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame($layout, $this->layouts->state(81)['draft']);
        $this->layouts->save(81, 1, $layout, 'publish');
        self::assertSame($layout, $this->layouts->state(81)['published']);
        $draft = $layout;
        $draft['blocks'][1]['text'] = 'Unpublished edit';
        $this->layouts->save(81, 2, $draft, 'draft');
        self::assertSame($layout, $this->layouts->state(81)['published']);
        $this->layouts->save(81, 3, null, 'default');
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame($draft, $this->layouts->state(81)['draft']);
    }

    public function testStaleEditorCannotOverwritePublishedWork(): void
    {
        $this->layouts->initialize(81);
        $this->layouts->save(81, 0, $this->layout(), 'publish');
        try {
            $this->layouts->save(81, 0, null, 'default');
            self::fail('Stale revision accepted');
        } catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode());
        }
        self::assertSame($this->layout(), $this->layouts->state(81)['published']);
    }

    public function testVersionsPreserveExistingLayoutsAndRestoreOnlyTheDraft(): void
    {
        $layout = $this->layout();
        $this->layouts->initialize(81);
        $initial = json_encode($layout, JSON_THROW_ON_ERROR);
        $this->db->execute('UPDATE album_layouts SET revision = 4, draft_json = ?, published_json = ? WHERE album_id = 81', [$initial, $initial]);
        // Simulate an upgrade from an earlier prerelease at the same migration checkpoint.
        $this->db->pdo()->exec('DROP TABLE album_layout_versions');
        $this->layouts->initialize(81);
        $new = $layout;
        $new['blocks'][1]['text'] = 'New layout';
        $this->layouts->save(81, 4, $new, 'publish');

        self::assertSame([5, 4], array_column($this->layouts->history(81), 'revision'));
        self::assertNull($this->layouts->snapshot(82, 4, 'publish'));
        $snapshot = $this->layouts->snapshot(81, 4, 'publish');
        self::assertSame($layout, $snapshot);
        $restored = $this->layouts->normalize($this->layouts->removeMissingPhotos($snapshot, [11, 12]), [11, 12]);
        $this->layouts->save(81, 5, $restored, 'restore');
        self::assertSame($restored, $this->layouts->state(81)['draft']);
        self::assertSame($new, $this->layouts->state(81)['published']);
        self::assertSame([12], $restored['blocks'][2]['images']);
        self::assertSame('restore', $this->layouts->history(81)[0]['kind']);
    }

    public function testVersionRetentionKeepsTwentySnapshots(): void
    {
        $this->layouts->initialize(81);
        for ($i = 0; $i < 22; ++$i) {
            $this->layouts->save(81, $i, $this->layout(), 'draft');
        }

        self::assertSame(range(22, 3), array_column($this->layouts->history(81), 'revision'));
        self::assertNull($this->layouts->snapshot(81, 2, 'draft'));
    }

    public function testRowSplitSurvivesSaveAndOnlyAppliesToPhotoPairs(): void
    {
        $input = ['blocks' => [[
            'id' => 'grid', 'type' => 'grid', 'images' => [11, 12, 13, 14, 15],
            'columns' => 3, 'rows' => [
                0 => ['split' => 'equal', 'fill' => false],
                1 => ['split' => '30-70', 'fill' => true],
            ],
        ]]];
        $layout = $this->layouts->normalize($input, [11, 12, 13, 14, 15]);
        $this->layouts->initialize(81);
        $this->layouts->save(81, 0, $layout, 'publish');
        self::assertSame([1 => ['split' => '30-70', 'fill' => true]],
            $this->layouts->state(81)['published']['blocks'][0]['rows']);
        $input['blocks'][0]['rows'][1]['split'] = 'arbitrary';
        $this->expectException(InvalidArgumentException::class);
        $this->layouts->normalize($input, [11, 12, 13, 14, 15]);
    }

    public function testUnauthorizedImageAndInvalidCssAreRejected(): void
    {
        foreach ([
            ['id'=>'bad','type'=>'photo','images'=>[999]],
            ['id'=>'bad','type'=>'photo','images'=>['11']],
            ['id'=>'bad','type'=>'grid','width'=>'100%;color:red'],
            ['id'=>'bad','type'=>'grid','columns'=>0],
            ['id'=>'bad','type'=>'html','text'=>'<iframe>'],
        ] as $block) {
            try {
                $this->layouts->normalize(['blocks'=>[$block]], [11,12]);
                self::fail('Invalid layout accepted');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testDeletedPhotosAreSkippedAndNewUploadsJoinRemainingGrid(): void
    {
        $images = [['id'=>11], ['id'=>12], ['id'=>14]];
        $resolved = $this->layouts->resolve($this->layout(), $images);
        self::assertSame([12], array_column($resolved['blocks'][2]['photos'], 'id'));
        self::assertSame([14], array_column($resolved['remaining'], 'id'));
        $layout = $this->layout();
        $layout['include_remaining'] = false;
        self::assertSame([], $this->layouts->resolve($layout, $images)['remaining']);
    }

    public function testAlbumDeletionRemovesItsLayoutsOnly(): void
    {
        foreach ([81,82] as $id) {
            $this->layouts->initialize($id);
            $this->layouts->save($id, 0, $this->layout(), 'publish');
        }
        $this->db->execute('DELETE FROM albums WHERE id=81');
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame($this->layout(), $this->layouts->state(82)['published']);
    }
}
