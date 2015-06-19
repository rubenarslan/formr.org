<?php

class Page extends RunUnit {

	public $errors = array();
	public $id = null;
	public $session = null;
	public $unit = null;
	protected $body = '';
	protected $body_parsed = '';
	public $title = '';
	private $can_be_ended = 0;
	public $ended = false;
	public $type = 'Endpage';
	public $icon = "fa-stop";

	public function __construct($fdb, $session = null, $unit = null, $run_session = NULL, $run = NULL) {
		parent::__construct($fdb, $session, $unit, $run_session, $run);

		if ($this->id):
			$vars = $this->dbh->findRow('survey_pages', array('id' => $this->id), 'title, body, body_parsed');
			if ($vars):
				$this->body = $vars['body'];
				$this->body_parsed = $vars['body_parsed'];
				$this->title = $vars['title'];
#				$this->can_be_ended = $vars['end'] ? 1:0;
				$this->can_be_ended = 0;

				$this->valid = true;
			endif;
		endif;

		if (!empty($_POST) AND isset($_POST['page_submit'])) {
			unset($_POST['page_submit']);
			$this->end();
		}
	}

	public function create($options) {
		if (!$this->id) {
			$this->id = parent::create('Page');
		} else {
			$this->modify($options);
		}

		if (isset($options['body'])) {
			$this->body = $options['body'];
			$this->title = $options['title'];
//			$this->can_be_ended = $options['end'] ? 1:0;
			$this->can_be_ended = 0;
		}

		$parsedown = new ParsedownExtra();
		$this->body_parsed = $parsedown
				->setBreaksEnabled(true)
				->text($this->body); // transform upon insertion into db instead of at runtime

		$this->dbh->insert_update('survey_pages', array(
			'id' => $this->id,
			'body' => $this->body,
			'body_parsed' => $this->body_parsed,
			'title' => $this->title,
			'end' => (int) $this->can_be_ended,
		));
		$this->valid = true;

		return true;
	}

	public function displayForRun($prepend = '') {
		$dialog = '<p><input class="form-control col-md-5" type="text" placeholder="Page title" name="title" value="' . h($this->title) . '" placeholder="Title"></p>
		<p><label>Text: <br>
			<textarea data-editor="markdown" style="width:388px;" placeholder="You can use Markdown" name="body" rows="10" cols="60" class="form-control col-md-5">' . h($this->body) . '</textarea></label></p>';
#			'<p><input type="hidden" name="end" value="0"><label><input type="checkbox" name="end" value="1"'.($this->can_be_ended ?' checked ':'').'> allow user to continue after viewing page</label></p>';
		$dialog .= '<p class="btn-group"><a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Page">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=Page">Preview</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog, 'fa-stop fa-1-5x');
	}

	public function removeFromRun() {
		return $this->delete();
	}

	public function test() {
		echo $this->getParsedBodyAdmin($this->body);
	}

	public function exec() {
		if ($this->called_by_cron):
			$this->getParsedBody($this->body); // make report before showing it to the user, so they don't have to wait
			return true; // never show to the cronjob
		endif;

		if ($this->run_session) {
			$this->run_session->end();
		}

		$this->body_parsed = $this->getParsedBody($this->body);
		if ($this->body_parsed === false)  {
			return true; // wait for openCPU to be fixed!
		}

		return array(
			'title' => $this->title,
			'body' => $this->body_parsed
		);
	}

}
