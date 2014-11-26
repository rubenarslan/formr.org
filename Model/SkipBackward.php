<?php

class SkipBackward extends Branch {

	public $type = 'SkipBackward';
	public $icon = 'fa-backward';

	public function displayForRun($prepend = '') {
		$dialog = '<p>
			<label>if… <br>
				<textarea style="width:388px;" data-editor="r" class="form-control col-md-5" name="condition" rows="4" placeholder="Condition: You can use R here: survey1$item2 == 2">' . h($this->condition) . '</textarea>
			</label>
		</p>
		<div class="row col-md-12">
			<label>…skip backward to
			<input type="number" class="form-control" style="width:100px" name="if_true" max="' . ($this->position - 1) . '" min="-32000" step="1" value="' . h($this->if_true) . '">
			</label>
			
		</div>';
		$dialog .= '<p class="btn-group">
				<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=SkipBackward">Save.</a>
		<a class="btn btn-default unit_test" href="ajax_test_unit?type=SkipBackward">Test</a></p>';


		$dialog = $prepend . $dialog;

		return parent::runDialog($dialog);
	}

}
