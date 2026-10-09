<?php

class CryptoTest extends \PHPUnit\Framework\TestCase {

    private $savedConfig;
    private $tmpDir;

    protected function setUp(): void {
        $config = new ReflectionProperty('Config', 'config');
        $config->setAccessible(true);
        $this->savedConfig = (array) $config->getValue(null);
    }

    protected function tearDown(): void {
        Config::initialize($this->savedConfig);
        if ($this->tmpDir !== null) {
            foreach (glob($this->tmpDir . '/*') ?: array() as $f) {
                unlink($f);
            }
            rmdir($this->tmpDir);
        }
    }

    /**
     * The deployment mounts a volume AT formr-crypto.key and keeps the key
     * inside it; Docker also leaves that directory on the host side of a
     * dev bind-mount. A directory at the key path must resolve to the file
     * inside it and setup() must create the key there — file_exists() on the
     * directory used to skip generation and every encrypt() returned null.
     */
    public function testKeyPathThatIsADirectoryResolvesToFileInside() {
        $this->tmpDir = sys_get_temp_dir() . '/formr-crypto-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700);
        Config::initialize(array_merge($this->savedConfig, array('encryption_key_file' => $this->tmpDir)));

        $expected = $this->tmpDir . '/formr-crypto.key';
        $this->assertSame($expected, Crypto::getKeyFile());
        $this->assertFileDoesNotExist($expected);

        Crypto::setup();

        $this->assertFileExists($expected);
        $this->assertGreaterThan(0, filesize($expected));
    }

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
