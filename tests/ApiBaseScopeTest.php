<?php
use PHPUnit\Framework\TestCase;

/**
 * Concrete ApiBase used purely as a fixture so we can exercise the
 * protected helpers (checkScope, getStatusText) without dragging the full
 * dispatcher / Site bootstrap into the test.
 */
class _ApiBaseScopeFixture extends ApiBase
{
    public function callCheckScope($scope)
    {
        $this->checkScope($scope);
    }
}

class ApiBaseScopeTest extends TestCase
{
    private function makeBase($scope)
    {
        // ApiBase::__construct hydrates a User from the token's user_id via
        // OAuthHelper. We don't want to touch the DB here, so build the
        // object without invoking the constructor.
        $ref = new ReflectionClass(_ApiBaseScopeFixture::class);
        /** @var _ApiBaseScopeFixture $obj */
        $obj = $ref->newInstanceWithoutConstructor();

        $tokenProp = $ref->getProperty('tokenData');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($obj, ['scope' => $scope, 'user_id' => 'unused@example.com']);

        return $obj;
    }

    public function testCheckScopePassesWhenScopeGranted()
    {
        $base = $this->makeBase('user:read user:write run:read');
        $base->callCheckScope('run:read');
        // No exception — pass. PHPUnit needs at least one assertion.
        $this->assertTrue(true);
    }

    public function testCheckScopeThrowsWith403WhenScopeMissing()
    {
        $base = $this->makeBase('user:read');
        try {
            $base->callCheckScope('run:write');
            $this->fail('Expected Exception for missing scope was not thrown');
        } catch (Exception $e) {
            // The dispatcher relies on getCode() being a valid HTTP status
            // (with `?:` fallback to 500). 403 is what the user-facing API
            // contract promises for scope failures.
            $this->assertSame(Response::STATUS_FORBIDDEN, $e->getCode(), 'scope failures must throw with code 403');
            $this->assertStringContainsString("'run:write'", $e->getMessage());
        }
    }

    public function testCheckScopeThrowsWhenTokenHasNoScopeAtAll()
    {
        $base = $this->makeBase('');
        $this->expectException(Exception::class);
        $this->expectExceptionCode(Response::STATUS_FORBIDDEN);
        $base->callCheckScope('user:read');
    }

    public function testGetStatusTextMapsKnownCodes()
    {
        $this->assertSame('Bad Request', ApiBase::getStatusText(400));
        $this->assertSame('Unauthorized', ApiBase::getStatusText(401));
        $this->assertSame('Forbidden', ApiBase::getStatusText(403));
        $this->assertSame('Not Found', ApiBase::getStatusText(404));
        $this->assertSame('Method Not Allowed', ApiBase::getStatusText(405));
        $this->assertSame('Conflict', ApiBase::getStatusText(409));
        $this->assertSame('Internal Server Error', ApiBase::getStatusText(500));
    }

    public function testGetStatusTextFallsBackForUnknownCodes()
    {
        $this->assertSame('Error', ApiBase::getStatusText(418));
        $this->assertSame('Error', ApiBase::getStatusText(0));
    }

    /**
     * Models the dispatcher's exception-handling math. The dispatcher uses
     * `?:` (not `??`) so a default Exception::getCode() of 0 falls back to
     * 500. The status text is then mapped from the resolved code, not
     * hardcoded — this is exactly what was broken before.
     */
    public function testDispatcherFallbackComputesCodeAndText()
    {
        $defaultException = new Exception('boom'); // code 0
        $code = $defaultException->getCode() ?: Response::STATUS_INTERNAL_SERVER_ERROR;
        $this->assertSame(500, $code);
        $this->assertSame('Internal Server Error', ApiBase::getStatusText($code));

        $scopeException = new Exception('forbidden', Response::STATUS_FORBIDDEN);
        $code = $scopeException->getCode() ?: Response::STATUS_INTERNAL_SERVER_ERROR;
        $this->assertSame(403, $code);
        $this->assertSame('Forbidden', ApiBase::getStatusText($code));
    }
}
