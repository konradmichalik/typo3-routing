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

namespace KonradMichalik\Typo3Routing\Routing;

use KonradMichalik\Typo3Routing\Http\HttpProblemException;
use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use RuntimeException;

use function ctype_digit;
use function ini_get;
use function ini_parse_quantity;
use function sprintf;

/**
 * UploadedFileArgument.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class UploadedFileArgument
{
    /**
     * PHP discards the whole body of a POST beyond `post_max_size`, files and fields alike, so an
     * oversized upload would otherwise look merely absent.
     *
     * @param int $postMaxSize in bytes, 0 or less for unlimited
     *
     * @throws HttpProblemException 413 when PHP discarded the body
     */
    public static function assertBodyWithinPostMaxSize(ServerRequestInterface $request, int $postMaxSize): void
    {
        if ($postMaxSize <= 0 || 'POST' !== $request->getMethod() || [] !== $request->getUploadedFiles()) {
            return;
        }

        $contentLength = $request->getHeaderLine('Content-Length');
        if (ctype_digit($contentLength) && (int) $contentLength > $postMaxSize) {
            throw new HttpProblemException(413, 'Request body exceeds the server\'s post_max_size');
        }
    }

    public static function postMaxSize(): int
    {
        return ini_parse_quantity((string) ini_get('post_max_size'));
    }

    /**
     * The single uploaded file under a wire name, or null when there is none. `UPLOAD_ERR_NO_FILE` is
     * how PHP reports an empty file field, so it counts as absent rather than as a failed upload.
     *
     * @param array<mixed> $files the request's getUploadedFiles()
     *
     * @throws ArgumentResolutionException for several files under one name, or a partial upload
     * @throws HttpProblemException        413 for a size limit
     * @throws RuntimeException            for a failure on the server side
     */
    public static function find(array $files, string $name): ?UploadedFileInterface
    {
        $file = $files[$name] ?? null;
        if (null === $file) {
            return null;
        }
        if (!$file instanceof UploadedFileInterface) {
            throw ArgumentResolutionException::invalidValue($name, 6402317598);
        }

        $error = $file->getError();

        return match ($error) {
            \UPLOAD_ERR_OK => $file,
            \UPLOAD_ERR_NO_FILE => null,
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => throw new HttpProblemException(413, sprintf('Uploaded file too large for parameter: %s', $name)),
            // A server fault the client cannot fix: TYPO3's exception handling logs it for the admin.
            \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION => throw new RuntimeException(sprintf('Uploaded file for parameter "%s" could not be stored (PHP upload error %d).', $name, $error), 1750000067),
            default => throw new ArgumentResolutionException(sprintf('Upload failed for parameter: %s', $name), 4127730619),
        };
    }
}
