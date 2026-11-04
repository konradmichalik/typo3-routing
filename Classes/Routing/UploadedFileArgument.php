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
use Psr\Http\Message\UploadedFileInterface;

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
     * The single uploaded file under a wire name, or null when there is none. `UPLOAD_ERR_NO_FILE` is
     * how PHP reports an empty file field, so it counts as absent rather than as a failed upload.
     *
     * @param array<mixed> $files the request's getUploadedFiles()
     *
     * @throws ArgumentResolutionException for several files under one name, or a partial upload
     * @throws HttpProblemException        413 for a size limit, 500 for a failure on the server side
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

        return match ($file->getError()) {
            \UPLOAD_ERR_OK => $file,
            \UPLOAD_ERR_NO_FILE => null,
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => throw new HttpProblemException(413, sprintf('Uploaded file too large for parameter: %s', $name)),
            // The client cannot fix these, and the detail stays generic so no server path leaks.
            \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION => throw new HttpProblemException(500, sprintf('Upload could not be stored for parameter: %s', $name)),
            default => throw new ArgumentResolutionException(sprintf('Upload failed for parameter: %s', $name), 4127730619),
        };
    }
}
