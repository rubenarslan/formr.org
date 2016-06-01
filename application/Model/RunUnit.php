<?php

class RunUnitFactory {

	protected $supported = array('Survey', 'Pause', 'Email', 'External', 'Page', 'SkipBackward', 'SkipForward', 'Shuffle');

	public function make($dbh, $session, $unit, $run_session = NULL, $run = NULL) {
		if (empty($unit['type'])) {
			$unit['type'] = 'Survey';
		}
		$type = $unit['type'];

		if (!in_array($type, $this->supported)) {
			throw new Exception("Unsupported unit type '$type'");
		}

		return new $type($dbh, $session, $unit, $run_session, $run);
	}

	public function getSupportedUnits() {
		return $this->supported;
	}

}

class RunUnit {

	public $errors = array();
	public $id = null;
	public $user_id = null;
	public $run_unit_id = null; // this is the ID of the unit-to-run-link entry
	public $session = null;
	public $unit = null;
	public $ended = false;
	public $expired = false;
	public $position;
	public $called_by_cron = false;
	public $knitr = false;
	public $session_id = null;
	public $run_session_id = null;
	public $type = '';
	public $icon = 'fa-wrench';
	public $special = false;
	public $valid;
	public $run_id;
	public $description = "";
	protected $had_major_changes = false;

	/**
	 * An array of unit's exportable attributes
	 * @var array
	 */
	public $export_attribs = array('type', 'description', 'position', 'special');

	/**
	 * @var RunSession
	 */
	public $run_session;

	/**
	 * @var Run
	 */
	public $run;

	/**
	 * parsed body if unit
	 * @var string
	 */
	protected $body_parsed = null;

	/**
	 * Array of tables that contain user/study data to be used when parsing variables
	 * indexed by table names with values being an array of columns of interest
	 *
	 * @var array
	 */
	protected $non_user_tables = array(
		'survey_users' => array("created","modified", "user_code","email","email_verified","mobile_number", "mobile_verified"),
		'survey_run_sessions' => array("session","created","last_access","position","current_unit_id", "deactivated","no_email"),
		'survey_unit_sessions' => array("created","ended",'expired',"unit_id", "position", "type"),
		'externals' => array("created","ended",'expired', "position"),
		'survey_items_display' => array("created","answered_time","answered","displaycount","item_id"),
		'survey_email_log' => array("email_id","created","recipient"),
		'shuffle' => array("unit_id","created","group"),
	);

	/**
	 * Array of tables that contain system session data to be used when parsing variables
	 *
	 * @var array
	 */
	protected $non_session_tables = array('survey_users', 'survey_run_sessions', 'survey_unit_sessions');

	/**
	 * collects strings to parse on opencpu before rendering the unit
	 * @var array
	 */
	protected $strings_to_parse = array();

	/**
	 * @var DB
	 */
	protected $dbh;

	public function __construct($fdb, $session = null, $unit = null, $run_session = null, $run = NULL) {
		$this->dbh = $fdb;
		$this->session = $session;
		$this->unit = $unit;
		$this->run_session = $run_session;
		$this->run = $run;

		if (isset($unit['run_id'])) {
			$this->run_id = $unit['run_id'];
		}

		if (isset($unit['run_unit_id'])) {
			$this->run_unit_id = $unit['run_unit_id'];
		} elseif (isset($unit['id'])) {
			$this->run_unit_id = $unit['id'];
		}

		if (isset($unit['run_name'])) {
			$this->run_name = $unit['run_name'];
		}

		if (isset($unit['session_id'])) {
			$this->session_id = $unit['session_id'];
		}

		if (isset($unit['run_session_id'])) {
			$this->run_session_id = $unit['run_session_id'];
		}

		if (isset($this->unit['unit_id'])) {
			$this->id = $this->unit['unit_id'];
			$vars = $this->dbh->findRow('survey_units', array('id' => $this->id), 'created, modified, type');
			if ($vars):
				$this->modified = $vars['modified'];
				$this->created = $vars['created'];
			endif;
		}

		if (isset($this->unit['position'])) {
			$this->position = (int) $this->unit['position'];
		}
		if (isset($this->unit['description'])) {
			$this->description = $this->unit['description'];
		}

		if (isset($this->unit['special'])) {
			$this->special = $this->unit['special'];
		}

		if (isset($this->unit['cron'])) {
			$this->called_by_cron = true;
		}
	}

	public function create($type) {
		$id = $this->dbh->insert('survey_units', array(
			'type' => $type,
			'created' => mysql_now(),
			'modified' => mysql_now(),
		));
		$this->unit_id = $id;
		return $this->unit_id;
	}

	public function modify($options = array()) {
		$change = array('modified' => mysql_now());
		if($this->run_unit_id && isset($options['description'])):
			$this->dbh->update('survey_run_units', array("description" => $options['description']), array('id' => $this->run_unit_id ));
			$this->description = $options['description'];
		endif;
		return $this->dbh->update('survey_units', $change, array('id' => $this->id));
	}

	protected function beingTestedByOwner() {
		if ($this->run_session === null OR $this->run_session->user_id == $this->run_session->run_owner_id) {
			return true;
		}
		return false;
	}

	public function linkToRun() {
		return $this->dbh->update('survey_run_units', 
				array('unit_id' => $this->id), 
				array('id' => $this->run_unit_id), 
				array('int'), array('int')
		);
	}

	public function addToRun($run_id, $position = 10, $options = array("description" => '')) {
		if (!is_numeric($position)) {
			$position = 10;
		}
		$this->position = (int) $position;
		if(!isset($options['description'])) {
			$options['description'] = '';
		}

		$this->run_unit_id = $this->dbh->insert('survey_run_units', array(
			'unit_id' => $this->id,
			'run_id' => $run_id,
			'position' => $position,
			'description' => $options['description']
		));
		return $this->run_unit_id;
	}

	public function removeFromRun() {
		return $this->dbh->delete('survey_run_units', array('id' => $this->run_unit_id));
	}

	public function delete() {
		$affected = $this->dbh->delete('survey_units', array('id' => $this->id));
		if ($affected): // remove from all runs
			$affected += $this->dbh->delete('survey_run_units', array('unit_id' => $this->id));
		endif;

		return $affected;
	}

	public function end() { // todo: logically this should be part of the Unit Session Model, but I messed up my logic somehow
		$ended = $this->dbh->exec(
			"UPDATE `survey_unit_sessions` SET `ended` = NOW() WHERE `id` = :session_id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1", 
			array('session_id' => $this->session_id, 'unit_id' => $this->id)
		);

		if ($ended === 1) {
			$this->ended = true;
			return true;
		}

		return false;
	}

	public function expire() { // todo: logically this should be part of the Unit Session Model, but I messed up my logic somehow
		$expired = $this->dbh->exec(
			"UPDATE `survey_unit_sessions` SET `expired` = NOW() WHERE `id` = :session_id AND `unit_id` = :unit_id AND `ended` IS NULL LIMIT 1", 
			array('session_id' => $this->session_id, 'unit_id' => $this->id)
		);

		if ($expired === 1) {
			$this->expired = true;
			return true;
		}

		return false;
	}

	protected function getSampleSessions() {
		$current_position = -9999999;
		if (isset($this->unit['position'])) {
			$current_position = $this->unit['position'];
		}
		$results = $this->dbh->select('session, id, position')
			->from('survey_run_sessions')
			->order('position', 'desc')->order('RAND')
			->where(array('run_id' => $this->run_id, 'position >=' => $current_position))
			->limit(20)->fetchAll();
		
		if (!$results) {
			alert('No data to compare to yet.','alert-info');
			return false;
		}
		return $results;
	}

	protected function grabRandomSession() {
		if ($this->run_session_id === NULL) {
			$current_position = -9999999;
			if (isset($this->unit['position'])) {
				$current_position = $this->unit['position'];
			}

			$temp_user = $this->dbh->select('session, id, position')
				->from('survey_run_sessions')
				->order('position', 'desc')->order('RAND')
				->where(array('run_id' => $this->run_id, 'position >=' => $current_position))
				->limit(1)
				->fetch();

			if (!$temp_user) {
				alert('No data to compare to yet','alert-info');
				return false;
			}

			$this->run_session_id = $temp_user['id'];
		}

		return $this->run_session_id;
	}

	protected function howManyReachedItNumbers() {
		$reached = $this->dbh->select(array('SUM(`survey_unit_sessions`.ended IS NULL)' => 'begun', 'SUM(`survey_unit_sessions`.ended IS NOT NULL)' => 'finished'))
						->from('survey_unit_sessions')
						->leftJoin('survey_run_sessions', 'survey_run_sessions.id = survey_unit_sessions.run_session_id')
						->where('survey_unit_sessions.unit_id = :unit_id')
						->where('survey_run_sessions.run_id = :run_id')
						->bindParams(array('unit_id' => $this->id, 'run_id' => $this->run_id))
						->fetch();

		return $reached;
	}

	protected function howManyReachedIt() {
		$reached = $this->howManyReachedItNumbers();
		if ($reached['begun'] === "0") {
			$reached['begun'] = "";
		}
		if ($reached['finished'] === "0") {
			$reached['finished'] = "";
		}
		return "<span class='hastooltip badge' title='Number of unfinished sessions'>" . $reached['begun'] . "</span> <span class='hastooltip badge badge-success' title='Number of finished sessions'>" . $reached['finished'] . "</span>";
	}

	public function runDialog($dialog) {
		return '
		<div class="col-xs-12 row run_unit_inner ' . $this->type . '" data-type="' . $this->type . '">
		<div class="col-xs-12"><h4><input type="text" value="'.$this->description.'" placeholder="Description (click to edit)" class="run_unit_description" name="description"></h4></div>
				<div class="col-xs-3 run_unit_position">
					<h1><i class="muted fa fa-2x ' . $this->icon . '"></i></h1>
					' . $this->howManyReachedIt() . ' <button href="ajax_remove_run_unit_from_run" class="remove_unit_from_run btn btn-xs hastooltip" title="Remove unit from run" type="button"><i class="fa fa-times"></i></button><br>
					<input class="position" value="' . $this->position . '" type="number" name="position[' . $this->run_unit_id . ']" step="1" max="32000" min="-32000"><br>
				</div>
			<div class="col-xs-9 run_unit_dialog">
				<input type="hidden" value="' . $this->run_unit_id . '" name="run_unit_id">
				<input type="hidden" value="' . $this->id . '" name="unit_id">
				<input type="hidden" value="' . $this->special . '" name="special">' . $dialog . '
			</div>
		</div>';
	}
	
	public function hadMajorChanges() {
		return $this->had_major_changes;
	}
	protected function majorChange() {
		$this->had_major_changes = true;
	}

	public function displayForRun($prepend = '') {
		return $this->runDialog($prepend, '<i class="fa fa-puzzle-piece"></i>'); // FIXME: This class has no parent
	}

	protected $survey_results;

	/**
	 * Get user data needed to execute a query/request (mainly used in opencpu requests)
	 *
	 * @param string $q
	 * @param string $required
	 * @return array
	 */
	public function getUserDataInRun($q, $required = null) {
		$cache_key = Cache::makeKey($q, $required, $this->session_id, $this->run_session_id);
		if (($data = Cache::get($cache_key))) {
			return $data;
		}

		$needed = $this->dataNeeded($q, $required);
		$surveys = $needed['matches'];
		$results_tables = $needed['matches_results_tables'];
		$matches_variable_names = $needed['matches_variable_names'];
		$this->survey_results = array('datasets' => array());

		foreach($surveys AS $study_id => $survey_name) {
			if (isset($this->survey_results['datasets'][$survey_name])) {
				continue;
			}
	
			$results_table = $results_tables[$survey_name];
			if(empty($matches_variable_names[ $survey_name ])) {
				$variables = "NULL AS formr_dummy";
			} else {
				$variables = '';
				if($results_table === "survey_unit_sessions") {
					if(($key = array_search('position', $matches_variable_names[ $survey_name ])) !== false) {
 						unset($matches_variable_names[ $survey_name ][$key]);
						$variables .= '`survey_run_units`.`position`, ';
					}
					if(($key = array_search('type', $matches_variable_names[ $survey_name ])) !== false) {
 						unset($matches_variable_names[ $survey_name ][$key]);
						$variables .= '`survey_units`.`type`, ';
					}
				}
				$variables .= "`$results_table`.`" . implode("`,`$results_table`.`" ,$matches_variable_names[ $survey_name ]) . '`';
			}
			
			$q1 = "SELECT $variables";
			if($this->run_session_id === NULL AND !in_array($results_table, $this->non_session_tables)) { // todo: what to do with session_id tables in faketestrun
				$q3 = " WHERE `$results_table`.session_id = :session_id"; // just for testing surveys
			} else {
				$q3  = " WHERE  `survey_run_sessions`.id = :run_session_id";
				if($survey_name === "externals") {
					$q3 .= " AND `survey_units`.`type` = 'External'";
				}
			}

			if(!in_array($results_table, $this->non_session_tables )) {
				$q2 = "
					LEFT JOIN `survey_unit_sessions` ON `$results_table`.session_id = `survey_unit_sessions`.id
					LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				";
			} elseif($results_table == 'survey_unit_sessions'){
				$q2 = "LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
				LEFT JOIN `survey_units` ON `survey_unit_sessions`.unit_id = `survey_units`.id
				LEFT JOIN `survey_run_units` ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id";
			} elseif($results_table == 'survey_run_sessions') {
				$q2 = "";
			} elseif($results_table == 'survey_users') {
				$q2 = "LEFT JOIN `survey_run_sessions` ON `survey_users`.id = `survey_run_sessions`.user_id";
			}

			$q1 .= " FROM `$results_table` ";

			$q = $q1 . $q2 . $q3 . ";";
			$get_results = $this->dbh->prepare($q);
			if($this->run_session_id === NULL) {
				$get_results->bindValue(':session_id', $this->session_id);
			} else {
				$get_results->bindValue(':run_session_id', $this->run_session_id);
			}
			$get_results->execute();

			$this->survey_results['datasets'][$survey_name] = array();
			while($res = $get_results->fetch(PDO::FETCH_ASSOC)):
				foreach($res AS $var => $val):
					if(!isset($this->survey_results['datasets'][$survey_name][$var])) {
						$this->survey_results['datasets'][$survey_name][$var] = array();
					}
					$this->survey_results['datasets'][$survey_name][$var][] = $val;
				endforeach;
			endwhile;
		}

		if(!empty($needed['variables'])):
			if(in_array('formr_last_action_date', $needed['variables']) OR in_array('formr_last_action_time', $needed['variables'])):
				$last_action = $this->dbh->execute(
					"SELECT `survey_unit_sessions`.`created` FROM `survey_unit_sessions` 
					LEFT JOIN `survey_run_sessions` ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
					WHERE `survey_run_sessions`.id  = :run_session_id AND `unit_id` = :unit_id AND `survey_unit_sessions`.`ended` IS NULL LIMIT 1",
					array('run_session_id' => $this->run_session_id, 'unit_id' => $this->id),
					true
				);
				if($last_action !== false):
					$last_action_time = strtotime($last_action);
					if(in_array('formr_last_action_date', $needed['variables'])):
						$this->survey_results['.formr$last_action_date'] = "as.POSIXct('".date("Y-m-d", $last_action_time)."')";
					endif;
					if(in_array('formr_last_action_time', $needed['variables']) ):
						$this->survey_results['.formr$last_action_time'] = "as.POSIXct('".date("Y-m-d H:i:s T", $last_action_time)."')";
					endif;
				else:
					if(in_array('formr_last_action_date', $needed['variables'])):
						$this->survey_results['.formr$last_action_date'] = "NA";
					endif;
					if(in_array('formr_last_action_time', $needed['variables']) ):
						$this->survey_results['.formr$last_action_time'] = "NA";
					endif;					
				endif;
			endif;
			
			if(in_array('formr_login_link',$needed['variables']) ):
				$this->survey_results['.formr$login_link'] = WEBROOT."{$this->run_name}?code=".urlencode($this->session);
			endif;
			if(in_array('formr_login_code',$needed['variables']) ):
				$this->survey_results['.formr$login_code'] = $this->session;
			endif;
		endif;

		if ($needed['token_add'] !== null AND ! isset($this->survey_results['datasets'][$needed['token_add']])):
			$this->survey_results['datasets'][$needed['token_add']] = array();
		endif;

		Cache::set($cache_key, $this->survey_results);
		return $this->survey_results;
	}

	protected function knittingNeeded($source) {
		if (mb_strpos($source, '`r ') !== false OR mb_strpos($source, '```{r') !== false) {
			return true;
		}
		return false;
	}

	protected function dataNeeded($q, $token_add = null) {
		$cache_key = Cache::makeKey($q, $token_add);
		if (($data = Cache::get($cache_key))) {
			return $data;
		}

		$matches_variable_names = $variable_names_in_table = $matches = $matches_results_tables = $results_tables = $tables = array();

//		$results = $this->run->getAllLinkedSurveys(); // fixme -> if the last reported email thing is known to work, we can turn this on
		$results = $this->run->getAllSurveys();

		// also add some "global" formr tables
		$non_user_tables = array_keys($this->non_user_tables);
		$tables = $non_user_tables;
		$table_ids = $non_user_tables;
		$results_tables = array_combine($non_user_tables, $non_user_tables);
		if(isset($results_tables['externals'])) {
			$results_tables['externals'] = 'survey_unit_sessions';
		}

		if($token_add !== null):	 // send along this table if necessary, always as the first one, since we attach it
			$table_ids[] = $this->id;
			$tables[] = $this->name;
			$results_tables[ $this->name ] = $this->results_table;
		endif;

		// map table ID to the name that the user sees (because tables in the DB are prefixed with the user ID, so they're unique)
		foreach ($results as $res) {
			if($res['name'] !== $token_add):
				$table_ids[] = $res['id'];
				$tables[] = $res['name']; // FIXME: ID can overwrite the non_user_tables
				$results_tables[$res['name']] = $res['results_table'];
			endif;
		}

		foreach($tables AS $index => $table_name):
			$study_id = $table_ids[$index];

			// For preg_match, study name appears as word, matches nrow(survey), survey$item, survey[row,], but not survey_2
			if($table_name == $token_add OR preg_match("/\b$table_name\b/", $q)) {
				$matches[ $study_id ] = $table_name;
				$matches_results_tables[ $table_name ] = $results_tables[ $table_name ];	
			}

		endforeach;

		// loop through any studies that are mentioned in the command
		foreach($matches AS $study_id => $table_name):

			// generate a search set of variable names for each study
			if(array_key_exists($table_name, $this->non_user_tables)) {
				$variable_names_in_table[$table_name] = $this->non_user_tables[$table_name];
			} else {
				$items = $this->dbh->select('name')->from('survey_items')
					->where(array('study_id' => $study_id))
					->where("type NOT IN ('mc_heading', 'note', 'submit', 'block')")
					->fetchAll();

				$variable_names_in_table[ $table_name ] = array("created", "modified", "ended"); // should avoid modified, sucks for caching
				foreach ($items as $res) {
					$variable_names_in_table[ $table_name ][] = $res['name']; // search set for user defined tables
				}
			}

			$matches_variable_names[ $table_name ] = array();
			// generate match list for variable names
			foreach($variable_names_in_table[ $table_name ] AS $variable_name) {
				// try to match scales too, extraversion_1 + extraversion_2 - extraversion_3R - extraversion_4r = extraversion (script might mention the construct name, but not its item constituents)
				$variable_name_base = preg_replace("/_?[0-9]{1,3}R?$/i","", $variable_name);
				// don't match very short variable name bases
				if(strlen($variable_name_base) < 3) {
					$variable_name_base = $variable_name;
				}
				// item name appears as word, matches survey$item, survey[, "item"], but not item_2 for item-scale unfortunately
				if(preg_match("/\b$variable_name\b/",$q) OR preg_match("/\b$variable_name_base\b/",$q)) {
					$matches_variable_names[ $table_name ][] = $variable_name;
				}
			}

//			if(empty($matches_variable_names[ $table_name ])):
//				unset($matches_variable_names[ $table_name ]);
//				unset($variable_names_in_table[ $table_name ]);
//				unset($matches[ $study_id ]);
//			endif;
		endforeach;
	
		$variables = array();
		if(preg_match("/\btime_passed\b/",$q)) { $variables[] = 'formr_last_action_time'; }
		if(preg_match("/\bnext_day\b/",$q)) { $variables[] = 'formr_last_action_date'; }
		if(preg_match('/\b.formr\$login_code\b/',$q)) { $variables[] = 'formr_login_code'; }
		if(preg_match('/\b.formr\$login_link\b/',$q)) { $variables[] = 'formr_login_link'; }

		$data = compact("matches","matches_results_tables", "matches_variable_names", "token_add", "variables");
		Cache::set($cache_key, $data);
		return $data;
	}

	public function parseBodySpecial() {
		$session = opencpu_knitadmin($this->body, array(), true);
		return opencpu_debug($session);
	}

	public function getParsedText($source) {
		$ocpu_vars = $this->getUserDataInRun($source);
		return opencpu_knit_plaintext($source, $ocpu_vars, false);
	}

	public function getParsedTextAdmin($source) {
		if (!$this->grabRandomSession()) {
			return false;
		}
		$ocpu_vars = $this->getUserDataInRun($source);
		return opencpu_debug(opencpu_knit_plaintext($source, $ocpu_vars, true));
	}

	public function getParsedBodyAdmin($source, $email_embed = false) {
		if ($this->knittingNeeded($source)) {
			if (!$this->grabRandomSession()) {
				return false;
			}

			$opencpu_vars = $this->getUserDataInRun($source);
			/* @var $session OpenCPU_Session */
			$session = opencpu_knitadmin($source, $opencpu_vars, true);
			$body = opencpu_debug($session);

			if ($email_embed) {
				$report = array('body' => $body, 'images' => array());
			} else {
				$report = $body;
			}

			return $report;

		} else {
			$report = $this->body_parsed;
			if ($email_embed) {
				$report = array('body' => $this->body_parsed, 'images' => array());
			}

			return $report;
		}
	}

	public function getParsedBody($source, $email_embed = false) {
		/* @var $session OpenCPU_Session */
		if (!$this->knittingNeeded($source)) { // knit if need be
			if($email_embed) {
				return array('body' => $this->body_parsed, 'images' => array());
			} else {
				return $this->body_parsed;
			}
		}

		$opencpu_url = $this->dbh->findValue('survey_reports', array(
			'unit_id' => $this->id, 
			'session_id' => $this->session_id,
			'created >=' => $this->modified // if the definition of the unit changed, don't use old reports
		),  array('opencpu_url'));

		// If there is a cache of opencpu, check if it still exists
		if($opencpu_url) {
			if ($this->called_by_cron) {
				return false; // don't regenerate once we once had a report for this feedback, if it's only the cronjob
			}

			$opencpu_url = rtrim($opencpu_url, "/") . $email_embed ? '' : '/R/.val/';
			$format = ($email_embed ? "" : "json");
			$session = opencpu_get($opencpu_url, $format , null, true);
		}

		// If there no session or old session (from aquired url) has an error for some reason, then get a new one for current request
		if (empty($session) || $session->hasError()) {
			$ocpu_vars = $this->getUserDataInRun($source);
			$session = $email_embed ? opencpu_knitemail($source, $ocpu_vars, '', true) : opencpu_knitdisplay($source, $ocpu_vars, true);
		}

		// At this stage we are sure to have an OpenCPU_Session in $session. If there is an error in the session return FALSE
		if(empty($session)) {
			alert('OpenCPU is probably down or inaccessible. Please retry in a few minutes.', 'alert-danger');
			return false;
		} elseif ($session->hasError()) {
			$where = '';
			if(isset($this->run_name)) {
				$where = "Run: ". $this->run_name. " (".$this->position."-". $this->type.") ";
			}
			notify_user_error( opencpu_debug( $session ), 'There was a problem with OpenCPU.');
			return false;
		} else {
			
			print_hidden_opencpu_debug_message($session, "OpenCPU debugger for run R code in {$this->type} at {$this->position}.");
			
			$opencpu_url = $session->getLocation();

			if($email_embed) {
				$report = array(
					'body' => $session->getObject(),
					'images' => $session->getFiles('/figure-html'),
				);
			} else {
				$report = $session->getJSONObject();
			}

			if($this->session_id) {
				$set_report = $this->dbh->prepare(
				"INSERT INTO `survey_reports` (`session_id`, `unit_id`, `opencpu_url`, `created`, `last_viewed`) 
					VALUES  (:session_id, :unit_id, :opencpu_url,  NOW(), 	NOW() ) 
					ON DUPLICATE KEY UPDATE opencpu_url = VALUES(opencpu_url), created = VALUES(created)");

					$set_report->bindParam(":unit_id", $this->id);
					$set_report->bindParam(":opencpu_url", $opencpu_url);
					$set_report->bindParam(":session_id", $this->session_id);
					$set_report->execute();
			}

			return $report;
		}
	}

	public function getExportUnit() {
		$unit = array();
		foreach ($this->export_attribs as $property) {
			if (property_exists($this, $property)) {
				$unit[$property] = $this->{$property};
			}
		}
		return $unit;
	}

}
