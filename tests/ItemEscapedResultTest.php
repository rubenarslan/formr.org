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
        $cases = array(
            'Audio_Item' => '<audio src="https://study.example/assets/tmp/user_uploaded_files/abc.mp3" controls></audio>',
            'Video_Item' => '<video src="https://study.example/assets/tmp/user_uploaded_files/abc.mp4" controls></video>',
            'Image_Item' => '<img src="https://study.example/assets/tmp/user_uploaded_files/abc.png">',
        );
        foreach ($cases as $class => $embed) {
            $item = new $class(array('name' => 'media'));
            $this->assertSame($embed, $item->getEscapedResult($embed), "$class should pass embed markup through");
        }
    }

    public function testFileItemInheritsPassthrough()
    {
        $item = new File_Item(array('type' => 'file', 'name' => 'f'));
        $markup = '<a href="https://study.example/assets/tmp/user_uploaded_files/abc.pdf">abc.pdf</a>';
        $this->assertSame($markup, $item->getEscapedResult($markup));
    }

    public function testNullResultIsHandled()
    {
        $item = new Text_Item(array('type' => 'text', 'name' => 'q3'));
        $this->assertNull($item->getEscapedResult(null));
    }
}
