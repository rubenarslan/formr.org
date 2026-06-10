<?php

// notes are rendered at full width
class NoteIframe_Item extends Note_Item {

    public $type = 'note_iframe';
    public $mysql_field = null;
    public $input_attributes = array('type' => 'hidden', "value" => 1);
    public $save_in_results_table = false;

    public function needsDynamicLabel($vars = [], $context = null) {

        $ocpu_session = opencpu_knit_iframe($this->label, $vars, true, $context);
        if ($ocpu_session && !$ocpu_session->hasError()) {
            $iframesrc = $ocpu_session->getFiles("knit.html")['knit.html'];
            $this->label_parsed = '' .
                '<div class="rmarkdown_iframe">
					<iframe src="' . $iframesrc . '">
					  <p>Your browser does not support iframes.</p>
					</iframe>
				</div>';
		} else {
            // A failed knit (R error, missing package, OpenCPU down) used to
            // leave label_parsed empty — the item rendered as nothing at all.
            // Show a neutral placeholder to the participant; surface the R
            // error only to test/admin sessions (real participants shouldn't
            // get a page-top error banner for a content item).
            $run_session = Site::getInstance()->getRunSession();
            if (!$run_session || $run_session->isCron() || $run_session->isTesting()) {
                notify_user_error(
                    opencpu_debug($ocpu_session),
                    "A note_iframe item ('{$this->name}') could not be rendered."
                );
            } else {
                error_log("note_iframe '{$this->name}': knit failed (see OpenCPU logs)");
            }
            $this->label_parsed = '<div class="alert alert-warning fmr-iframe-fallback">'
                . _('This content is temporarily unavailable.')
                . '</div>';
        }

        return false;
    }

}
