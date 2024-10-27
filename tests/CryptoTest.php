<?php

class CryptoTest extends \PHPUnit\Framework\TestCase {

    public function testEncryptDecryptArray() {
        $data = array('cyril@example.com', 'myP@ssw0Rd');
        $glue = ':fmr:';

        Crypto::setup();

        $ciphertext = Crypto::encrypt($data, $glue);
        $plaintext = Crypto::decrypt($ciphertext);
        $this->assertEquals(implode($glue, $data), $plaintext);
    }

    public function testEncryptDecryptString() {
        Crypto::setup();

        $string = "The quick brownfox jumped over the lazy dog";
        $ciphertext = Crypto::encrypt($string);
        $plaintext = Crypto::decrypt($ciphertext);
        $this->assertEquals($string, $plaintext);
    }

}
