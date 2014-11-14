<?php
require_once INCLUDE_ROOT."Model/DB.php";
require_once INCLUDE_ROOT."Model/Item.php";
require_once INCLUDE_ROOT."Model/RunUnit.php";

class Survey extends RunUnit {
	public $id = null;
	public $name = null;
	public $run_name = null;
	public $items = array();
	public $unanswered = array();
	public $already_answered = 0;
	public $not_answered = 0;
	public $progress = 0;
	public $session = null;
	public $results_table = null;
	public $run_session_id = null;
	public $settings = array();
	/**
	 * @var PDO
	 */
	public $dbh;
	
	public $valid = false;
	public $public = false;
	public $errors = array();
	public $messages = array();
	public $warnings = array();
	public $position;
	private $SPR;
	public $openCPU = null;
	public $icon = "fa-pencil-square-o";
	public $type = "Survey";
	public $admin_usage = false;
	
	
	private $confirmed_deletion = false;
	private $item_factory = null;
	
	public function __construct($fdb, $session, $unit, $run_session = NULL)
	{
		if(isset($unit['name']) AND !isset($unit['unit_id'])): // when called via URL
			$study_data = $fdb->prepare("SELECT id FROM `survey_studies` WHERE name = :name LIMIT 1");
			$study_data->bindValue(":name",$unit['name']);
			$study_data->execute();
			$vars = $study_data->fetch(PDO::FETCH_ASSOC);
			$unit['unit_id'] = $vars['id']; // parent::__construct needs this
			$this->id = $unit['unit_id'];
		endif;
		
		parent::__construct($fdb,$session,$unit, $run_session);
		
		if($this->id):
			$this->load();
		endif;
		
		if($this->beingTestedByOwner()) $this->admin_usage = true;
	}
	private function load()
	{
		$study_data = $this->dbh->prepare("SELECT * FROM `survey_studies` WHERE id = :id LIMIT 1");
		$study_data->bindParam(":id",$this->id);
		$study_data->execute();
		$vars = $study_data->fetch(PDO::FETCH_ASSOC);
		
		if($vars):
			$this->id = $vars['id'];
			$this->name = $vars['name'];
 			$this->user_id = (int)$vars['user_id'];
			if(!isset($vars['results_table']) OR $vars['results_table'] == null)
				$this->results_table = $this->name;
			else
				$this->results_table = $vars['results_table'];
			
			$this->settings['maximum_number_displayed'] = (int)$vars['maximum_number_displayed'];
			$this->settings['displayed_percentage_maximum'] = (int)$vars['displayed_percentage_maximum'];
			$this->settings['add_percentage_points'] = (int)$vars['add_percentage_points'];
		
			$this->valid = true;
		endif;
	}
	public function render() {
		global $js;
		$js = (isset($js)?$js:'') . '<script src="'.WEBROOT.'assets/survey.js"></script>';

	 $ret = '
	<div class="row">
		<div class="col-md-12">

	';
	 $ret .= $this->render_form_header().
	 $this->render_items().
	 $this->render_form_footer();
	 $ret .=	 '
		</div> <!-- end of col-md-12 div -->
	</div> <!-- end of row div -->
	';
		$this->dbh = NULL;
		return $ret;
	}

	protected function startEntry() {
		
		$start_entry = $this->dbh->prepare("INSERT INTO `{$this->results_table}` (`session_id`, `study_id`, `created`) VALUES(:session_id, :study_id, NOW()) ON DUPLICATE KEY UPDATE modified = NOW();");
		$start_entry->bindParam(":session_id", $this->session_id);
		$start_entry->bindParam(":study_id", $this->id);
		$start_entry->execute();
	}

	public function post($posted, $redirect = true) {

		unset($posted['id']); // cant overwrite your session
		unset($posted['session']); // cant overwrite your session
		unset($posted['session_id']); // cant overwrite your session ID
		unset($posted['study_id']); // cant overwrite your study ID
		unset($posted['created']); // cant overwrite
		unset($posted['modified']); // cant overwrite
		unset($posted['ended']); // cant overwrite
		
		$answered = $this->dbh->prepare("
			INSERT INTO `survey_items_display` (item_id, session_id, created, answered, answered_time, modified, displaycount) 
			VALUES(:item_id,  :session_id, NOW(), 1, NOW(), NOW(), 1) 
			ON DUPLICATE KEY UPDATE answered = 1, answered_time = NOW()");
		$answered->bindParam(":session_id", $this->session_id);

		try {
			$this->dbh->beginTransaction();
			foreach($posted AS $name => $value) {
				if (!isset($this->unanswered[$name])) {
					continue;
				}
	
				$value = $this->unanswered[$name]->validateInput($value);
				if(!$this->unanswered[$name]->error) {
					$this->errors[$name] = $this->unanswered[$name]->error;
					unset($this->unanswered[$name]);
					continue;
				}

				$item_saved = true;
				if($this->unanswered[$name]->save_in_results_table) {
					$post_form = $this->dbh->prepare("
						UPDATE `{$this->results_table}` SET `$name` = :$name 
						WHERE session_id = :session_id AND study_id = :study_id AND `$name` IS NULL;
					");

					$post_form->bindValue(":$name", $value);
					$post_form->bindValue(":session_id", $this->session_id);
					$post_form->bindValue(":study_id", $this->id);
					$item_saved = $post_form->execute();
				}

				$answered->bindParam(":item_id", $this->unanswered[$name]->id);
				$item_answered = $answered->execute();

				if (!$item_saved || !$item_answered) {
					throw new Exception("Survey item '$name' could not be saved with value '$value' in table '{$this->results_table}' (FieldType: {$this->unanswered[$name]->getResultField()})");
				}
				$this->unanswered[$name];
			} //endforeach
			$this->dbh->commit();
		} catch (Exception $e) {
			$this->dbh->rollBack();
			formr_log($e->getMessage());
			formr_log($e->getTraceAsString());
		}

		if(empty($this->errors) AND !empty($posted) AND $redirect) {
			// PRG
			redirect_to($this->run_name);
		}
		
	}
	protected function getProgress() {
		$query = "SELECT COUNT(`survey_items_display`.answered) AS count, study_id, session_id
					FROM 
						`survey_items` 
					LEFT JOIN `survey_items_display`
					ON `survey_items_display`.session_id = :session_id
					AND `survey_items`.id = `survey_items_display`.item_id
					
					WHERE 
					`survey_items_display`.session_id IS NOT NULL AND
					`survey_items`.study_id = :study_id AND
			        `survey_items`.type NOT IN (
							'mc_heading',
							'submit'
						)";
		$progress = $this->dbh->prepare($query);
		$progress->bindParam(":session_id", $this->session_id);
		$progress->bindParam(":study_id", $this->id);
		$progress->execute();

		$answered = $progress->fetch(PDO::FETCH_ASSOC);
		
		$this->already_answered = $answered['count'];

		$this->not_answered = array_filter($this->unanswered, function ($item)
		{
			if(
				$item->hidden OR  // item was skipped
				in_array($item->type, array('submit','mc_heading')) 		 // these items require no user interaction and thus don't count against progress
				OR ($item->type == 'note' AND $item->displaycount > 0) 		 // item is a note and has already been viewed
			)
				return false;
			else 
				return true;
		}
		);
		$this->not_answered = count( $this->not_answered );
		
// todo: in the medium term it may be more intuitive to treat notes as item that are answered by viewing but that can linger in a special case, might require less extra logic. but they shouldn't go in the results table.. so maybe not.
/*		$seen_notes = array_filter($this->unanswered, function ($item)
				{ // notes stay in the unanswered batch
					if(
						! $item->hidden											 // item wasn't skipped
						AND ($item->type == 'note' AND $item->displaycount > 0) 		 // item is a note and has already been viewed
					)
						return true;
					else 
						return false;
				}
		);
		$this->already_answered += count($seen_notes); 
*/		

		$all_items = $this->already_answered + $this->not_answered;
		
		#pr(array_filter($this->unanswered,'proper_type'));
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
	protected function getAndRenderChoices()
	{
		// get and render choices
		// todo: should only get & render choices for items on this page
		$get_item_choices = $this->dbh->prepare("SELECT list_name, name, label, label_parsed FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id 
		ORDER BY `survey_item_choices`.id ASC;");
		$get_item_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$get_item_choices->execute();
		$choice_lists = array();
		while($row = $get_item_choices->fetch(PDO::FETCH_ASSOC)):
			if(!isset($choice_lists[ $row['list_name'] ])):
				$choice_lists[ $row['list_name'] ] = array();
			endif;
			
			// fixme: because were not using this much yet, I haven't really made any effort to efficiently only calculate this when necessary
			if($row['label_parsed'] === null):
				$openCPU = $this->makeOpenCPU();
				$openCPU->admin_usage = $this->admin_usage;
		
				$openCPU->addUserData($this->getUserDataInRun(
					$this->dataNeeded($this->dbh, $row['label'])
				));
				
				$markdown = $openCPU->knitForUserDisplay($row['label']);
				
				if(mb_substr_count($markdown,"</p>")===1 AND preg_match("@^<p>(.+)</p>$@",trim($markdown),$matches)): // simple wraps are eliminated
					$row['label_parsed'] = $matches[1];
				else:
					$row['label_parsed'] = $markdown;
				endif;
			endif;
			$choice_lists[ $row['list_name'] ][$row['name']] = $row['label_parsed'];
		endwhile;
		return $choice_lists;	
	}
	
	/*
	* this function first restricts the number of items to walk through by only requesting those from the DB
		which have not yet been answered
		- first caveat: items like mc_heading, note and submit are special and don't just get eliminated when "answered"
	*  the function proceeds to get all choices and render them – there is room to economise here, but I considered this premature
		for now. to be clear: getting the choices has little cost, rendering them has high costs if they're dynamic (need openCPU), but we do that rarely if ever now.
	* 
	*/
	protected function getNextItems() {
		if(!isset($this->settings["maximum_number_displayed"]) OR trim($this->settings["maximum_number_displayed"])=="" OR !is_numeric($this->settings["maximum_number_displayed"]))
			$this->settings["maximum_number_displayed"] = null;
		
		$item_query = "SELECT 
				`survey_items`.id,
				`survey_items`.study_id,
				`survey_items`.type,
				`survey_items`.choice_list,
				`survey_items`.type_options,
				`survey_items`.name,
				`survey_items`.label,
				`survey_items`.label_parsed,
				`survey_items`.optional,
				`survey_items`.class,
				`survey_items`.showif,
				`survey_items`.value,
				`survey_items`.`order`,
				
		`survey_items_display`.displaycount, 
		`survey_items_display`.session_id,
		`survey_items_display`.answered
		
					FROM 
			`survey_items` LEFT JOIN `survey_items_display`
		ON `survey_items_display`.session_id = :session_id
		AND `survey_items`.id = `survey_items_display`.item_id
		WHERE 
		`survey_items`.study_id = :study_id AND
		(`survey_items_display`.answered IS NULL OR `survey_items`.type = 'note')
		ORDER BY `survey_items`.id ASC;";
		$get_items = $this->dbh->prepare($item_query);
		$get_items->bindParam(":session_id",$this->session_id);
		$get_items->bindParam(":study_id", $this->id);
		$get_items->execute();
		
		$choice_lists = $this->getAndRenderChoices();
		
		$this->item_factory = new ItemFactory($choice_lists);
		
		while($item_array = $get_items->fetch(PDO::FETCH_ASSOC) )
		{
			$name = $item_array['name'];
			$item = $this->item_factory->make($item_array);
			
			
			if(trim($item->showif) != null)
			{
				$show = $this->item_factory->showif($this, $item->showif);
			
				if( $show === null) // we don't know what happens yet, maybe JS, maybe not
				{
					$item->hide();
				}
				elseif( ! $show) // do not force this to be false, could be "0", 0, false
				{
					continue; // do not render, we know the result of this check, it's false!
				}
				elseif(isset( $this->item_factory->openCPU_errors[$item->showif] ))
				{
					$item->alwaysInvalid();
					$item->error = $this->item_factory->openCPU_errors[$item->showif];
				}


			}
			if(
				$item->no_user_input_required AND
				$item->needsDynamicValue()
			) // determine value if there is a dynamic one and no user input is required
			{
				$item->determineDynamicValue($this);
			}
			$this->unanswered[$name] = $item;

			if(! $item->hidden AND $item->no_user_input_required AND isset($item->input_attributes['value'])):
				$this->post( array($item->name => $item->input_attributes['value']), false); # add this data but don't reload
			endif;
			
		}
		
	}
	protected function renderNextItems() {
	
		$this->dbh->beginTransaction();
		
		$view_query = "INSERT INTO `survey_items_display` (item_id,  session_id, displaycount, created, modified)
											     VALUES(:item_id, :session_id, 1,				 NOW(), NOW()	) 
		ON DUPLICATE KEY UPDATE displaycount = displaycount + 1, modified = NOW()";
		$view_update = $this->dbh->prepare($view_query);
		$view_update->bindValue(":session_id", $this->session_id);
		
		$itemsDisplayed = 0;
		$item_will_be_rendered = true;
		
		$this->rendered_items = array();
		foreach($this->unanswered AS &$item)
		{	
			if($this->settings['maximum_number_displayed'] != null AND
				$itemsDisplayed >= $this->settings['maximum_number_displayed'])
			{
				$item_will_be_rendered = false;
			}
			if($item_will_be_rendered)
			{
				if ($item->type === 'submit')
				{
					if($itemsDisplayed === 0):
						continue; // skip submit buttons once everything before them was dealt with	
					else:
						$item_will_be_rendered = false;
					endif;
				}
				elseif ($item->type === "note")
				{
					$next = current($this->unanswered);
					if(
						$item->displaycount > 0 AND 											 // if this was displayed before
						(
							$next === false OR 								    				 // this is the end of the survey
							$next->hidden === true OR 								    				 // the next item is hidden // todo: should actually be checking if all following items up to the next note are hidden, but at least it's displayed once like this and doesn't block progress
							in_array( $next->type , array('note','submit','mc_heading'))  		 // the next item isn't a normal item
						)
					)
					{
						continue; // skip this note							
					}
				}
				else if ($item->type === "mc_heading")
				{
					$next = current($this->unanswered);
					if(
						(
							$next === false OR 								    				 // this is the end of the survey
							$next->hidden === true OR 								    				 // the next item is hidden // todo: same as above
							!in_array( $next->type , array('mc','mc_multiple','mc_button','mc_multiple_button'))  		 // the next item isn't a mc item
						)
					)
					{
						continue; // skip this mc_heading
					}
				}
				
				if(! $item->hidden)
				{
			
					$item->viewedBy($view_update);
					$itemsDisplayed++;
				}
				
			
				$this->rendered_items[] = $item;
			}
		}
		$this->dbh->commit();
		$this->not_answered_on_current_page = array_filter($this->rendered_items, function ($item)
		{
			if(
				$item->hidden OR  // item was skipped
				in_array($item->type, array('submit','mc_heading')) 		 // these items require no user interaction and thus don't count against progress
				OR ($item->type == 'note' AND $item->displaycount > 0) 		 // item is a note and has already been viewed
			)
				return false;
			else 
				return true;
		}
		);
		$this->not_answered_on_current_page = count( $this->not_answered_on_current_page );
	}
	protected function render_form_header() {
		$action = WEBROOT."{$this->run_name}";

		$enctype = ' enctype="multipart/form-data"'; # maybe make this conditional application/x-www-form-urlencoded
		$ret = '<form action="'.$action.'" method="post" class="form-horizontal" accept-charset="utf-8"'.$enctype.'>';
		
	    /* pass on hidden values */
	    $ret .= '<input type="hidden" name="session_id" value="' . $this->session_id . '" />';
	
		if(!isset($this->settings["displayed_percentage_maximum"]) OR $this->settings["displayed_percentage_maximum"] == 0)
			$this->settings["displayed_percentage_maximum"] = 100;
		
		$prog = $this->progress *  // the fraction of this survey that was completed
			($this->settings["displayed_percentage_maximum"] - // is multiplied with the stretch of percentage that it was accorded
				$this->settings["add_percentage_points"]);
		
		if(isset($this->settings["add_percentage_points"])):
			$prog += $this->settings["add_percentage_points"];
		endif;
		if($prog > $this->settings["displayed_percentage_maximum"]):
			$prog = $this->settings["displayed_percentage_maximum"];
		endif;
		
		$prog = round($prog);
		
	    $ret .= '<div class="container progress-container">
			<div class="progress">
				  <div data-percentage-minimum="'.$this->settings["add_percentage_points"].'" data-percentage-maximum="'.$this->settings["displayed_percentage_maximum"].'" data-already-answered="'.$this->already_answered.'"  data-items-left="'.($this->not_answered - $this->not_answered_on_current_page).'" class="progress-bar" style="width: '.$prog.'%;">'.$prog.'%</div>
			</div>
			</div>';
		
		if(!empty($this->errors))
			$ret .= '<div class="form-group has-error form-message">
				<div class="control-label"><i class="fa fa-exclamation-triangle pull-left fa-2x"></i>'.implode("<br>",array_unique($this->errors)).'
				</div></div>';	
		return $ret;
	
	}

	protected function render_items() 
	{
		$ret = '';

	    foreach($this->rendered_items AS $item) 
		{
			if(
				!$item->no_user_input_required AND
				$item->needsDynamicValue()
			) // determine value if there is a dynamic one and no user input is required
			{
				$item->determineDynamicValue($this);
			}
			if($item->needsDynamicLabel() )  // item label has to be dynamically generated with user data
			{
				$item->determineDynamicLabel($this);
			}
			$ret .= $item->render();
	    }
		
		
		if(isset($item) AND $item->type !== "submit") // if the last item was not a submit button, add a default one
		{
			$sub_sets = array('label_parsed' => '<i class="fa fa-arrow-circle-right pull-left fa-2x"></i> Go on to the<br>next page!', 'class_input' => 'btn-info');
			$item = new Item_submit($sub_sets);
			$ret .= $item->render();
		}
		
		return $ret;
	}

	protected function render_form_footer() 
	{
	    return "</form>"; /* close form */
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
		$post_form->execute();
		
		return parent::end();
	}
	public function exec()
	{
		if($this->called_by_cron)
			return true; // never show to the cronjob
		
		
		$this->startEntry();
		
		$this->getNextItems();

		$this->post(array_merge($_POST,$_FILES));

		if($this->getProgress()===1)
		{
			$this->end();
			return false;
		}

		$this->renderNextItems();
		
		
		return array('title' => null,
		'body' => $this->render());
	}

	
	public function changeSettings($key_value_pairs)
	{
		$errors = false;
		if(isset($key_value_pairs['maximum_number_displayed']) 
			AND $key_value_pairs['maximum_number_displayed'] = (int)$key_value_pairs['maximum_number_displayed'] 
			AND $key_value_pairs['maximum_number_displayed'] > 3000
			|| $key_value_pairs['maximum_number_displayed'] < 1
			):
			alert("Maximum number displayed has to be between 1 and 65535", 'alert-warning');
			$errors = true;
		endif;
		if(isset($key_value_pairs['displayed_percentage_maximum']) 
			AND $key_value_pairs['displayed_percentage_maximum'] = (int)$key_value_pairs['displayed_percentage_maximum'] 
			AND $key_value_pairs['displayed_percentage_maximum'] > 100
			|| $key_value_pairs['displayed_percentage_maximum'] < 1
		):
			alert("Percentage maximum has to be between 1 and 100.", 'alert-warning');
			$errors = true;
		endif;
		if(isset($key_value_pairs['add_percentage_points']) 
			AND $key_value_pairs['add_percentage_points'] = (int)$key_value_pairs['add_percentage_points'] 
			AND $key_value_pairs['add_percentage_points'] > 100
			|| $key_value_pairs['add_percentage_points'] < 0
			):
			alert("Percentage points added has to be between 0 and 100.", 'alert-warning');
			$errors = true;
		endif;
		if($errors) return false;
		
		$this->dbh->beginTransaction();
		$post_form = $this->dbh->prepare("UPDATE `survey_studies` SET
			`maximum_number_displayed` = :maximum_number_displayed, 
			`displayed_percentage_maximum` = :displayed_percentage_maximum,
			`add_percentage_points` = :add_percentage_points
			WHERE `id` = :study_id");
		
	    $post_form->bindParam(":study_id", $this->id);
		foreach($key_value_pairs AS $key => $value)
		{
		    $post_form->bindValue(":$key", $value);
		}
		$post_form->execute();

		$this->dbh->commit();
	}
	public function uploadItemTable($file, $confirmed_deletion)
	{	
		if(trim($confirmed_deletion) == ''):
			$this->confirmed_deletion = false;
		elseif($confirmed_deletion === $this->name):
			$this->confirmed_deletion = true;
		else:
			alert("<strong>Error:</strong> You confirmed the deletion of the study's results but your input did not match the study's name. Update aborted.", 'alert-danger');
			$this->confirmed_deletion = false;
			return false;
		endif;
			
		umask(0002);
		ini_set('memory_limit', '256M');
		$target = $_FILES['uploaded']['tmp_name'];
		$filename = $_FILES['uploaded']['name'];

		$this->messages[] = "File <b>$filename</b> was uploaded.";
		$this->messages[] = "Survey name was determined to be <b>{$this->name}</b>.";
		
		require_once INCLUDE_ROOT.'Model/SpreadsheetReader.php';

		$SPR = new SpreadsheetReader();
		$SPR->readItemTableFile($target);
		$this->errors = array_merge($this->errors, $SPR->errors);
		$this->warnings =  array_merge($this->warnings, $SPR->warnings);
		$this->messages =  array_merge($this->messages, $SPR->messages);
		$this->messages = array_unique($this->messages);
		$this->warnings = array_unique($this->warnings);
		
		// if items are ok, make actual survey
	    if (empty($this->errors) AND $this->createSurvey($SPR) ):
			
			if(!empty($this->warnings))
				alert('<ul><li>' . implode("</li><li>",$this->warnings).'</li></ul>','alert-warning');
			
			if(!empty($this->messages))
				alert('<ul><li>' . implode("</li><li>",$this->messages).'</li></ul>','alert-info');
			
			return true;
		else:
			alert('<ul><li>' . implode("</li><li>",$this->errors).'</li></ul>','alert-danger');
			return false;
		endif;
	}
	protected function existsByName($name)
	{
		if(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/",$name)) return;
		
		$exists = $this->dbh->prepare("SELECT name FROM `survey_studies` WHERE name = :name LIMIT 1");
		$exists->bindParam(':name',$name);
		$exists->execute();
		if($exists->rowCount())
			return true;
		
		$reserved = $this->dbh->query("SHOW TABLES LIKE '$name';");
		if($reserved->rowCount())
			return true;

		return false;
	}
	protected function hasResultsTable()
	{
		$reserved = $this->dbh->query("SHOW TABLES LIKE '$this->results_table';");
		if($reserved->rowCount())
			return true;

		return false;
	}
	
	/* ADMIN functions */
	public function create($options)
	{
		// this unit type is a bit special
		// all other unit types are created only within runs
		// but surveys are semi-independent of runs
		// so it is possible to add a survey, without specifying which one at first
		// and to then choose one.
		
		// thus, we "mock" a survey at first
		if(count($options)===1):
			$this->valid = true;
		else: // and link it to the run only later
			$this->id = $options['unit_id'];
			if($this->linkToRun())
				$this->load();
		endif;
	}
	public function createIndependently()
	{
	    $name = trim($this->unit['name']);
	    if($name == ""):
			alert(_("<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from <strong>a</strong> to <strong>Z</strong>, <strong>0</strong> to <strong>9</strong> and the underscore. The name has to at least 2, at most 64 characters long. It needs to start with a letter. No dots, no spaces, no dashes, no umlauts please. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>, but they will be ignored."), 'alert-danger');
			return false;
		elseif(!preg_match("/[a-zA-Z][a-zA-Z0-9_]{2,64}/",$name)):
			alert('<strong>Error:</strong> The study name (the name of the file you uploaded) can only contain the characters from a to Z, 0 to 9 and the underscore. It needs to start with a letter. The file can have version numbers after a dash, like this <code>survey_1-v2.xlsx</code>.','alert-danger');
			return false;
		elseif($this->existsByName($name)):
			alert(__("<strong>Error:</strong> The survey name %s is already taken.",h($name)), 'alert-danger');
			return false;
		endif;

		$this->dbh->beginTransaction();
		$this->id = parent::create('Survey');
		$create = $this->dbh->prepare("INSERT INTO `survey_studies` (id, user_id,name) VALUES (:run_item_id, :user_id,:name);");
		$create->bindParam(':run_item_id',$this->id);
		$create->bindParam(':user_id',$this->unit['user_id']);
		$create->bindParam(':name',$name);
		$create->execute();
		$this->dbh->commit();
		
		$this->name = $name;
		
		$this->changeSettings(array
			(
				"maximum_number_displayed" => 0,
				"displayed_percentage_maximum" => 100,
				"add_percentage_points" => 0,
			)
		);
		
		return true;
	}
	protected $user_defined_columns = array(
		'name', 'label', 'label_parsed', 'type',  'type_options', 'choice_list', 'optional', 'class' ,'showif', 'value', 'order' // study_id is not among the user_defined columns
	);
	protected $choices_user_defined_columns = array(
		'list_name', 'name', 'label', 'label_parsed' // study_id is not among the user_defined columns
	);
	public function getChoices()
	{
		$get_item_choices = $this->dbh->prepare("SELECT list_name, name, label FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id 
		ORDER BY `survey_item_choices`.id ASC;");
		$get_item_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$get_item_choices->execute();
		$choice_lists = array();
		while($row = $get_item_choices->fetch(PDO::FETCH_ASSOC)):
			if(!isset($choice_lists[ $row['list_name'] ]))
				$choice_lists[ $row['list_name'] ] = array();
			
			$choice_lists[ $row['list_name'] ][$row['name']] = $row['label'];
		endwhile;
		return $choice_lists;	
	}
	public function getChoicesForSheet()
	{
		$get_item_choices = $this->dbh->prepare("SELECT list_name, name, label FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id 
		ORDER BY `survey_item_choices`.id ASC;");
		$get_item_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$get_item_choices->execute();

		$results = array();
		while($row = $get_item_choices->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function createSurvey($SPR) {
		$this->SPR = $SPR;
		
		
		$this->dbh->beginTransaction();
		
		$old_syntax = $this->getOldSyntax();
		$this->parsedown = new ParsedownExtra();
		$this->parsedown->setBreaksEnabled(true);
		
		$this->addChoices();
		/**
		$delete_old_items = $this->dbh->prepare("DELETE FROM `survey_items` WHERE `survey_items`.study_id = :study_id");
		$delete_old_items->bindParam(":study_id", $this->id); // delete cascades to item display
		$delete_old_items->execute();
		 */

		$UPDATES = implode(', ', get_duplicate_update_string($this->user_defined_columns));
		$add_items = $this->dbh->prepare("
			INSERT INTO `survey_items` (study_id, name, label, label_parsed, type, type_options, choice_list, optional, class, showif, value, `order`) 
			VALUES (:study_id, :name, :label, :label_parsed, :type, :type_options, :choice_list, :optional, :class, :showif, :value, :order
		) ON DUPLICATE KEY UPDATE $UPDATES");

		$result_columns = array();
		$add_items->bindParam(":study_id", $this->id);

		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);

		foreach($this->SPR->survey as $row_number => $row) {
			$item = $this->item_factory->make($row);
			if (!$item) {
				$this->errors[] = __("Row %s: Type %s is invalid.",$row_number,$this->SPR->survey[$row_number]['type']);
				unset($this->SPR->survey[$row_number]);
				continue;
			}

			$val_errors = $item->validate();
			if (!empty($val_errors)) {
				$this->errors = $this->errors + $val_errors;
				unset($this->SPR->survey[$row_number]);
				continue;
			}

			if(!$this->knittingNeeded($item->label)): // if the parsed label is constant
				$markdown = $this->parsedown->text($item->label);
				$item->label_parsed = $markdown;
				if(mb_substr_count($markdown, "</p>") === 1 AND preg_match("@^<p>(.+)</p>$@", trim($markdown), $matches)) {
					$item->label_parsed = $matches[1];
				}
			endif;

			foreach ($this->user_defined_columns as $param) {
				$add_items->bindValue(":$param", $item->$param);
			}

			$result_columns[] = $item->getResultField();			
			$add_items->execute();
			formr_log($add_items->queryString);
		}

		$unused = $this->item_factory->unusedChoiceLists();
		if(! empty( $unused ) ):
			$this->warnings[] = __("These choice lists were not used: '%s'", implode("', '",$unused));
		endif;
	
		$new_syntax = $this->getResultsTableSyntax($result_columns);
		
		if($this->hasResultsTable()) {
			$resultCount = $this->getResultCount();
		
			if($new_syntax !== $old_syntax AND $resultCount['finished'] > 0)  {
			// if the results table would be recreated and there are results
				if(! $this->confirmed_deletion) {
					$this->errors[] = "The results table would have to be deleted, but you did not confirm deletion of results.";
				}
			}
		}
		
		if(!empty($this->errors)) {
			$this->dbh->rollBack();
			$this->errors[] = "All changes were rolled back";
			return false;
		} elseif ($this->dbh->commit()) {
			//$this->messages[] = $delete_old_items->rowCount() . " old items were replaced with " . count($this->SPR->survey) . " new items.";
			
			if($new_syntax !== $old_syntax) {
				$this->warnings[] = "A new results table was created.";
				return $this->createResultsTable($new_syntax);
			} else {
				$this->messages[] = "<strong>The old results table was kept.</strong>";
				return true;
			}
		}
		return false;
	}

	public function getItemsWithChoices() {
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);
		
		$raw_items = $this->getItems();
		
		
		$items = array();
		foreach($raw_items as $row) 
		{
			$item = $this->item_factory->make($row);
			$items[$item->name] = $item;
		}
		return $items;
	}

	private function addChoices() {
		$delete_old_choices = $this->dbh->prepare("DELETE FROM `survey_item_choices` WHERE `survey_item_choices`.study_id = :study_id");
		$delete_old_choices->bindParam(":study_id", $this->id); // delete cascades to item display
		$delete_old_choices->execute();
	
		$add_choices = $this->dbh->prepare('INSERT INTO `survey_item_choices` (
			study_id,
	        list_name,
			name,
	        label,
			label_parsed
		) VALUES (
			:study_id,
			:list_name,
			:name,
			:label,
			:label_parsed
		)');
		$add_choices->bindParam(":study_id", $this->id);
		
		foreach($this->SPR->choices AS $choice)
		{
			if(!$this->knittingNeeded( $choice['label'] )): // if the parsed label is constant
				$markdown = $this->parsedown->text($choice['label']); // transform upon insertion into db instead of at runtime

				if(mb_substr_count($markdown,"</p>")===1 AND preg_match("@^<p>(.+)</p>$@",trim($markdown),$matches)):
					$choice['label_parsed'] = $matches[1];
				else:
					$choice['label_parsed'] = $markdown;
				endif;
			endif;
			
			foreach ($this->choices_user_defined_columns as $param) 
			{
				$add_choices->bindParam(":$param", $choice[ $param ]);
			}
			$add_choices->execute();
		}
		$this->messages[] = $delete_old_choices->rowCount() . " old choices deleted.";
		$this->messages[] = count($this->SPR->choices) . " choices were successfully loaded.";
		
		return true;
	}
	private function getResultsTableSyntax($columns)
	{
		$columns = array_filter($columns); // remove NULL, false, '' values (note, fork, submit, ...)
		
		if(empty($columns))
			$columns_string = ''; # create a results tabel with only the access times
		else
			$columns_string = implode(",\n", $columns).",";
		
		$create = "CREATE TABLE `{$this->name}` (
		  `session_id` INT UNSIGNED NOT NULL ,
		  `study_id` INT UNSIGNED NOT NULL ,
		  `created` DATETIME NULL DEFAULT NULL ,
		  `modified` DATETIME NULL DEFAULT NULL ,
		  `ended` DATETIME NULL DEFAULT NULL ,
	
		  $columns_string
		  
		  INDEX `fk_survey_results_survey_unit_sessions1_idx` (`session_id` ASC) ,
		  INDEX `fk_survey_results_survey_studies1_idx` (`study_id` ASC) ,
		  PRIMARY KEY (`session_id`) ,
		  INDEX `ending` (`session_id` DESC, `study_id` ASC, `ended` ASC) ,
		  CONSTRAINT `fk_{$this->name}_survey_unit_sessions1`
		    FOREIGN KEY (`session_id` )
		    REFERENCES `survey_unit_sessions` (`id` )
		    ON DELETE CASCADE
		    ON UPDATE NO ACTION,
		  CONSTRAINT `fk_{$this->name}_survey_studies1`
		    FOREIGN KEY (`study_id` )
		    REFERENCES `survey_studies` (`id` )
		    ON DELETE NO ACTION
		    ON UPDATE NO ACTION)
		ENGINE = InnoDB";
		return $create;
	}
	private function getOldSyntax()
	{
		$resC = $this->getResultCount();
		if($resC == array('finished' => 0, 'begun' => 0)):
			$this->messages[] = __("The results table was empty.",array_sum($resC));
			return null;
		endif;
		
		$old_items = $this->getItems();
		require_once INCLUDE_ROOT."Model/Item.php";
		
		$choice_lists = $this->getChoices();
		$this->item_factory = new ItemFactory($choice_lists);
		
		$old_result_columns = array();
		foreach($old_items AS $row)
		{
			$item = $this->item_factory->make($row);
			if(!$item)
			{
				if(isset($row['type'])) $type = $row['type'];
				else $type = "<em>missing</em>";
				alert("While trying to recreate old results table: Item type ".h($row['type']) . " not found.", 'alert-danger');
				return false;
			}
			$old_result_columns[] = $item->getResultField();
		}
		
		return $this->getResultsTableSyntax($old_result_columns);
	}
	private function createResultsTable($syntax)
	{
		if($this->deleteResults()):
			$drop = $this->dbh->query("DROP TABLE IF EXISTS `{$this->name}` ;");
			$drop->execute();
		else:
			return false;
		endif;
		
		$create_table = $this->dbh->query($syntax);
		if($create_table)
			return true;
		else return false;
	}
	public function getItems()
	{
		$get_items = $this->dbh->prepare("SELECT id,study_id,type,choice_list,type_options,name,label,label_parsed,optional,class,showif,value,`order` FROM `survey_items` WHERE `survey_items`.study_id = :study_id ORDER BY id ASC");
		$get_items->bindParam(":study_id", $this->id);
		$get_items->execute();

		$results = array();
		while($row = $get_items->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function getItemsForSheet()
	{
		$get_items = $this->dbh->prepare("SELECT type,type_options,choice_list,name,label,optional,class,showif,value,`order` FROM `survey_items` WHERE `survey_items`.study_id = :study_id ORDER BY id ASC");
		$get_items->bindParam(":study_id", $this->id);
		$get_items->execute();

		$results = array();
		while($row = $get_items->fetch(PDO::FETCH_ASSOC)):
			$row["type"] = $row["type"] ." ". $row["type_options"] ." ". $row["choice_list"];
			unset($row["choice_list"]);
			unset($row["type_options"]);
			$results[] = $row;
		endwhile;
		
		return $results;
	}
	public function countResults()
	{
		$get = "SELECT COUNT(*) AS count FROM `{$this->name}`";
		$get = $this->dbh->query($get);
		$results = array();
		$row = $get->fetch(PDO::FETCH_ASSOC);
		$this->result_count = $row['count'];
		return $row['count'];
	}
	public function getResults()
	{ // fixme: shouldnt be using wildcard operator here.
		$get = "SELECT `survey_run_sessions`.session, `{$this->name}`.* FROM `{$this->name}`
		LEFT JOIN `survey_unit_sessions`
		ON  `{$this->name}`.session_id = `survey_unit_sessions`.id
		LEFT JOIN `survey_run_sessions`
		ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id";
		$get = $this->dbh->query($get);
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC)):
			unset($row['study_id']);
			$results[] = $row;
		endwhile;
		
		return $results;
	}
	public function getItemDisplayResults()
	{
		$get = "SELECT `survey_run_sessions`.session,`survey_items`.name, 
		`survey_items_display`.id,
		`survey_items_display`.item_id,
		`survey_items_display`.session_id,
		`survey_items_display`.created,
		`survey_items_display`.modified,
		`survey_items_display`.answered_time,
		`survey_items_display`.answered,
		`survey_items_display`.displaycount
		 
		FROM `survey_items_display` 
		
		LEFT JOIN `survey_unit_sessions`
		ON `survey_unit_sessions`.id = `survey_items_display`.session_id
		
		LEFT JOIN `survey_run_sessions`
		ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
		
		LEFT JOIN `survey_items`
		ON `survey_items_display`.item_id = `survey_items`.id
		
		WHERE `survey_items`.study_id = :study_id
		ORDER BY `survey_run_sessions`.session, `survey_run_sessions`.created, `survey_items_display`.item_id";
		$get = $this->dbh->prepare($get);
		$get->bindParam(':study_id',$this->id);
		$get->execute();
		$results = array();
		while($row = $get->fetch(PDO::FETCH_ASSOC))
			$results[] = $row;
		
		return $results;
	}
	public function deleteResults()
	{
		$resC = $this->getResultCount();
		if($resC['finished'] > 10):
			if($this->backupResults()):
				$this->warnings[] = __("%s results rows were backed up.",array_sum($resC));
			else:
				$this->errors[] = __("Backup of %s result rows failed. Deletion cancelled.",array_sum($resC));
				return false;
			endif;
		elseif($resC == array('finished' => 0, 'begun' => 0)):
			return true;		
		else:
			$this->warnings[] = __("%s results rows were deleted.",array_sum($resC));
		endif;
		
		$delete = $this->dbh->query("TRUNCATE TABLE `{$this->name}`");
		
		$delete_sessions = $this->dbh->prepare ( "DELETE FROM `survey_unit_sessions` 
		WHERE `unit_id` = :study_id" );
		$delete_sessions->bindParam(':study_id',$this->id);
		$delete_sessions->execute();
		
		return $delete;
	}
	public function backupResults()
	{
		$filename = $this->name . date('YmdHis') . ".tab";
		if(isset($this->user_id)) $filename = "user" . $this->user_id . $filename;
        $filename = INCLUDE_ROOT ."tmp/backups/results/". $filename;
		require_once INCLUDE_ROOT . 'Model/SpreadsheetReader.php';

		$SPR = new SpreadsheetReader();
		return $SPR->backupTSV( $this->getResults() , $filename);
	}
	public function getResultCount()
	{
		if($this->dbh->table_exists($this->name)):
			$get = "SELECT SUM(`{$this->name}`.ended IS NULL) AS begun, SUM(`{$this->name}`.ended IS NOT NULL) AS finished FROM `{$this->name}` 
			LEFT JOIN `survey_unit_sessions`
			ON `survey_unit_sessions`.id = `{$this->name}`.session_id";
			$get = $this->dbh->query($get);
			return $get->fetch(PDO::FETCH_ASSOC);
		else:
			return array('finished' => 0, 'begun' => 0);
		endif;
	}
	public function getAverageTimeItTakes()
	{
		$get = "SELECT AVG(middle_values) AS 'median' FROM (
		  SELECT took AS 'middle_values' FROM
		    (
		      SELECT @row:=@row+1 as `row`, (x.ended - x.created) AS took
		      FROM `{$this->name}` AS x, (SELECT @row:=0) AS r
		      WHERE 1
		      -- put some where clause here
		      ORDER BY took
		    ) AS t1,
		    (
		      SELECT COUNT(*) as 'count'
		      FROM `{$this->name}` x
		      WHERE 1
		      -- put same where clause here
		    ) AS t2
		    -- the following condition will return 1 record for odd number sets, or 2 records for even number sets.
		    WHERE t1.row >= t2.count/2 and t1.row <= ((t2.count/2) +1)) AS t3;";
		$get = $this->dbh->query($get);
		$time = $get->fetch(PDO::FETCH_NUM);
		$time = round($time[0] / 60, 3); # seconds to minutes
		
		return $time;
	}
	public function delete()
	{
		if($this->deleteResults()): // always back up
			$delete_results = $this->dbh->query("DROP TABLE IF EXISTS `{$this->name}`");
			return parent::delete();
		endif;
		return false;
	}
	public function displayForRun($prepend = '')
	{
		if($this->id):
			$resultCount = $this->howManyReachedItNumbers();

			$time = $this->getAverageTimeItTakes();
			
			$dialog = "<h3>
				<strong>Survey:</strong> <a href='".WEBROOT."admin/survey/{$this->name}/index'>{$this->name}</a><br>
			<small>".(int)$resultCount['finished']." complete results,
		".(int)$resultCount['begun']." begun</small><br>
			<small title='Median duration that it takes to complete the survey, only completers accounted for'>Median duration: $time minutes</small>
			</h3>
			<p>
			<p class='btn-group'>
				<a class='btn' href='".WEBROOT."admin/survey/{$this->name}/show_results'>View results</a>
				<a class='btn' href='".WEBROOT."admin/survey/{$this->name}/show_item_table'>View items</a>
				<a class='btn' href='".WEBROOT."admin/survey/{$this->name}/access'>Test</a>
			</p>";
		else:
			$dialog = '';
			$g_studies = $this->dbh->prepare("SELECT * FROM `survey_studies` WHERE user_id = :user_id");
			global $user;
			$g_studies->bindValue(':user_id',$user->id);
			$g_studies->execute();
			
			
			$studies = array();
			while($study = $g_studies->fetch())
				$studies[] = $study;
			if($studies):
				$dialog = '<div class="form-group">
				<select class="select2" name="unit_id" style="width:300px">
				<option value=""></option>';
				foreach($studies as $study):
				    $dialog .= "<option value=\"{$study['id']}\">{$study['name']}</option>";
				endforeach;
				$dialog .= "</select>";
				$dialog .= '<a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Survey">Add to this run.</a></div>';
			else:
				$dialog .= "<h5>No studies. Add some first</h5>";
			endif;
		endif;
		$dialog = $prepend . $dialog;
		return parent::runDialog($dialog,'fa-pencil-square');
	}
}