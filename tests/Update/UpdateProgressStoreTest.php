<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Update;

use Lebensbaum\ContaoSystemInfoBundle\Update\UpdateProgressStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdateProgressStoreTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/contao-system-info-progress-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testWritesAndReadsProgressState(): void
    {
        $store = new UpdateProgressStore($this->projectDir);
        $requestId = '0123456789abcdef0123456789abcdef';

        $written = $store->write(
            $requestId,
            'composer',
            'running',
            "Composer-Update\n wird ausgeführt.",
            1789639200,
        );

        self::assertSame([
            'request_id' => $requestId,
            'phase' => 'composer',
            'status' => 'running',
            'message' => 'Composer-Update wird ausgeführt.',
            'updated_at' => 1789639200,
        ], $written);
        self::assertSame($written, $store->read($requestId));

        $path = $this->projectDir.'/var/domain-manager/update-progress/'.$requestId.'.json';
        self::assertFileExists($path);
    }

    public function testWritesAndReadsCompletedResult(): void
    {
        $store = new UpdateProgressStore($this->projectDir);
        $requestId = 'ffffffffffffffffffffffffffffffff';
        $result = [
            'id' => $requestId,
            'status' => 'completed',
            'current_contao_version' => '5.7.12',
            'target_contao_version' => '5.7.13',
            'installed_contao_version' => '5.7.13',
            'database_migrated' => true,
            'composer_lock_sha256' => str_repeat('a', 64),
        ];

        $written = $store->write(
            $requestId,
            'completed',
            'success',
            'Update erfolgreich abgeschlossen.',
            1789646898,
            $result,
        );

        self::assertSame($result, $written['result']);
        self::assertSame($written, $store->read($requestId));
    }

    public function testWriteReplacesPreviousStateForSameRequest(): void
    {
        $store = new UpdateProgressStore($this->projectDir);
        $requestId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $store->write($requestId, 'preflight', 'running', 'Sicherheitsprüfung läuft.', 100);
        $store->write($requestId, 'verify', 'success', 'Zielversion wurde verifiziert.', 200);

        self::assertSame([
            'request_id' => $requestId,
            'phase' => 'verify',
            'status' => 'success',
            'message' => 'Zielversion wurde verifiziert.',
            'updated_at' => 200,
        ], $store->read($requestId));
    }

    public function testReadReturnsNullForUnknownRequest(): void
    {
        $store = new UpdateProgressStore($this->projectDir);

        self::assertNull($store->read('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
    }

    public function testRemoveDeletesStoredState(): void
    {
        $store = new UpdateProgressStore($this->projectDir);
        $requestId = 'cccccccccccccccccccccccccccccccc';

        $store->write($requestId, 'completed', 'success', 'Update abgeschlossen.', 300);
        $store->remove($requestId);

        self::assertNull($store->read($requestId));
    }

    public function testRejectsInvalidRequestId(): void
    {
        $store = new UpdateProgressStore($this->projectDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Request-ID');

        $store->write('not-valid', 'composer', 'running', 'Composer läuft.');
    }

    public function testRejectsUnknownPhase(): void
    {
        $store = new UpdateProgressStore($this->projectDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Phase');

        $store->write(
            'dddddddddddddddddddddddddddddddd',
            'backup',
            'running',
            'Backup läuft.',
        );
    }

    public function testRejectsUnknownStatus(): void
    {
        $store = new UpdateProgressStore($this->projectDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Status');

        $store->write(
            'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'migration',
            'finished',
            'Migration abgeschlossen.',
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
