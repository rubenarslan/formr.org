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

    public function testReservedCharactersStayLikeRUrlencode()
    {
        // R's URLencode(reserved = FALSE) leaves the reserved set alone;
        // rawurlencode() would have produced plot%281%29-1.png.
        $this->assertSame("plot(1)+a,b;c=d!'*&@#-1.png", Email::embeddedImageCid("plot(1)+a,b;c=d!'*&@#-1.png"));
        // Anything else is %XX-escaped byte-wise, uppercase, like R.
        $this->assertSame('a%22b%5Cc%C3%A9-1.png', Email::embeddedImageCid("a\"b\\cé-1.png"));
    }

    public function testPlainFileNameIsUnchanged()
    {
        $this->assertSame('my_picture-1.png', Email::embeddedImageCid('my_picture-1.png'));
        $this->assertSame('unnamed-chunk-3.png', Email::embeddedImageCid('unnamed-chunk-3.png'));
    }

    public function testSquareBracketsStayUnencodedLikeR()
    {
        // R's URLencode(reserved = FALSE) leaves [ and ] alone; verified
        // against Rscript on the dev stack.
        $this->assertSame('z=[q]-1.png', Email::embeddedImageCid('z=[q]-1.png'));
    }
}
