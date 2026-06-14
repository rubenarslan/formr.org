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
            } catch (\Throwable $e) {
                // \Throwable, not Exception: a malformed/unreadable/truncated key
                // file (or a libsodium mismatch) surfaces from Halite as a
                // TypeError/Error, which `catch (Exception)` lets escape as a fatal.
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
        $key = self::getKey();
        if ($key === null) {
            // Don't hand a null key to Halite's typed EncryptionKey parameter —
            // that throws a TypeError (an Error, not an Exception) that would
            // escape the catch below and fatal the whole request.
            formr_log('Crypto::encrypt aborted: encryption key could not be loaded from ' . self::getKeyFile(), 'ParagonIE\Halite');
            return null;
        }
        try {
            return \ParagonIE\Halite\Symmetric\Crypto::encrypt($data, $key);
        } catch (\Throwable $e) {
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
        $key = self::getKey();
        if ($key === null) {
            formr_log('Crypto::decrypt aborted: encryption key could not be loaded from ' . self::getKeyFile(), 'ParagonIE\Halite');
            return null;
        }
        try {
            $hiddenString = \ParagonIE\Halite\Symmetric\Crypto::decrypt($ciphertext, $key);
            return $hiddenString->getString();
        } catch (\Throwable $e) {
            formr_log_exception($e, 'ParagonIE\Halite');
        }
    }

}
