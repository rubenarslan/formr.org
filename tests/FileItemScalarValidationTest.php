<?php
use PHPUnit\Framework\TestCase;

/**
 * File_Item::validateInput's non-array branch (File.php:111-133).
 *
 * The v2 JSON /form-page-submit path (RunController.php:740) copies
 * payload['data'] into item validation without per-type filtering. Before
 * this fix, ANY non-empty scalar reaching File_Item::validateInput's
 * non-array branch was handed to Item::validateInput verbatim and stored —
 * even though no legitimate client path ever posts a non-empty scalar for a
 * file/image/audio/video item. Per webroot/assets/form/js/main.js
 * collectPayload (~line 286-302): a chosen file always rides the
 * multipart/FormData path into the $_FILES-shaped array (the is_array($reply)
 * branch below this one); a file input with no selection posts the scalar ''
 * (so the row gets marked saved) and nothing else. So '' / null are the only
 * legitimate non-array shapes — everything else must be rejected the same
 * way a failed upload is, not trusted into a column that's otherwise only
 * ever server-generated embed markup (see File_Item::getEscapedResult).
 */
class FileItemScalarValidationTest extends TestCase
{
    private function makeOptional()
    {
        return new File_Item(array('type' => 'file', 'name' => 'f', 'id' => 1, 'optional' => true));
    }

    private function makeRequired()
    {
        return new File_Item(array('type' => 'file', 'name' => 'f', 'id' => 1, 'optional' => false));
    }

    public function testEmptyScalarIsAcceptedAsNonAnswerOnOptionalItem()
    {
        $item = $this->makeOptional();
        $this->assertNull($item->validateInput(''));
        $this->assertNull($item->error);

        $item = $this->makeOptional();
        $this->assertNull($item->validateInput(null));
        $this->assertNull($item->error);
    }

    public function testArbitraryNonEmptyScalarIsRejectedNotStored()
    {
        $payload = '<img src=x onerror=alert(1)>';

        $item = $this->makeOptional();
        $result = $item->validateInput($payload);
        $this->assertNotSame($payload, $result, 'an arbitrary scalar must never be stored verbatim');
        $this->assertNull($result);
        $this->assertNotNull($item->error, 'rejecting an arbitrary scalar must record a validation error');
    }

    public function testExistingEmbedLookingStringIsAlsoRejectedAsScalarInput()
    {
        // There is no legitimate client path that re-posts previously stored
        // embed markup as a scalar (browsers won't let JS set a file input's
        // .value, and main.js never emits one) — a value that happens to look
        // like this item's own embed shape must still be rejected here, same
        // as any other non-empty scalar. getEscapedResult() is the layer that
        // trusts genuine embed markup for *display*; validateInput() must not
        // accept it as new *input*.
        $looksLikeEmbed = 'https://study.example/assets/tmp/user_uploaded_files/abc-DEF_123.pdf';

        $item = $this->makeOptional();
        $result = $item->validateInput($looksLikeEmbed);
        $this->assertNull($result);
        $this->assertNotNull($item->error);
    }

    public function testRequiredItemWithArbitraryScalarIsAlsoRejected()
    {
        $item = $this->makeRequired();
        $result = $item->validateInput('not a real upload');
        $this->assertNull($result);
        $this->assertNotNull($item->error);
    }

    public function testArrayBranchIsUnaffectedByThisFix()
    {
        // The is_array($reply) branch (untouched by this fix) still runs a
        // real $_FILES-shaped reply through its own error/mime/size checks
        // rather than the scalar short-circuit above — e.g. a failed upload
        // (error=4 on a required item) is still rejected with a validation
        // error, not silently accepted.
        $item = $this->makeRequired();
        $result = $item->validateInput(array(
            'name' => '', 'type' => '', 'tmp_name' => '', 'error' => 4, 'size' => 0,
        ));
        $this->assertNull($result);
        $this->assertNotNull($item->error);
    }
}
