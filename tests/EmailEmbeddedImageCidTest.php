<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression for #290: a knitted figure whose chunk label contains a space
 * is referenced in the email HTML as cid:my%20picture-1.png (R's URLencode
 * of the file name), so the Content-ID registered with the mailer must be
 * the encoded form, not the raw file name.
 */
class EmailEmbeddedImageCidTest extends TestCase
{
    public function testSpacedFileNameIsEncodedLikeRUrlencode()
    {
        $this->assertSame('my%20picture-1.png', Email::embeddedImageCid('my picture-1.png'));
    }

    public function testPlainFileNameIsUnchanged()
    {
        $this->assertSame('my_picture-1.png', Email::embeddedImageCid('my_picture-1.png'));
        $this->assertSame('unnamed-chunk-3.png', Email::embeddedImageCid('unnamed-chunk-3.png'));
    }
}
