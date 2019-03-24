<?php


class RunUnitHelper {

	/**
	 *
	 * @var DB 
	 */
	protected $db;

	/**
	 *
	 * @var RunUnitHelper 
	 */
	protected static $instance = null;

	protected $expiration_extension;

	protected function __construct(DB $db) {
		$this->db = $db;
		$this->expiration_extension = Config::get('unit_session.queue_expiration_extension', '+10 minutes');
	}

	/**
	 * @return RunUnitHelper
	 */
	public static function getInstance() {
		if (self::$instance === null) {
			self::$instance = new self(DB::getInstance());
		}

		return self::$instance;
	}

	/**
	 * Wrapper function
	 *
	 * @param UnitSession $unitSession
	 * @param RunUnit $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getUnitSessionExpiration(UnitSession $unitSession, RunUnit $runUnit, $execResults) {
		$method = sprintf('get%sExpiration', $runUnit->type);
		return call_user_func(array($this, $method), $unitSession, $runUnit, $execResults);
	}

	/**
	 * Get expiration timestamp for External Run Unit
	 * If the results from executing the unit is TRUE then we queue the unit to be executed again after x minutes.
	 * Other wise 0 should be returned because unit ended after execution
	 *
	 * @param UnitSession $unitSession
	 * @param External $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getExternalExpiration(UnitSession $unitSession, External $runUnit, $execResults) {
		if (!empty($runUnit->execData['expire_timestamp'])) {
			return $runUnit->execData['expire_timestamp'];
		} elseif ($execResults === true) {
			// set expiration to x minutes for unit session to be executed again
			return strtotime($this->expiration_extension);
		}
		return 0;
	}

	/**
	 * Get expiration timestamp for Email Run Unit
	 * This should always return 0 because email-sending is managed in separate queue
	 *
	 * @param UnitSession $unitSession
	 * @param Email $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getEmailExpiration(UnitSession $unitSession, Email $runUnit, $execResults) {
		return 0;
	}

	/**
	 * Get expiration timestamp for Shuffle Run Unit
	 * This should always return 0 because a shuffle unit ends immediately after execution
	 *
	 * @param UnitSession $unitSession
	 * @param Shuffle $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getShuffleExpiration(UnitSession $unitSession, Shuffle $runUnit, $execResults) {
		return 0;
	}

	/**
	 * Get expiration timestamp for Page Run Unit
	 * Does not need to be executed in intervals so no need to queue.
	 *
	 * @param UnitSession $unitSession
	 * @param Page $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getPageExpiration(UnitSession $unitSession, Page $runUnit, $execResults) {
		return 0;
	}

	/**
	 * 
	 * @see self::getPageExpiration()
	 */
	public function getStopExpiration(UnitSession $unitSession, $runUnit, $execResults) {
		return $this->getPageExpiration($unitSession, $runUnit, $execResults);
	}

	/**
	 * 
	 * @see self::getPageExpiration()
	 */
	public function getEndpageExpiration(UnitSession $unitSession, $runUnit, $execResults) {
		return $this->getPageExpiration($unitSession, $runUnit, $execResults);
	}

	/**
	 * Get expiration timestamp for Survey Run Unit
	 * 
	 * @param UnitSession $unitSession
	 * @param Survey $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getSurveyExpiration(UnitSession $unitSession, Survey $runUnit, $execResults) {
		if ($execResults === false) {
			// Survey expired or ended so no need to queue
			return 0;
		}

		if (isset($runUnit->execData['expire_timestamp'])) {
			return $runUnit->execData['expire_timestamp'];
		} else {
			return 0;
		}
	}

	/**
	 * Get expiration timestamp for Pause Run Unit
	 *
	 * @param UnitSession $unitSession
	 * @param Pause $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getPauseExpiration(UnitSession $unitSession, Pause $runUnit, $execResults) {
		$execData = $runUnit->execData;
		if (!empty($execData['pause_over'])) {
			// pause is over no need to queue
			return 0;
		}

		if ($execData['check_failed'] === true || $execData['expire_relatively'] === false) {
			// check again in x minutes something went wrong with ocpu evaluation
			return strtotime($this->expiration_extension);
		}
		
		if (isset($execData['expire_timestamp'])) {
			return $execData['expire_timestamp'];
		}

		return 0;
	}

	/**
	 * Get expiration timestamp for Branch (SkipForward | SkipBackward) Run Unit
	 * 
	 * @param UnitSession $unitSession
	 * @param Branch $runUnit
	 * @param mixed $execResults
	 * @return int
	 */
	public function getBranchExpiration(UnitSession $unitSession, Branch $runUnit, $execResults) {
		if (!empty($runUnit->execData['expire_timestamp'])) {
			return (int)$runUnit->execData['expire_timestamp'];
		} elseif ($execResults === true) {
			// set expiration to x minutes for unit session to be executed again
			return strtotime($this->expiration_extension);
		}
		return 0;
	}

	/**
	 * @see getBranchExpiration
	 */
	public function getSkipBackwardExpiration(UnitSession $unitSession, Branch $runUnit, $execResults) {
		return $this->getBranchExpiration($unitSession, $runUnit, $execResults);
	}

	/**
	 * @see getBranchExpiration
	 */
	public function getSkipForwardExpiration(UnitSession $unitSession, Branch $runUnit, $execResults) {
		return $this->getBranchExpiration($unitSession, $runUnit, $execResults);
	}
	
}
