<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the SurveyTestController preview-token hardening.
 *
 * The preview token is authenticated-but-not-structured: mintToken()
 * glue-joins [studyName, userId, expiry] with '|' and Crypto::encrypt()s
 * the result, and verifyToken() explode()s it back. Before the fix the
 * study name was interpolated unchecked and verifyToken() used
 * explode('|', $plain, 3), so a study renamed to "victim|<uid>|<expiry>"
 * produced a genuine ciphertext whose fields re-mapped to another user's
 * id and a never-expiring window — a cross-tenant read/write of that
 * user's survey. The rename action's validation regex was also unanchored,
 * which is what let such a name be stored.
 *
 * After the fix: mintToken() refuses a name that isn't a bare identifier,
 * and verifyToken() requires exactly three fields of the minted shape.
 * Legitimate tokens still round-trip.
 */
class SurveyTestTokenInjectionTest extends TestCase
{
    /** A name carrying the glue delimiter must never be minted into a token. */
    public function testMintRejectsNameContainingGlue()
    {
        $this->assertNull(SurveyTestController::mintToken('victim|42|9999999999', 7));
    }

    /** Any character outside the identifier charset is refused too. */
    public function testMintRejectsNonIdentifierNames()
    {
        $this->assertNull(SurveyTestController::mintToken('has space', 7));
        $this->assertNull(SurveyTestController::mintToken('has-dash', 7));
        $this->assertNull(SurveyTestController::mintToken('9leadingdigit', 7));
        $this->assertNull(SurveyTestController::mintToken('', 7));
        $this->assertNull(SurveyTestController::mintToken('back`tick', 7));
    }

    /** A legitimate study name still mints and round-trips through verifyToken(). */
    public function testValidNameRoundTrips()
    {
        $token = SurveyTestController::mintToken('my_study1', 7, 3600);
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $verify = new \ReflectionMethod(SurveyTestController::class, 'verifyToken');
        $verify->setAccessible(true);
        $controller = (new \ReflectionClass(SurveyTestController::class))
            ->newInstanceWithoutConstructor();

        $parsed = $verify->invoke($controller, $token);
        $this->assertIsArray($parsed);
        $this->assertSame('my_study1', $parsed[0]);
        $this->assertSame(7, $parsed[1]);
        $this->assertFalse($parsed[2], 'a token minted with a 1h ttl must not read as expired');
    }
}
