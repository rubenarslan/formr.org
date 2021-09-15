<?php


/**
 * Render a form for a unit sessions based on rendered and validated items
 *
 * @author ctata
 */
class FormRenderer {
    
    /**
     * 
     * @var Run
     */
    protected $run;
    /**
     * 
     * @var SurveyStudy
     */
    protected $study;
    /**
     * 
     * @var UnitSession
     */
    protected $unitSession;
    /**
     * 
     * @var Item[]
     */
    protected $renderedItems;
    
    protected $progressCounts;

    protected $validationErrors;
    
    protected $validatedItems;

    public function __construct(UnitSession $unitSession, $renderedItems, $validatedItems = [], $validationErrors = [], $progressCounts = []) {
        $this->unitSession = $unitSession;
        $this->run = $unitSession->runSession->getRun();
        $this->study = $unitSession->runUnit->surveyStudy;
        $this->renderedItems = $renderedItems;
        $this->validatedItems = $validatedItems;
        $this->validationErrors = $validationErrors;
        $this->progressCounts = $progressCounts;
    }
    
    public function render($form_action = null, $form_append = null) {
        $ret = '
		<div class="row study-' . $this->study->id . ' study-name-' . $this->study->name . '">
			<div class="col-md-12">
		';
        $ret .= $this->renderHeader($form_action) .
                $this->renderItems() .
                $form_append .
                $this->renderFooter();
        $ret .= '
			</div> <!-- end of col-md-12 div -->
		</div> <!-- end of row div -->
		';
        //$this->dbh = null;
        return $ret;
    }
    
    protected function renderHeader($action = null) {
        //$cookie = Request::getGlobals('COOKIE');
        $action = $action !== null ? $action : run_url($this->run->name);
        $enctype = 'multipart/form-data'; # maybe make this conditional application/x-www-form-urlencoded

        $tpl = '
			<form action="%{action}" method="post" class="%{class}" enctype="%{enctype}" accept-charset="utf-8">
				<input type="hidden" name="session_id" value="%{session_id}" />
				<input type="hidden" name="%{name_request_tokens}" value="%{request_tokens}" />
				<input type="hidden" name="%{name_user_code}" value="%{user_code}" />
				<input type="hidden" name="%{name_cookie}" value="%{cookie}" />
				
				<div class="row progress-container">
					<div class="progress">
						<div class="progress-bar" style="width: %{progress}%;" data-percentage-minimum="%{add_percentage_points}" data-percentage-maximum="%{displayed_percentage_maximum}" data-already-answered="%{already_answered}" data-items-left="%{not_answered_on_current_page}" data-items-on-page="%{items_on_page}" data-hidden-but-rendered="%{hidden_but_rendered}">
							%{progress} %
						</div>
					</div>
				</div>

				%{errors_tpl}
		';

        $errors_tpl = '
			<div class="alert alert-danger alert-dismissible form-message fmr-error-messages">
				<i class="fa fa-exclamation-triangle pull-left fa-2x"></i>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				%{errors}
			</div>
		';

        if (!isset($this->study->displayed_percentage_maximum) OR $this->study->displayed_percentage_maximum == 0) {
            $this->study->displayed_percentage_maximum = 100;
        }

        $prog = $this->progressCounts['progress'] * // the fraction of this survey that was completed
                ($this->study->displayed_percentage_maximum - // is multiplied with the stretch of percentage that it was accorded
                $this->study->add_percentage_points);

        if (isset($this->study->add_percentage_points)) {
            $prog += $this->study->add_percentage_points;
        }

        if ($prog > $this->study->displayed_percentage_maximum) {
            $prog = $this->study->displayed_percentage_maximum;
        }

        $prog = round($prog);

        $tpl_vars = array(
            'action' => $action,
            'class' => 'form-horizontal main_formr_survey' . ($this->study->enable_instant_validation ? ' ws-validate' : ''),
            'enctype' => $enctype,
            'session_id' => $this->unitSession->id,
            'name_request_tokens' => Session::REQUEST_TOKENS,
            'name_user_code' => Session::REQUEST_USER_CODE,
            'name_cookie' => Session::REQUEST_NAME,
            'request_tokens' => Session::getRequestToken(), //$cookie->getRequestToken(),
            'user_code' => h(Site::getCurrentUser()->user_code), //h($cookie->getData('code')),
            'cookie' => '', //$cookie->getFile(),
            'progress' => $prog,
            'add_percentage_points' => $this->study->add_percentage_points,
            'displayed_percentage_maximum' => $this->study->displayed_percentage_maximum,
            'already_answered' => $this->progressCounts['already_answered'],
            'not_answered_on_current_page' => $this->progressCounts['not_answered_on_current_page'],
            'items_on_page' => $this->progressCounts['not_answered'] - $this->progressCounts['not_answered_on_current_page'],
            'hidden_but_rendered' => $this->progressCounts['hidden_but_rendered_on_current_page'],
            'errors_tpl' => !empty($this->validationErrors) ? Template::replace($errors_tpl, array('errors' => $this->renderErrors())) : null,
        );

        return Template::replace($tpl, $tpl_vars);
    }

    protected function renderItems() {
        $ret = '';

        foreach ($this->renderedItems as $item) {
            if (!empty($this->validationErrors[$item->name])) {
                $item->error = $this->validationErrors[$item->name];
            }
            if (!empty($this->validatedItems[$item->name])) {
                $item->value_validated = $this->validatedItems[$item->name]->value_validated;
            }
            $ret .= $item->render();
        }

        // if the last item was not a submit button, add a default one
        if (isset($item) && ($item->type !== "submit" || $item->hidden)) {
            $sub_sets = array(
                'label_parsed' => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!',
                'classes_input' => array('btn-info default_formr_button'),
            );
            $item = new Submit_Item($sub_sets);
            $ret .= $item->render();
        }

        return $ret;
    }

    protected function renderFooter() {
        return '</form>';
    }

    /**
     * 
     * @param Item[] $items
     * @return string
     */
    protected function renderErrors() {
        $labels = Session::get('labels', array());
        $tpl = '
        <li>
			<i class=""></i>
			<b>Question/Code</b>: %{question} <br />
			<b>Error</b>: %{error}
		 </li>
		';
        $errors = '';

        foreach ($this->validationErrors as $name => $error) {
            if ($error) {
                $errors .= Template::replace($tpl, array(
                    'question' => strip_tags(array_val($labels, $name, strtoupper($name))),
                    'error' => $error,
                ));
            }
        }
        Session::delete('labels');
        return '<ul>' . $errors . '</ul>';
    }
}
