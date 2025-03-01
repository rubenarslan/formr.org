<?php
use ParagonIE\Halite\HiddenString;

class Crypto {

    const KEY_FILE = APPLICATION_CRYPTO_KEY_FILE;

    private static $key = null;

    public static function setup() {
        if (!\ParagonIE\Halite\Halite::isLibsodiumSetupCorrectly()) {
            throw new Exception('The libsodium extension is required. Please see https://paragonie.com/book/pecl-libsodium/read/00-intro.md#installing-libsodium on how to install');
        }

        if (!file_exists(self::getKeyFile())) {
            $enc_key = \ParagonIE\Halite\KeyFactory::generateEncryptionKey();
            \ParagonIE\Halite\KeyFactory::save($enc_key, self::getKeyFile());
        }
    }

    protected static function getKeyFile() {
        if (Config::get('encryption_key_file')) {
            return Config::get('encryption_key_file');
        }
        return self::KEY_FILE;
    }

    /**
     * Get saved encryption key
     *
     * @return \ParagonIE\Halite\Symmetric\EncryptionKey | null
     */
    protected static function getKey() {
        if (self::$key === null) {
            try {
                self::$key = \ParagonIE\Halite\KeyFactory::loadEncryptionKey(self::getKeyFile());
            } catch (Exception $e) {
                formr_log_exception($e, 'ParagonIE\Halite');
            }
        }
        return self::$key;
    }

    /**
     * Encrypt Data
     *
     * @param string|array $data String or an array of strings
     * @param string $glue If array is provided as first parameter, this will be used to glue the elements to form a string
     * @return string|null
     */
    public static function encrypt($data, $glue = '') {
        if (is_array($data)) {
            $data = implode($glue, $data);
        }
        if (!$data instanceof HiddenString) {
            $data = new HiddenString($data, true);
        }
        try {
            return \ParagonIE\Halite\Symmetric\Crypto::encrypt($data, self::getKey());
        } catch (Exception $e) {
            formr_log_exception($e, 'ParagonIE\Halite');
        }
    }

    /**
     * Decrypt cipher text
     *
     * @param string $ciphertext
     * @return string|null
     */
    public static function decrypt($ciphertext) {
        try {
            $hiddenString = \ParagonIE\Halite\Symmetric\Crypto::decrypt($ciphertext, self::getKey());
            return $hiddenString->getString();
        } catch (Exception $e) {
            formr_log_exception($e, 'ParagonIE\Halite');
        }
    }

}
