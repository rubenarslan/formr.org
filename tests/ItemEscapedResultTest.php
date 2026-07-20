<?php
use PHPUnit\Framework\TestCase;

/**
 * Item::getEscapedResult() is the safe-by-default renderer for stored
 * answers in the admin results tables (show_results / show_itemdisplay),
 * which echo cells as raw HTML. Base escapes; File/Audio/Video/Image
 * pass their server-generated embed markup through.
 *
 * Regression guard for the participant -> admin stored-XSS where an
 * open-text answer (or a Server/Referrer item capturing the User-Agent)
 * containing `<img onerror>` executed in the admin origin.
 */
class ItemEscapedResultTest extends TestCase
{
    private $xss = '<img src=x onerror="alert(document.cookie)">';

    public function testTextItemEscapesHtml()
    {
        $item = new Text_Item(array('type' => 'text', 'name' => 'q1'));
        $out = $item->getEscapedResult($this->xss);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('&lt;img', $out);
        $this->assertStringNotContainsString('onerror="alert', $out);
    }

    public function testTextareaItemEscapesHtml()
    {
        $item = new Textarea_Item(array('type' => 'textarea', 'name' => 'q2'));
        $this->assertStringNotContainsString('<img', $item->getEscapedResult($this->xss));
    }

    /**
     * Media items store the <audio|video|img> markup they build themselves
     * (src = server crypto_token path), so it must render, not be escaped.
     */
    public function testMediaItemsPassEmbedMarkupThrough()
    {
        // Build the genuine stored value each class produces — sprintf of its
        // OWN embed_html over a real upload URL — so the test tracks the
        // template (incl. quirks like Video's fallback text) rather than a
        // hand-approximation the strict recognizer would rightly reject.
        $url = 'https://study.example/assets/tmp/user_uploaded_files/abc-DEF_123~x.png';
        foreach (array('Image_Item', 'Audio_Item', 'Video_Item') as $class) {
            $item = new $class(array('name' => 'media'));
            $prop = new ReflectionProperty($class, 'embed_html');
            $prop->setAccessible(true);
            $embed = sprintf($prop->getValue($item), $url);
            $this->assertSame($embed, $item->getEscapedResult($embed), "$class should pass its own embed markup through");
        }
    }

    public function testFileItemPassesItsBareUploadUrl()
    {
        // File_Item's embed_html is '%s' — it stores the bare asset URL.
        $item = new File_Item(array('type' => 'file', 'name' => 'f'));
        $url = 'https://study.example/assets/tmp/user_uploaded_files/abc-DEF_123.pdf';
        $this->assertSame($url, $item->getEscapedResult($url));
    }

    /**
     * Review 2026-07 (item 19): the admin results view dispatches by the
     * item's CURRENT type, so foreign data under a now-file-typed column
     * (restored backup after a text→file retype; import) must NOT pass raw.
     * A value that isn't this item's own embed shape is escaped.
     */
    public function testFileItemsEscapeForeignDataUnderTheirColumn()
    {
        $classes = array('File_Item', 'Image_Item', 'Audio_Item', 'Video_Item');
        foreach ($classes as $class) {
            $item = new $class(array('name' => 'media'));
            // participant-stored XSS payload sitting under a retyped column
            $out = $item->getEscapedResult($this->xss);
            $this->assertStringNotContainsString('<img', $out, "$class must escape a foreign XSS payload");
            $this->assertStringContainsString('&lt;img', $out, "$class must html-escape a foreign payload");
            // plain participant text
            $this->assertSame('hello &amp; goodbye', $item->getEscapedResult('hello & goodbye'), "$class must escape plain text");
            // a spoofed embed whose src escapes the upload dir + adds a handler
            $spoof = '<img src="x" onerror="alert(1)">';
            $this->assertStringNotContainsString('onerror="alert', $item->getEscapedResult($spoof), "$class must not pass a spoofed tag");
        }
    }

    public function testNullResultIsHandled()
    {
        $item = new Text_Item(array('type' => 'text', 'name' => 'q3'));
        $this->assertNull($item->getEscapedResult(null));
        // media items preserve null too (empty cells)
        $this->assertNull((new Image_Item(array('name' => 'm')))->getEscapedResult(null));
    }
}
