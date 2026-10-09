<?php

// notes are rendered at full width
class NoteIframe_Item extends Note_Item {

    public $type = 'note_iframe';
    public $mysql_field = null;
    public $input_attributes = array('type' => 'hidden', "value" => 1);
    public $save_in_results_table = false;

    /**
     * This override DOES the work instead of answering a question, so calling
     * it twice knits twice. FormRenderer (v2) and the inherited
     * SpreadsheetRenderer::processDynamicLabelsAndChoices both ask; page
     * scoping in FormRenderer keeps them from overlapping, and this flag is the
     * backstop — one knit per item per request, whatever the caller does.
     *
     * @var bool
     */
    protected $knitted = false;

    public function needsDynamicLabel($vars = [], $context = null) {
        if ($this->knitted) {
            return false;
        }
        $this->knitted = true;

        $ocpu_session = opencpu_knit_iframe($this->label, $vars, true, $context);
        if ($ocpu_session && !$ocpu_session->hasError()) {
            $this->label_parsed = self::iframeHtml($ocpu_session);
        } else {
            // A failed knit (R error, missing package, OpenCPU down) used to
            // leave label_parsed empty — the item rendered as nothing at all.
            // Show a neutral placeholder to the participant; surface the R
            // error only to test/admin sessions (real participants shouldn't
            // get a page-top error banner for a content item).
            if (RunSession::authorFacing(Site::getInstance()->getRunSession())) {
                notify_user_error(
                    opencpu_debug($ocpu_session),
                    "A note_iframe item ('{$this->name}') could not be rendered."
                );
            } else {
                error_log("note_iframe '{$this->name}': knit failed (see OpenCPU logs)");
            }
            $this->label_parsed = self::fallbackHtml();
        }

        return false;
    }

    /** The label of a knitted note_iframe: its knit.html in a frame. v1 and v2 (RCallService::resolveIframes). */
    public static function iframeHtml(OpenCPU_Session $session) {
        $files = $session->getFiles('knit.html');
        $src = isset($files['knit.html']) ? (string) $files['knit.html'] : '';
        return '<div class="rmarkdown_iframe"><iframe src="' . h($src) . '">'
            . '<p>Your browser does not support iframes.</p></iframe></div>';
    }

    /** The label of a note_iframe whose knit failed. */
    public static function fallbackHtml() {
        return '<div class="alert alert-warning fmr-iframe-fallback">'
            . h(_('This content is temporarily unavailable.')) . '</div>';
    }

}
