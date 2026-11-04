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

namespace KonradMichalik\Typo3Routing\Tests\Functional\Middleware;

use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, UploadedFileInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\{Response, ServerRequest, Stream, UploadedFile};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function strlen;

/**
 * UploadedFileArgumentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class UploadedFileArgumentTest extends FunctionalTestCase
{
    use JsonAssertions;

    protected bool $initializeDatabase = false;

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    #[Test]
    public function bindsAMultipartUploadAndItsFormFieldsToTheController(): void
    {
        $response = $this->process($this->multipart(['title' => 'Report'], ['file' => $this->file('report.txt', 'hello')]));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"file":"report.txt","content":"hello","title":"Report","thumb":null}',
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function treatsAnEmptyOptionalFileFieldAsAbsent(): void
    {
        $files = ['file' => $this->file('report.txt', 'hello'), 'thumb' => $this->file('', '', \UPLOAD_ERR_NO_FILE)];

        $response = $this->process($this->multipart(['title' => 'Report'], $files));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'thumb', null);
    }

    #[Test]
    public function answersBadRequestForAMissingRequiredFile(): void
    {
        $response = $this->process($this->multipart(['title' => 'Report'], []));

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Missing required parameter: file');
    }

    #[Test]
    public function answersContentTooLargeNamingTheParameterOfAnOversizedUpload(): void
    {
        $response = $this->process($this->multipart(['title' => 'Report'], ['file' => $this->file('big.bin', '', \UPLOAD_ERR_INI_SIZE)]));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Uploaded file too large for parameter: file');
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        return $this->get(RouteDispatcher::class)->process($request, $this->handler());
    }

    /**
     * The shape TYPO3's ServerRequestFactory produces for a multipart POST: form fields in the parsed
     * body, files in getUploadedFiles(), and an empty raw body (PHP never exposes multipart input).
     *
     * @param array<string, string>                $fields
     * @param array<string, UploadedFileInterface> $files
     */
    private function multipart(array $fields, array $files): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => 'https://example.com/']],
        ]);

        return (new RequestBuilder('POST', 'https://example.com/api/example/upload'))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----routing')
            ->withParsedBody($fields)
            ->build()
            ->withUploadedFiles($files);
    }

    private function file(string $name, string $content, int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($content);
        $stream->rewind();

        return new UploadedFile($stream, strlen($content), $error, $name, 'text/plain');
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 404);
            }
        };
    }
}
