<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_routing" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\Typo3Routing\Http\HttpProblemException;
use KonradMichalik\Typo3Routing\Routing\{ArgumentResolutionException, UploadedFileArgument};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Stream, UploadedFile};

/**
 * UploadedFileArgumentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(UploadedFileArgument::class)]
final class UploadedFileArgumentTest extends TestCase
{
    #[Test]
    public function returnsASuccessfulUpload(): void
    {
        $file = $this->file();

        self::assertSame($file, UploadedFileArgument::find(['file' => $file], 'file'));
    }

    #[Test]
    public function treatsAnAbsentFieldAsNoFile(): void
    {
        self::assertNull(UploadedFileArgument::find([], 'file'));
    }

    #[Test]
    public function treatsAnEmptyFileFieldAsNoFile(): void
    {
        self::assertNull(UploadedFileArgument::find(['file' => $this->file(\UPLOAD_ERR_NO_FILE)], 'file'));
    }

    #[Test]
    public function rejectsAFailedUpload(): void
    {
        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Upload failed for parameter: file');

        UploadedFileArgument::find(['file' => $this->file(\UPLOAD_ERR_PARTIAL)], 'file');
    }

    #[Test]
    #[DataProvider('uploadErrorStatuses')]
    public function mapsAnUploadErrorToItsStatus(int $error, int $status, string $detail): void
    {
        try {
            UploadedFileArgument::find(['file' => $this->file($error)], 'file');
            self::fail('Expected an HttpProblemException.');
        } catch (HttpProblemException $exception) {
            self::assertSame($status, $exception->status);
            self::assertSame($detail, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function uploadErrorStatuses(): iterable
    {
        yield 'php.ini size limit' => [\UPLOAD_ERR_INI_SIZE, 413, 'Uploaded file too large for parameter: file'];
        yield 'form size limit' => [\UPLOAD_ERR_FORM_SIZE, 413, 'Uploaded file too large for parameter: file'];
        yield 'no temporary directory' => [\UPLOAD_ERR_NO_TMP_DIR, 500, 'Upload could not be stored for parameter: file'];
        yield 'write failure' => [\UPLOAD_ERR_CANT_WRITE, 500, 'Upload could not be stored for parameter: file'];
        yield 'stopped by an extension' => [\UPLOAD_ERR_EXTENSION, 500, 'Upload could not be stored for parameter: file'];
    }

    #[Test]
    public function rejectsSeveralFilesUnderOneName(): void
    {
        $this->expectException(ArgumentResolutionException::class);
        $this->expectExceptionMessage('Invalid value for parameter: file');

        UploadedFileArgument::find(['file' => [$this->file(), $this->file()]], 'file');
    }

    private function file(int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(new Stream('php://temp', 'wb+'), 0, $error, 'a.txt', 'text/plain');
    }
}
