<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\BadRequestException;
use dcardenasl\Ci4ApiCore\Http\ApiRequest;
use dcardenasl\Ci4ApiCore\Support\RequestDataCollector;

final class RequestDataCollectorTest extends CIUnitTestCase
{
    public function testCollectMergesGetPostRawAndParamsForNonJson(): void
    {
        $request = $this->createMock(ApiRequest::class);
        $request->method('getHeaderLine')->with('Content-Type')->willReturn('application/x-www-form-urlencoded');
        $request->method('getFiles')->willReturn([]);
        $request->method('getBody')->willReturn('');
        $request->method('getRawInput')->willReturn(['raw' => 'value']);
        $request->method('getGet')->willReturn(['q' => '1']);
        $request->method('getPost')->willReturn(['post' => 'ok']);

        $collector = new RequestDataCollector();
        $result = $collector->collect($request, ['extra' => 'yes']);

        $this->assertSame('1', $result['q']);
        $this->assertSame('value', $result['raw']);
        $this->assertSame('ok', $result['post']);
        $this->assertSame('yes', $result['extra']);
    }

    public function testCollectAvoidsRawBodyParsingForMultipart(): void
    {
        $request = $this->createMock(ApiRequest::class);
        $request->method('getHeaderLine')->with('Content-Type')->willReturn('multipart/form-data; boundary=abc');
        $request->method('getFiles')->willReturn(['file' => ['name' => 'demo.txt']]);
        $request->expects($this->never())->method('getBody');
        $request->expects($this->never())->method('getRawInput');
        $request->method('getGet')->willReturn([]);
        $request->method('getPost')->willReturn(['title' => 'doc']);

        $collector = new RequestDataCollector();
        $result = $collector->collect($request);

        $this->assertSame('doc', $result['title']);
        $this->assertArrayHasKey('file', $result);
    }

    public function testCollectDecodesJsonBodyWhenPossible(): void
    {
        $request = $this->createMock(ApiRequest::class);
        $request->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $request->method('getFiles')->willReturn([]);
        $request->method('getBody')->willReturn('{"email":"a@b.com"}');
        $request->method('getGet')->willReturn([]);
        $request->method('getPost')->willReturn([]);

        $collector = new RequestDataCollector();
        $result = $collector->collect($request);

        $this->assertSame('a@b.com', $result['email']);
    }

    public function testCollectThrowsBadRequestForMalformedJsonPayload(): void
    {
        $request = $this->createMock(ApiRequest::class);
        $request->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
        $request->method('getFiles')->willReturn([]);
        $request->method('getBody')->willReturn('{"email":');
        $request->method('getGet')->willReturn([]);
        $request->method('getPost')->willReturn([]);

        $collector = new RequestDataCollector();

        $this->expectException(BadRequestException::class);
        $collector->collect($request);
    }
}
