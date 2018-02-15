<?php

// notes are rendered at full width
class Note_Item extends Item {

	public $type = 'note';
	public $mysql_field = null;
	public $input_attributes = array('type' => 'hidden', "value" => 1);
	public $save_in_results_table = false;
	public $optional = 1;

	protected function render_label() {
		$template = '<div class="%{class}">%{error} %{text} </div>';

		return Template::replace($template, array(
			'class' => implode(' ', $this->classes_label),
			'error' => $this->render_error_tip(),
			'text' => $this->label_parsed,
		));
	}

	public function validateInput($reply) {
		if ($reply != 1) {
			$this->error = _("You can only answer notes by viewing them.");
		}
		return $reply;
	}

}
