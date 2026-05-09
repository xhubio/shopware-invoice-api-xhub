<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Manages invoice files at:
 *   <shopware-root>/files/invoice-api-xhub/<orderId>/<filename>
 *
 * Uses the private Flysystem (`shopware.filesystem.private`) so files are not
 * web-accessible. The Shopware `private` filesystem is rooted at `files/` by
 * default — paths we hand it are relative to that root, hence the
 * `invoice-api-xhub/...` prefix.
 *
 * All write/read/delete failures surface as \RuntimeException with a
 * descriptive message; no silent fallthroughs.
 */
final class InvoiceFileStorage
{
    private const STORAGE_PREFIX = 'invoice-api-xhub';

    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    /**
     * Write file bytes for an order. Returns the relative path
     * (`invoice-api-xhub/<orderId>/<filename>`) suitable for storing in
     * order custom fields.
     */
    public function write(string $orderId, string $filename, string $bytes): string
    {
        $path = $this->pathFor($orderId, $filename);

        try {
            $this->filesystem->write($path, $bytes);
        } catch (FilesystemException $e) {
            throw new \RuntimeException(
                sprintf('Failed to write invoice file "%s": %s', $path, $e->getMessage()),
                0,
                $e,
            );
        }

        return $path;
    }

    /**
     * Read file bytes by relative path. Returns null if the file does not
     * exist; throws on permission/IO errors.
     */
    public function read(string $relativePath): ?string
    {
        try {
            if (!$this->filesystem->fileExists($relativePath)) {
                return null;
            }

            return $this->filesystem->read($relativePath);
        } catch (FilesystemException $e) {
            throw new \RuntimeException(
                sprintf('Failed to read invoice file "%s": %s', $relativePath, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Cheap existence probe; never throws (returns false on any error).
     */
    public function exists(string $relativePath): bool
    {
        try {
            return $this->filesystem->fileExists($relativePath);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Delete the entire `invoice-api-xhub/<orderId>/` directory and all files
     * within. Idempotent — missing directory is not an error.
     */
    public function deleteForOrder(string $orderId): void
    {
        $directory = self::STORAGE_PREFIX . '/' . $orderId;

        try {
            if (!$this->filesystem->directoryExists($directory)) {
                return;
            }
            $this->filesystem->deleteDirectory($directory);
        } catch (FilesystemException $e) {
            throw new \RuntimeException(
                sprintf('Failed to delete invoice directory "%s": %s', $directory, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Build the canonical relative path for an order/filename pair.
     */
    public function pathFor(string $orderId, string $filename): string
    {
        return self::STORAGE_PREFIX . '/' . $orderId . '/' . $filename;
    }

    /**
     * List every relative file path under a given order's directory. Useful
     * for admin UIs that show all generated artefacts (PDF + XML + log).
     *
     * @return list<string>
     */
    public function listForOrder(string $orderId): array
    {
        $directory = self::STORAGE_PREFIX . '/' . $orderId;

        try {
            if (!$this->filesystem->directoryExists($directory)) {
                return [];
            }

            $listing = $this->filesystem->listContents($directory, false);
            $paths   = [];
            foreach ($listing as $item) {
                if ($item->isFile()) {
                    $paths[] = $item->path();
                }
            }

            return $paths;
        } catch (FilesystemException $e) {
            throw new \RuntimeException(
                sprintf('Failed to list invoice directory "%s": %s', $directory, $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
