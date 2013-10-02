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
	public $errors = array();
	public $results_table = null;
	public $run_session_id = null;
	public $settings = array();
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
	protected function getProgress() {
		
	    $query = "SELECT `survey_items_display`.answered, COUNT(1) AS count
					FROM 
						`survey_items` LEFT JOIN `survey_items_display`
					ON `survey_items_display`.session_id = :session_id
					AND `survey_items`.id = `survey_items_display`.item_id
					WHERE 
					`survey_items`.study_id = :study_id AND
			        `survey_items`.type NOT IN (
							'instruction',
							'mc_heading',
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
			if(in_array($item->type, array('instruction','submit','mc_heading')) ) return false;
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
	protected function getChoices()
	{
		$get_item_choices = $this->dbh->prepare("SELECT list_name, name, label FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id 
		ORDER BY `survey_item_choices`.id ASC;");
		$get_item_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$get_item_choices->execute() or die(print_r($get_item_choices->errorInfo(), true));
		$choice_lists = array();
		while($row = $get_item_choices->fetch(PDO::FETCH_ASSOC)):
			if(!isset($choice_lists[ $row['list_name'] ]))
				$choice_lists[ $row['list_name'] ] = array();
			
			$choice_lists[ $row['list_name'] ][$row['name']] = $row['label'];
		endwhile;
		return $choice_lists;	
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
		
		$choice_lists = $this->getChoices();
		$item_factory = new ItemFactory($choice_lists);
		
		
		while($item = $get_items->fetch(PDO::FETCH_ASSOC) )
		{
			$name = $item['name'];
			$this->unanswered_batch[$name] = $item_factory->make($item);
			if($this->unanswered_batch[$name]->skipif !== null)
			{
				if($this->unanswered_batch[$name]->skip($this->session_id,$this->run_session_id,$this->dbh,$this->results_table))
				{
					unset($this->unanswered_batch[$name]); // todo: do something else with this when we want JS?
				}
			}
		}
		return $this->unanswered_batch;
	}
	protected function render_form_header() {
		$action = WEBROOT."{$this->run_name}";

		if(!isset($this->settings['form_classes'])) $this->settings['form_classes'] = '';
		$ret = '<form action="'.$action.'" method="post" class="form-horizontal '.$this->settings['form_classes'].'" accept-charset="utf-8">';
		
	    /* pass on hidden values */
	    $ret .= '<input type="hidden" name="session_id" value="' . $this->session_id . '" />';
	
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
											     VALUES(:item_id, :session_id, 1,				 NOW(), NOW()	) 
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
					$item->displaycount AND 											 // if this was displayed before
					(
						$next === false OR 								    				 // this is the end of the survey
						in_array( $next->type , array('instruction','submit','mc_heading'))  		 // the next item isn't a normal item
					)
				)
				{
					continue; // skip this instruction							
				}
			}
			else if ($item->type === "mc_heading")
			{
				$next = current($items);
				if(
					(
						$next === false OR 								    				 // this is the end of the survey
						!in_array( $next->type , array('mc','mmc',))  		 // the next item isn't a normal item
					)
				)
				{
					continue; // skip this instruction							
				}
			}
			
	        // Gibt es Bedingungen, unter denen das Item alternativ formuliert wird?
			
			$item->viewedBy($view_update);
			$itemsDisplayed++;
			
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
			if(isset($this->settings["submit_button_text"])):
				$sub_sets = array(
								'label' => $this->settings["submit_button_text"]
				);
			else:
				$sub_sets = array('label' => 'Weiter', 'class_input' => 'btn-info');
			endif;
			$item = new Item_submit($sub_sets);
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
		$subs_query = $this->dbh->prepare ( "SELECT `search`,`replace`,`mode` FROM `survey_substitutions` WHERE `study_id` = :study_id ORDER BY id DESC" );	// get all substitutions

		$subs_query->bindParam(':study_id',$this->id);
		$subs_query->execute();	// get all substitutions


		$search = $replace = array();

		while( $substitution = $subs_query->fetch() ) 
		{
			if(!$this->run_session_id):
				alert('<strong>Information:</strong> You can only test substitutions within runs.','alert-info');
			else:
			
				if($substitution['mode']=='ASC'):
					$mode = 'ASC';
				else:
					$mode = 'DESC';
				endif;
			
				if(strpos($substitution['replace'], ".")===-1)
					$substitution['replace'] = $this->results_table . "." . $substitution['replace'];

				$join = join_builder($this->dbh, $substitution['replace']);
				$q = "SELECT ( {$substitution['replace']} ) AS `replace` FROM `survey_run_sessions`

				$join

				WHERE 
				`survey_run_sessions`.`id` = :run_session_id

				ORDER BY IF(ISNULL( ( {$substitution['replace']} ) ),1,0), `survey_unit_sessions`.id $mode

				LIMIT 1";
				$get_entered = $this->dbh->prepare($q);
		
				$get_entered->bindParam(":run_session_id",$this->run_session_id);
				try
				{
					$get_entered->execute();
				}
				catch(Exception $e)
				{
					echo __("Column %s not found.", $substitution['replace']);
					print_r($e);
				}

				if( $data = $get_entered->fetch(PDO::FETCH_NUM))
				{
				    $search[] = $substitution['search'];
				    $replace[] = h($data[0]);
				}
			endif;
		}
		return array('search' => $search,'replace' => $replace);
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