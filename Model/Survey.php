<?php
require_once INCLUDE_ROOT."Model/DB.php";
require_once INCLUDE_ROOT."Model/Item.php";
require_once INCLUDE_ROOT."Model/RunUnit.php";

class Survey extends RunUnit {
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
	public $timestarted = null;
	public $errors = array();
	public $results_table = null;
	protected $dbh;
	
	public function __construct($fdb, $session, $unit)
	{
		parent::__construct($fdb,$session,$unit);
		

		$study_data = $this->dbh->prepare("SELECT id,name FROM `survey_studies` WHERE id = :study_id LIMIT 1");
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

		if(isset($_POST['session_id'])) 
		{
			$this->post($_POST);
		}
		
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

		
		$answered = $this->dbh->prepare("INSERT INTO `survey_items_display` (study_id, item_id, session_id, answered, answered_time, modified)
																  VALUES(	:study_id, :item_id,  :session_id, 1, 		NOW(),	NOW()	) 
		ON DUPLICATE KEY UPDATE 											answered = 1,answered_time = NOW()");
		
		$answered->bindParam(":session_id", $this->session_id);
		$answered->bindParam(":study_id", $this->id);
		
		foreach($posted AS $name => $value)
		{
	        if (isset($this->unanswered_batch[$name])) {
				$this->unanswered_batch[$name]->validateInput($value);
				if( ! $this->unanswered_batch[$name]->error )
				{
					
					if(is_array($posted[$name])) $value = implode(", ",array_filter($posted[$name]));
					else $value = $posted[$name];

					$this->dbh->beginTransaction() or die(print_r($answered->errorInfo(), true));
					$answered->bindParam(":item_id", $this->unanswered_batch[$name]->id);
			   	   	$answered->execute() or die(print_r($answered->errorInfo(), true));
					
					$post_form = $this->dbh->prepare("INSERT INTO `{$this->results_table}` (`session_id`, `session`, `study_id`, `created`, `modified`, `$name`)
																			  VALUES(:session_id, :session, :study_id, NOW(),	    NOW(),	 		:$name) 
					ON DUPLICATE KEY UPDATE `$name` = :$name, modified = NOW();");
				    $post_form->bindParam(":$name", $value);
					$post_form->bindParam(":session_id", $this->session_id);
					$post_form->bindParam(":session", $this->session);
					$post_form->bindParam(":study_id", $this->id);
					$post_form->execute() or die(print_r($post_form->errorInfo(), true));

					$this->dbh->commit() or die(print_r($answered->errorInfo(), true));
					unset($this->unanswered_batch[$name]);
				} else {
					$this->errors[$name] = $this->unanswered_batch[$name]->error;
				}
			}
		} //endforeach

		if(empty($this->errors))
		{ // PRG
			redirect_to(WEBROOT."{$this->run_name}");
		} else
		{
			$this->getProgress();
		}
		
	}
	protected function getProgress() {
		
	    $query = "SELECT `survey_items_display`.answered, COUNT(1) AS count
					FROM 
						`survey_items` LEFT JOIN `survey_items_display`
					ON `survey_items_display`.session_id = :session_id
					AND `survey_items`.id = `survey_items_display`.item_id
					WHERE 
					`survey_items`.study_id = :study_id AND
			        `survey_items`.typ NOT IN (
							'instruction',
							'submit'
						)
					GROUP BY `survey_items_display`.answered;";

		//fixme: progress can become smaller when questions enabling a lot of skipif turn on
		$progress = $this->dbh->prepare($query);
		$progress->bindParam(":session_id", $this->session_id);
		$progress->bindParam(":study_id", $this->id);
		
		$progress->execute() or die(print_r($progress->errorInfo(), true));

		while($item = $progress->fetch(PDO::FETCH_ASSOC) )
		{	
			if($item['answered']!=null) $this->already_answered += $item['count'];
			else $this->not_answered += $item['count'];
		}
		
		
		$this->not_answered = count( array_filter($this->unanswered_batch, function ($item)
		{
			if(in_array($item->type, array('instruction','submit')) ) return false;
			else return true;
		}
) );
		$all_items = $this->already_answered + $this->not_answered;
		
		#pr(array_filter($this->unanswered_batch,'proper_type'));
		if($all_items !== 0) {
			$this->progress = $this->already_answered / $all_items ;

			return $this->progress;
		}
		else
		{
			$this->errors[] = _('Something went wrong, there are no items in this survey!');
			$this->progress = 0;
			return 0;
		}
	}
	protected function getNextItems() {
		$this->unanswered_batch = array();
		
		$item_query = "SELECT 
				`survey_items`.*,
		`survey_items_display`.displaycount, 
		`survey_items_display`.session_id
					FROM 
			`survey_items` LEFT JOIN `survey_items_display`
		ON `survey_items_display`.session_id = :session_id
		AND `survey_items`.id = `survey_items_display`.item_id
		WHERE 
		`survey_items`.study_id = :study_id AND
		`survey_items_display`.answered IS NULL
		ORDER BY `survey_items`.id ASC;";
		
#		if($this->maximum_number_displayed) $item_query .= " LIMIT {$this->maximum_number_displayed}";
		$get_items = $this->dbh->prepare($item_query) or die(print_r($this->dbh->errorInfo(), true));
		
		$get_items->bindParam(":session_id",$this->session_id);
		$get_items->bindParam(":study_id", $this->id);
		
		$get_items->execute() or die(print_r($get_items->errorInfo(), true));
		
		while($item = $get_items->fetch(PDO::FETCH_ASSOC) )
		{
			$name = $item['variablenname'];
			$this->unanswered_batch[$name] = legacy_translate_item($item);
			if($this->unanswered_batch[$name]->skipIf !== null)
			{
				if($this->unanswered_batch[$name]->skip($this->session_id,$this->dbh,$this->results_table))
				{
					unset($this->unanswered_batch[$name]); // fixme: do something else with this when we want JS?
				}
			}
		}
		return $this->unanswered_batch;
	}
	protected function render_form_header() {
		$action = WEBROOT."{$this->run_name}";

		$ret = '<form action="'.$action.'" method="post" class="form-horizontal" accept-charset="utf-8">';
		
	    /* pass on hidden values */
	    $ret .= '<input type="hidden" name="session_id" value="' . $this->session_id . '" />';
	    if( !empty( $timestarted ) ) {
	        $ret .= '<input type="hidden" name="timestarted" value="' . $timestarted .'" />';
		} else {
			debug("<strong>render_form_header:</strong> timestarted was not set or empty");
		}
	
		if(!isset($this->settings["displayed_percentage_maximum"]))
			$this->settings["displayed_percentage_maximum"] = 90;
		$prog = round($this->progress,2) * $this->settings["displayed_percentage_maximum"];
		if(isset($this->settings["add_percentage_points"]))
			$prog += $this->settings["add_percentage_points"];
		
	    $ret .= '<div class="progress">
				  <div class="bar" style="width: '.$prog.'%;">'.$prog.'%</div>
			</div>';
		$ret .= '<div class="control-group error form-message">
			<div class="control-label">'.implode("<br>",array_unique($this->errors)).'
			</div></div>';	
		return $ret;

	}

	protected function render_items() 
	{
		$ret = '';
		$substitutions = $this->getSubstitutions();
		$items = $this->unanswered_batch;
		
		$view_query = "INSERT INTO `survey_items_display` (item_id,  session_id, displaycount, created, modified)
											     VALUES(  :item_id, :session_id, 1,				 NOW(), NOW()	) 
		ON DUPLICATE KEY UPDATE displaycount = displaycount + 1, modified = NOW()";
		$view_update = $this->dbh->prepare($view_query);
		$view_update->bindParam(":session_id", $this->session_id);
	
		$itemsDisplayed = $i = 0;
		$need_submit = true;
	    foreach($items AS &$item) 
		{
			$i++;

	        // fork-items sind relevant, werden aber nur behandelt, wenn sie auch an erster Stelle sind, also alles vor ihnen schon behandelt wurde
			if ($item->type === 'submit')
			{
				if($itemsDisplayed === 0)
					continue; // skip submit buttons once everything before them was dealt with				
			}
			else if ($item->type === "instruction")
			{
				$next = current($items);
				if(
					$item->displayed_before AND 											 // if this was displayed before
					(
						$next === false OR 								    				 // this is the end of the survey
						in_array( $next->type , array('instruction','submit'))  		 // the next item isn't a normal item
					)
				)
				{
					continue; // skip this instruction							
				}
			}
				
			
	        // Gibt es Bedingungen, unter denen das Item alternativ formuliert wird?
			
			$item->viewedBy($view_update);
			$itemsDisplayed++;
			
			$item->switchText($this->session_id,$this->dbh,$this->results_table);
			
			if(!empty($substitutions['search']))
			{
		        $item->substituteText($substitutions);				
			}

			
			$ret .= $item->render();
#			$ret .= '<strong>'.key($items);

	        // when the maximum number of items to display is reached, stop
	        if (
				($this->maximum_number_displayed != null AND
				$itemsDisplayed >= $this->maximum_number_displayed) OR 
				$item->type === 'submit' 
			)
			{
				$need_submit = ($item->type !== 'submit');
	            break;
	        }
	    } //end of for loop
		
		if($need_submit) // only if no submit was part of the form
		{
			$item = new Item_submit('final_submit',array('text'=>'Weiter!'));
			$ret .= $item->render();
		}
		
		return $ret;
	}

	protected function render_form_footer() 
	{
	    return "</form>"; /* close form */
	}
	protected function getSubstitutions() 
	{
		
	$subs_query = $this->dbh->prepare ( "SELECT `search`,`replace`,`mode` FROM `survey_substitutions` WHERE `study_id` = :study_id ORDER BY id DESC" ) or die(print_r($this->dbh->errorInfo(), true));	// get all substitutions
	
	$subs_query->bindParam(':study_id',$this->id);
	$subs_query->execute() or die(print_r($subs_query->errorInfo(), true));	// get all substitutions
	

	$search = $replace = array();
	while( $substitution = $subs_query->fetch() ) 
		{
	        switch( $substitution['mode'] ) 
			{
		        case NULL:
					$get_entered = $this->dbh->prepare("SELECT {$substitution['replace']} FROM `{$this->results_table}` WHERE session_id = :session_id AND {$substitution['replace']} IS NOT NULL ORDER BY created DESC LIMIT 1;") or die(print_r($get_entered->errorInfo(), true));
					break;
				default:
					$get_entered = $this->dbh->prepare("SELECT {$substitution['replace']} FROM `{$this->results_table}` WHERE session_id = :session_id AND {$substitution['replace']} IS NOT NULL AND {$substitution['mode']} LIMIT 1;") or die(print_r($get_entered->errorInfo(), true));
//					$get_entered->bindParam(":mode",$subst['mode']); // fixme: mode not meaningfully used atm, gotta autocreate joins
					break;
	        }
			$get_entered->bindParam(":session_id",$this->session_id);
			$get_entered->execute() or die(print_r($get_entered->errorInfo(), true));
		
			if( $data = $get_entered->fetch(PDO::FETCH_NUM))
			{
			    $search[] = $substitution['search'];
			    $replace[] = h($data[0]);
			}
		}
		return array('search' => $search,'replace' => $replace);
	}
	
	public function end()
	{
		$post_form = $this->dbh->prepare("UPDATE `{$this->results_table}` SET `ended` = NOW() WHERE `session_id` = :session_id AND `study_id` = :study_id AND `ended` IS NULL;");
		$post_form->bindParam(":session_id", $this->session_id);
		$post_form->bindParam(":study_id", $this->id);
		$post_form->execute() or die(print_r($post_form->errorInfo(), true));
		if($post_form->rowCount() === 1):
			return parent::end();
		else:
			return false;
		endif;
	}
	public function exec()
	{
		if($this->getProgress()===1) return false;
		return array('title' => (isset($this->settings['title'])?$this->settings['title']: null),
		'body' => 
			'
			
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

		'.

		 $this->render().
		
		 '

			</div> <!-- end of span12 div -->
		</div> <!-- end of row-fluid div -->
		'.
		(isset($this->settings['problem_email'])?
		'
		<div class="row-fluid">
			<div class="span12">
				Bei Problemen wenden Sie sich bitte an <strong><a href="mailto:'.$this->settings['problem_email'].'">'.$this->settings['problem_email'].'</a>.</strong>
			</div>
		</div>
		':'')
		);

	}
}