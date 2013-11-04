<?php
require_once INCLUDE_ROOT."Model/DB.php";
require_once INCLUDE_ROOT."Model/Item.php";
require_once INCLUDE_ROOT."Model/RunUnit.php";

class DearyLiewald extends RunUnit {
	public $id = null;
	public $name = null;
	public $run_name = null;
	public $logo_name = null;
	public $items = array();
	public $maximum_number_displayed = null;
	public $unanswered_batch = array();
	public $already_answered = 0;
	public $not_answered = 0;
	public $progress = 0;
	public $session = null;
	public $errors = array();
	public $results_table = null;
	public $run_session_id = null;
	public $settings = array();
	protected $dbh;
	
	public function __construct($fdb, $session, $unit)
	{
		parent::__construct($fdb,$session,$unit);
		

		$study_data = $this->dbh->prepare("SELECT id,name FROM `survey_dearyliewalds` WHERE id = :study_id LIMIT 1");
		$study_data->bindParam(":study_id",$unit['unit_id']);
		$study_data->execute() or die(print_r($study_data->errorInfo(), true));
		$vars = $study_data->fetch(PDO::FETCH_ASSOC);
		
		if($vars):
			$this->id = $vars['id'];
			$this->name = $vars['name'];
#			$this->logo_name = $vars['logo_name'];
			$this->results_table = $this->name;
			$this->getSettings();
		endif;
		
		$this->getNextItems();

#		if(isset($_POST['session_id'])) 
#		{
			$this->post($_POST);
#		}
		
		if($this->getProgress()===1)
			$this->end();
	}
	protected function getSettings()
	{
		$study_settings = $this->dbh->prepare("SELECT `key`, `value` FROM `survey_settings` WHERE study_id = :study_id");
		$study_settings->bindParam(":study_id",$this->id);
		$study_settings->execute() or die(print_r($study_settings->errorInfo(), true));
		while($setting = $study_settings->fetch(PDO::FETCH_ASSOC))
			$this->settings[$setting['key']] = $setting['value'];

		return $this->settings;
	}
	public function render() {
		$ret = $this->render_form_header().
		$this->render_items().
		$this->render_form_footer();
		$this->dbh = NULL;
		return $ret;
	}
	public function post($posted) {

		unset($posted['id']); // cant overwrite your session
		unset($posted['session']); // cant overwrite your session
		unset($posted['session_id']); // cant overwrite your session ID
		unset($posted['study_id']); // cant overwrite your study ID
		unset($posted['created']); // cant overwrite
		unset($posted['modified']); // cant overwrite
		unset($posted['ended']); // cant overwrite

		
		$answered = $this->dbh->prepare("INSERT INTO `survey_items_display` (item_id, session_id, answered, answered_time, modified)
																  VALUES(	:item_id,  :session_id, 1, 		NOW(),	NOW()	) 
		ON DUPLICATE KEY UPDATE 											answered = 1,answered_time = NOW()");
		
		$answered->bindParam(":session_id", $this->session_id);
		
		$start_entry = $this->dbh->prepare("INSERT INTO `{$this->results_table}` (`session_id`, `study_id`, `created`, `modified`)
																  VALUES(:session_id, :study_id, NOW(),	    NOW()) 
		ON DUPLICATE KEY UPDATE modified = NOW();");
		$start_entry->bindParam(":session_id", $this->session_id);
		$start_entry->bindParam(":study_id", $this->id);
		$start_entry->execute() or die(print_r($start_entry->errorInfo(), true));
		
		
		foreach($posted AS $name => $value)
		{
	        if (isset($this->unanswered_batch[$name])) {
				$value = $this->unanswered_batch[$name]->validateInput($value);
				if( ! $this->unanswered_batch[$name]->error )
				{
					$this->dbh->beginTransaction() or die(print_r($answered->errorInfo(), true));
					$answered->bindParam(":item_id", $this->unanswered_batch[$name]->id);
			   	   	$answered->execute() or die(print_r($answered->errorInfo(), true));
					
					$post_form = $this->dbh->prepare("UPDATE `{$this->results_table}`
					SET 
					`$name` = :$name
					WHERE session_id = :session_id AND study_id = :study_id;");
				    $post_form->bindParam(":$name", $value);
					$post_form->bindParam(":session_id", $this->session_id);
					$post_form->bindParam(":study_id", $this->id);

					try
					{
						$post_form->execute();
						$this->dbh->commit();
					}
					catch(Exception $e)
					{
						pr($e);
						pr($value);
					}
					unset($this->unanswered_batch[$name]);
				} else {
					$this->errors[$name] = $this->unanswered_batch[$name]->error;
				}
			}
		} //endforeach

		if(empty($this->errors) AND !empty($posted))
		{ // PRG
			redirect_to(WEBROOT."{$this->run_name}");
		} else
		{
			$this->getProgress();
		}
		
	}

	public function end()
	{
		$post_form = $this->dbh->prepare("UPDATE 
					`{$this->results_table}` 
			SET `ended` = NOW() 
		WHERE `session_id` = :session_id AND 
		`study_id` = :study_id AND 
		`ended` IS NULL;");
		$post_form->bindParam(":session_id", $this->session_id);
		$post_form->bindParam(":study_id", $this->id);
		$post_form->execute() or die(print_r($post_form->errorInfo(), true));
		
		return parent::end();
	}
	public function exec()
	{
		if($this->called_by_cron)
			return true; // never show to the cronjob
		if($this->getProgress()===1) {
			$this->end();
			return false;
		}
		
		$action = WEBROOT."{$this->run_name}";
		
		if(!isset($this->settings['form_classes'])) $this->settings['form_classes'] = '';
		$form = 
		
		return array('title' => (isset($this->settings['title'])?$this->settings['title']: null),
		'body' => 
			'<form action="'.$action.'" method="post" class="form-horizontal '.$this->settings['form_classes'].'" accept-charset="utf-8">
				<script type="text/javascript" src="'.WEBROOT.'assets/deary_liewald^	.js"></script>
				
				<input type="hidden" name="session_id" value="' . $this->session_id . '" />
				<div class="control-group error form-message">
						<div class="control-label">'.implode("<br>",array_unique($this->errors)).'
						</div></div>
			
		<div class="row-fluid">
		    <div id="span12">
		        '.
		
				 (isset($this->settings['title'])?"<h1>{$this->settings['title']}</h1>":'') . 
				 (isset($this->settings['description'])?"<p class='lead'>{$this->settings['description']}</p>":'') .
				 '
		    </div>
		</div>
		<div class="row-fluid">
			<div class="span12">

			<div id="session_outer">
	
			<div id="session">
	
				<h4>Hier gelangen Sie zu Ihrem persönlichen Trainingsbereich.</h4>
				<p>	Bitte klicken Sie auf „Weiter“, um mit dem Training anzufangen.</p>
	
					<div class="session_begin">
						Für die korrekte Darstellung der Aufgabe, müssen Sie JavaScript in Ihren Browser-Einstellungen erlauben.
						Eine genaue Anleitung dafür finden Sie <a href="http://www.enable-javascript.com/de/">auf dieser Homepage</a>.
					</div>
				</div>
				<div id="trial">
					<div style="visibility:hidden" id="fixation"></div>
					<div style="visibility:hidden" id="probe1_top" class="probe_top"></div>
					<div style="visibility:hidden" id="probe1_bottom" class="probe"></div>
					<div style="visibility:hidden" id="probe2_top" class="probe_top"></div>
					<div style="visibility:hidden" id="probe2_bottom" class="probe"></div>
					<div style="visibility:hidden" id="mistake_message">Falsche Antwort</div>
				</div>
			</div>
		

			</div> <!-- end of span12 div -->
		</div> <!-- end of row-fluid div -->
		</form>
		'.
		(isset($this->settings['problem_email'])?
		'
		<div class="row-fluid">
			<div class="span12">'.
			(isset($this->settings['problem_text'])?
				str_replace("%s",$this->settings['problem_email'],$this->settings['problem_text']) :
				('<a href="mailto:'.$this->settings['problem_email'].'">'.$this->settings['problem_email'].'</a>')
			).
			'</div>
		</div>
		':'')
		);

	}
}