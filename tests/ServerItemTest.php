<?php
use PHPUnit\Framework\TestCase;

/**
 * `server` stores one request header (or request fact) by its $_SERVER
 * name, from a fixed list. The list includes what a browser says about
 * itself beside the User-Agent (client hints) and the headers with which an
 * AI agent that identifies itself signs its requests.
 */
class ServerItemTest extends TestCase
{
    private $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function item($variable = null, array $options = array())
    {
        if ($variable !== null) {
            $options['type_options'] = $variable;
        }
        return new Server_Item($options + array('type' => 'server', 'name' => 'srv', 'id' => 3));
    }

    public function testWithoutAnOptionItStoresTheUserAgent()
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
        $this->assertSame('Mozilla/5.0 (test)', $this->item()->getReply(null));
        $this->assertSame('Mozilla/5.0 (test)', $this->item('')->getReply(null), 'an empty option cell is no option');
        $this->assertSame('Mozilla/5.0 (test)', (new Browser_Item(array('type' => 'browser', 'name' => 'ua')))->getReply(null));
        $this->assertSame(array(), $this->item('')->validate()['val_errors']);
    }

    public function testTheClientHintsAndSignatureHeadersCanBeStored()
    {
        $sent = array(
            'HTTP_SEC_CH_UA' => '"Chromium";v="141", "Google Chrome";v="141", "Not?A_Brand";v="8"',
            'HTTP_SEC_CH_UA_MOBILE' => '?0',
            'HTTP_SEC_CH_UA_PLATFORM' => '"macOS"',
            'HTTP_SIGNATURE_AGENT' => '"https://chatgpt.com"',
            'HTTP_SIGNATURE_INPUT' => 'sig1=("@authority" "signature-agent");keyid="abc";created=1',
            'HTTP_SIGNATURE' => 'sig1=:dGVzdA==:',
        );
        foreach ($sent as $variable => $value) {
            $_SERVER[$variable] = $value;
            $item = $this->item($variable);
            $this->assertSame(array(), $item->validate()['val_errors'], $variable);
            $this->assertSame($value, $item->getReply('whatever the browser posts'), $variable);
            $this->assertContains($variable, Server_Item::VARIABLES);
        }
        // A header the browser did not send is stored empty, like every server variable.
        unset($_SERVER['HTTP_SIGNATURE_AGENT']);
        $this->assertSame('', (string) $this->item('HTTP_SIGNATURE_AGENT')->getReply(null));
    }

    public function testTheVariablesItAlwaysTookStillWork()
    {
        foreach (array('HTTP_USER_AGENT', 'HTTP_ACCEPT', 'HTTP_ACCEPT_CHARSET', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE',
            'HTTP_CONNECTION', 'HTTP_HOST', 'QUERY_STRING', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT') as $variable) {
            $this->assertSame(array(), $this->item($variable)->validate()['val_errors'], $variable);
        }
    }

    public function testAnythingElseIsRefusedAndNeverRead()
    {
        $_SERVER['HTTP_COOKIE'] = 'session=secret';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        foreach (array('HTTP_COOKIE', 'REMOTE_ADDR', 'HTTP_AUTHORIZATION', 'PATH', 'http_user_agent') as $variable) {
            $item = $this->item($variable);
            $errors = $item->validate()['val_errors'];
            $this->assertNotEmpty($errors, $variable);
            $this->assertStringContainsString('cannot be saved', $errors[0]);
            // An item is also made from rows already in the table: it reads nothing it may not store.
            $this->assertNull($item->getReply(null), $variable);
        }
    }

    public function testItsNameIsCheckedLikeAnyItems()
    {
        $errors = $this->item('HTTP_ACCEPT_LANGUAGE', array('name' => 'session_id'))->validate()['val_errors'];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('reserved variable name', implode(' ', $errors));
    }

    public function testItTakesNoInputAndIsNotOnThePage()
    {
        $item = $this->item('HTTP_SEC_CH_UA_PLATFORM');
        $this->assertFalse($item->requiresUserInput());
        $this->assertSame(1, $item->optional);
        $this->assertNull($item->formV2Unsupported());
    }
}
