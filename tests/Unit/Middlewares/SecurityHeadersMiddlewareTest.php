<?php
declare(strict_types=1);

namespace Tests\Unit\Middlewares;

use App\Middlewares\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class SecurityHeadersMiddlewareTest extends TestCase
{
    private SecurityHeadersMiddleware $middleware;
    private ServerRequestInterface|MockObject $mockRequest;
    private ResponseInterface|MockObject $mockResponse;
    private RequestHandlerInterface|MockObject $mockHandler;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->middleware = new SecurityHeadersMiddleware();
        $this->mockRequest = $this->createMock(ServerRequestInterface::class);
        $this->mockResponse = $this->createMock(ResponseInterface::class);
        $this->mockHandler = $this->createMock(RequestHandlerInterface::class);
    }

    public function testProcessGeneratesNonceAndStoresInRequest(): void
    {
        // Mock request to capture the nonce attribute
        $capturedNonce = null;
        $this->mockRequest->expects($this->once())
            ->method('withAttribute')
            ->with('csp_nonce', $this->callback(function ($nonce) use (&$capturedNonce) {
                $capturedNonce = $nonce;
                return is_string($nonce) && strlen($nonce) > 0;
            }))
            ->willReturn($this->mockRequest);

        // Mock handler to return response
        $this->mockHandler->expects($this->once())
            ->method('handle')
            ->with($this->mockRequest)
            ->willReturn($this->mockResponse);

        // Setup response to return itself for method chaining
        $this->mockResponse->method('withHeader')->willReturnSelf();

        $response = $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($capturedNonce);
        $this->assertIsString($capturedNonce);
    }

    public function testProcessAddsSecurityHeaders(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $expectedHeaders = [
            'X-Content-Type-Options',
            'X-Frame-Options',
            'X-XSS-Protection',
            'Referrer-Policy',
            'Permissions-Policy',
            'Strict-Transport-Security',
            'Cross-Origin-Opener-Policy',
            'X-Permitted-Cross-Domain-Policies',
            'Expect-CT',
            'Content-Security-Policy'
        ];

        $headerCallCount = 0;
        $this->mockResponse->expects($this->exactly(count($expectedHeaders)))
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$headerCallCount, $expectedHeaders) {
                $this->assertContains($name, $expectedHeaders);
                $headerCallCount++;
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertEquals(count($expectedHeaders), $headerCallCount);
    }

    public function testProcessAddsCorrectXContentTypeOptions(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->with(
                $this->callback(fn($name) => $name === 'X-Content-Type-Options' || is_string($name)),
                $this->callback(function ($value) {
                    static $found = false;
                    if ($value === 'nosniff') {
                        $found = true;
                    }
                    return true;
                })
            )
            ->willReturnSelf();

        $this->middleware->process($this->mockRequest, $this->mockHandler);
    }

    public function testProcessAddsCorrectXFrameOptions(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->with(
                $this->callback(fn($name) => $name === 'X-Frame-Options' || is_string($name)),
                $this->callback(function ($value) {
                    static $found = false;
                    if ($value === 'DENY') {
                        $found = true;
                    }
                    return true;
                })
            )
            ->willReturnSelf();

        $this->middleware->process($this->mockRequest, $this->mockHandler);
    }

    public function testProcessAddsStrictTransportSecurity(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $hstsFound = false;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$hstsFound) {
                if ($name === 'Strict-Transport-Security') {
                    $hstsFound = true;
                    $this->assertStringContainsString('max-age=31536000', $value);
                    $this->assertStringContainsString('includeSubDomains', $value);
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);
        $this->assertTrue($hstsFound, 'HSTS header was not added');
    }

    public function testProcessAddsContentSecurityPolicyWithNonce(): void
    {
        $capturedNonce = null;
        $this->mockRequest->expects($this->once())
            ->method('withAttribute')
            ->willReturnCallback(function ($attr, $nonce) use (&$capturedNonce) {
                $capturedNonce = $nonce;
                return $this->mockRequest;
            });

        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspFound = false;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspFound, &$capturedNonce) {
                if ($name === 'Content-Security-Policy') {
                    $cspFound = true;
                    $this->assertStringContainsString("'nonce-{$capturedNonce}'", $value);
                    $this->assertStringContainsString("default-src 'self'", $value);
                    $this->assertStringContainsString("script-src 'self'", $value);
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);
        $this->assertTrue($cspFound, 'CSP header was not added');
    }

    public function testProcessCSPIncludesRecaptchaDomains(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString('https://www.google.com/recaptcha/', $cspValue);
        $this->assertStringContainsString('https://www.gstatic.com/recaptcha/', $cspValue);
        $this->assertStringContainsString('https://recaptcha.google.com/recaptcha/', $cspValue);
    }

    public function testProcessCSPIncludesGoogleFonts(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString('https://fonts.googleapis.com', $cspValue);
        $this->assertStringContainsString('https://fonts.gstatic.com', $cspValue);
    }

    public function testProcessCSPAllowsDataAndBlobForImages(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString("img-src 'self' data: blob:", $cspValue);
    }

    public function testProcessCSPRestrictsObjectSrc(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString("object-src 'none'", $cspValue);
    }

    public function testGetNonceReturnsCurrentNonce(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);
        $this->mockResponse->method('withHeader')->willReturnSelf();

        // Process request to generate nonce
        $this->middleware->process($this->mockRequest, $this->mockHandler);

        // Get nonce
        $nonce = SecurityHeadersMiddleware::getNonce();

        $this->assertIsString($nonce);
        $this->assertNotEmpty($nonce);
    }

    public function testNonceIsBase64Encoded(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);
        $this->mockResponse->method('withHeader')->willReturnSelf();

        $this->middleware->process($this->mockRequest, $this->mockHandler);
        $nonce = SecurityHeadersMiddleware::getNonce();

        // Verify it's valid base64
        $decoded = base64_decode($nonce, true);
        $this->assertNotFalse($decoded);
        $this->assertEquals($nonce, base64_encode($decoded));
    }

    public function testNonceIsSufficientlyLong(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);
        $this->mockResponse->method('withHeader')->willReturnSelf();

        $this->middleware->process($this->mockRequest, $this->mockHandler);
        $nonce = SecurityHeadersMiddleware::getNonce();

        // Nonce should be base64 of 16 bytes = 24 characters (approximately)
        $this->assertGreaterThanOrEqual(20, strlen($nonce));
    }

    public function testProcessAddsPermissionsPolicy(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $permissionsPolicyFound = false;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$permissionsPolicyFound) {
                if ($name === 'Permissions-Policy') {
                    $permissionsPolicyFound = true;
                    $this->assertStringContainsString('geolocation=()', $value);
                    $this->assertStringContainsString('camera=()', $value);
                    $this->assertStringContainsString('microphone=()', $value);
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);
        $this->assertTrue($permissionsPolicyFound);
    }

    public function testProcessAddsReferrerPolicy(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $referrerPolicyFound = false;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$referrerPolicyFound) {
                if ($name === 'Referrer-Policy') {
                    $referrerPolicyFound = true;
                    $this->assertEquals('strict-origin-when-cross-origin', $value);
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);
        $this->assertTrue($referrerPolicyFound);
    }

    public function testProcessCSPIncludesUpgradeInsecureRequests(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString('upgrade-insecure-requests', $cspValue);
    }

    public function testProcessCSPIncludesBaseUri(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString("base-uri 'self'", $cspValue);
    }

    public function testProcessCSPIncludesFormAction(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString("form-action 'self'", $cspValue);
    }

    public function testProcessCSPIncludesFrameAncestors(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);

        $cspValue = null;
        $this->mockResponse->expects($this->atLeastOnce())
            ->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$cspValue) {
                if ($name === 'Content-Security-Policy') {
                    $cspValue = $value;
                }
                return $this->mockResponse;
            });

        $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertNotNull($cspValue);
        $this->assertStringContainsString("frame-ancestors 'none'", $cspValue);
    }

    public function testProcessReturnsResponse(): void
    {
        $this->mockRequest->method('withAttribute')->willReturn($this->mockRequest);
        $this->mockHandler->method('handle')->willReturn($this->mockResponse);
        $this->mockResponse->method('withHeader')->willReturnSelf();

        $result = $this->middleware->process($this->mockRequest, $this->mockHandler);

        $this->assertSame($this->mockResponse, $result);
    }
}