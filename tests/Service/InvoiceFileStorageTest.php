<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Xhubio\InvoiceApiXhub\Service\InvoiceFileStorage;

/**
 * Unit tests for the Flysystem wrapper that stores Invoice-api.xhub artefacts.
 *
 * Most happy-path tests use the in-memory adapter (real Flysystem behaviour);
 * the failure paths use a PHPUnit mock to force exceptions.
 */
final class InvoiceFileStorageTest extends TestCase
{
    private const ORDER_ID = 'order-abc';

    private Filesystem $fs;

    private InvoiceFileStorage $storage;

    protected function setUp(): void
    {
        $this->fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $this->storage = new InvoiceFileStorage($this->fs);
    }

    public function testWriteStoresBytesUnderInvoiceApiXhubPrefixAndReturnsRelativePath(): void
    {
        $path = $this->storage->write(self::ORDER_ID, 'INV-1.pdf', 'PDFBYTES');

        self::assertSame('invoice-api-xhub/' . self::ORDER_ID . '/INV-1.pdf', $path);
        self::assertSame('PDFBYTES', $this->fs->read($path));
    }

    public function testReadReturnsNullWhenFileDoesNotExist(): void
    {
        self::assertNull($this->storage->read('invoice-api-xhub/missing/foo.pdf'));
    }

    public function testReadReturnsBytesForExistingFile(): void
    {
        $this->fs->write('invoice-api-xhub/' . self::ORDER_ID . '/x.xml', '<x/>');

        self::assertSame('<x/>', $this->storage->read('invoice-api-xhub/' . self::ORDER_ID . '/x.xml'));
    }

    public function testExistsReturnsFalseWhenMissingAndTrueAfterWrite(): void
    {
        $path = 'invoice-api-xhub/' . self::ORDER_ID . '/y.pdf';
        self::assertFalse($this->storage->exists($path));

        $this->storage->write(self::ORDER_ID, 'y.pdf', 'B');

        self::assertTrue($this->storage->exists($path));
    }

    public function testDeleteForOrderRemovesAllFilesUnderOrderDirectory(): void
    {
        $this->storage->write(self::ORDER_ID, 'a.pdf', 'A');
        $this->storage->write(self::ORDER_ID, 'b.xml', 'B');

        $this->storage->deleteForOrder(self::ORDER_ID);

        self::assertFalse($this->fs->fileExists('invoice-api-xhub/' . self::ORDER_ID . '/a.pdf'));
        self::assertFalse($this->fs->fileExists('invoice-api-xhub/' . self::ORDER_ID . '/b.xml'));
    }

    public function testDeleteForOrderIsIdempotentWhenDirectoryMissing(): void
    {
        // Should not throw — directoryExists returns false → early return.
        $this->storage->deleteForOrder('never-existed');
        $this->addToAssertionCount(1);
    }

    public function testListForOrderReturnsEmptyArrayWhenDirectoryMissing(): void
    {
        self::assertSame([], $this->storage->listForOrder('not-here'));
    }

    public function testListForOrderReturnsOnlyFiles(): void
    {
        $this->storage->write(self::ORDER_ID, 'a.pdf', 'A');
        $this->storage->write(self::ORDER_ID, 'b.xml', 'B');

        $paths = $this->storage->listForOrder(self::ORDER_ID);

        sort($paths);
        self::assertSame([
            'invoice-api-xhub/' . self::ORDER_ID . '/a.pdf',
            'invoice-api-xhub/' . self::ORDER_ID . '/b.xml',
        ], $paths);
    }

    public function testPathForBuildsCanonicalPrefixedPath(): void
    {
        self::assertSame(
            'invoice-api-xhub/x/y.pdf',
            $this->storage->pathFor('x', 'y.pdf'),
        );
    }

    public function testWriteRethrowsFilesystemErrorAsRuntimeException(): void
    {
        $broken = $this->createMock(FilesystemOperator::class);
        $broken->method('write')->willThrowException(
            new class('disk full') extends \RuntimeException implements FilesystemException {},
        );
        $storage = new InvoiceFileStorage($broken);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write invoice file');

        $storage->write(self::ORDER_ID, 'x.pdf', 'AAA');
    }

    public function testReadRethrowsFilesystemErrorAsRuntimeException(): void
    {
        $broken = $this->createMock(FilesystemOperator::class);
        $broken->method('fileExists')->willReturn(true);
        $broken->method('read')->willThrowException(
            new class('io error') extends \RuntimeException implements FilesystemException {},
        );
        $storage = new InvoiceFileStorage($broken);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read invoice file');

        $storage->read('invoice-api-xhub/x/y.pdf');
    }

    public function testExistsSwallowsFilesystemException(): void
    {
        $broken = $this->createMock(FilesystemOperator::class);
        $broken->method('fileExists')->willThrowException(
            new class('boom') extends \RuntimeException implements FilesystemException {},
        );
        $storage = new InvoiceFileStorage($broken);

        self::assertFalse($storage->exists('any/path'));
    }

    public function testDeleteForOrderRethrowsFilesystemException(): void
    {
        $broken = $this->createMock(FilesystemOperator::class);
        $broken->method('directoryExists')->willReturn(true);
        $broken->method('deleteDirectory')->willThrowException(
            new class('locked') extends \RuntimeException implements FilesystemException {},
        );
        $storage = new InvoiceFileStorage($broken);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete invoice directory');

        $storage->deleteForOrder(self::ORDER_ID);
    }

    public function testListForOrderRethrowsFilesystemException(): void
    {
        $broken = $this->createMock(FilesystemOperator::class);
        $broken->method('directoryExists')->willReturn(true);
        $broken->method('listContents')->willThrowException(
            new class('cannot list') extends \RuntimeException implements FilesystemException {},
        );
        $storage = new InvoiceFileStorage($broken);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to list invoice directory');

        $storage->listForOrder(self::ORDER_ID);
    }
}
