<?php

/**
 * Documentation for logged-in admins only: /admin/documentation/<page>.
 *
 * The public documentation (PublicController::documentationAction) is for
 * everyone, search engines included. What is written here is not: how formr
 * detects AI agents is of most use to somebody who wants to get past it, so
 * these pages need an admin account (AdminController::header redirects
 * everybody else to the login), are not indexed, and the public pages only
 * point here.
 *
 * A page is a template in templates/admin/documentation/ and an entry in
 * PAGES. An address that names no page ends up in indexAction (Router::
 * shiftAction) and is a 404.
 */
class AdminDocumentationController extends AdminController {

    /** page => title and summary, in the order of the index. */
    const PAGES = array(
        'data-quality' => array(
            'title' => 'Detecting AI agents and careless responding',
            'summary' => 'The data_quality and agent_probe items: what they check, the rows and the consent text to add, reading the results in R, what our tests showed, and the limits.',
        ),
    );

    /** What the data-quality page shows and offers for download. */
    const DATA_QUALITY_R = 'documentation/data_quality/dq_agent.R';
    const DATA_QUALITY_TEXTS = 'documentation/data_quality/texts.php';
    const DATA_QUALITY_EXAMPLE = 'documentation/example_surveys/data_quality.xlsx';

    public function indexAction($page = null) {
        if ($page !== null) {
            formr_error(404, 'Not Found', 'This documentation page does not exist.', null, admin_url('documentation'), 'Admin documentation');
        }
        return $this->page('index', array('pages' => self::PAGES));
    }

    public function dataQualityAction() {
        $texts = require APPLICATION_ROOT . self::DATA_QUALITY_TEXTS;
        return $this->page('data_quality', array(
            'title' => self::PAGES['data-quality']['title'],
            'rHelpers' => (string) @file_get_contents(APPLICATION_ROOT . self::DATA_QUALITY_R),
            'agentNote' => AgentProbe_Item::NOTE_OPEN . AgentProbe_Item::NOTE . AgentProbe_Item::NOTE_CLOSE,
            'agentQuestion' => AgentProbe_Item::QUESTION,
            'consentText' => $texts['consent'],
            'rrtQuestion' => $texts['rrt_question'],
        ));
    }

    /** dq_agent.R as a file. */
    public function dataQualityRAction() {
        return $this->download(self::DATA_QUALITY_R, 'text/plain; charset=utf-8');
    }

    /** The example survey as a file. */
    public function dataQualityExampleAction() {
        return $this->download(self::DATA_QUALITY_EXAMPLE, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function download($file, $contentType) {
        $content = @file_get_contents(APPLICATION_ROOT . $file);
        if ($content === false) {
            formr_error(404, 'Not Found', 'This file is missing from this installation.');
        }
        $this->noindex();
        $this->response->setContentType($contentType);
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . basename($file) . '"');
        return $this->sendResponse($content);
    }

    private function page($template, array $vars = array()) {
        $this->noindex();
        // `noindex` also puts the robots meta tag into the admin header.
        $this->setView('documentation/' . $template, $vars + array('noindex' => true));
        return $this->sendResponse();
    }

    private function noindex() {
        $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow');
    }

}
