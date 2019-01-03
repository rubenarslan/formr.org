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

	protected function __construct(DB $db) {
		$this->db = $db;
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

	public function getUnitSessionExpiration($unitType, UnitSession $unitSession, RunUnit $runUnit) {
		$method = sprintf('get%sExpiration', $unitType);
		return call_user_func(array($this, $method), $unitSession, $runUnit);
	}

	public function getSurveyExpiration(UnitSession $unitSession, Survey $survey) {
		$expire_invitation = (int) $survey->settings['expire_invitation_after'];
		$grace_period = (int) $survey->settings['expire_invitation_grace'];
		$expire_inactivity = (int) $survey->settings['expire_after'];
		if ($expire_inactivity === 0 && $expire_invitation === 0) {
			return false;
		} else {
			$now = time();

			$last_active = $survey->getTimeWhenLastViewedItem(); // when was the user last active on the study
			$expire_invitation_time = $expire_inactivity_time = 0; // default to 0 (means: other values supervene. users only get here if at least one value is nonzero)
			if($expire_inactivity !== 0 && $last_active != null) {
				$expire_inactivity_time = strtotime($last_active) + $expire_inactivity * 60;
			}
			$invitation_sent = $unitSession->created;
			if($expire_invitation !== 0 && $invitation_sent) {
				$expire_invitation_time = strtotime($invitation_sent) + $expire_invitation * 60;
				if($grace_period !== 0 && $last_active) {
					$expire_invitation_time = $expire_invitation_time + $grace_period * 60;
				}
			}

			$expire = max($expire_inactivity_time, $expire_invitation_time);
			return $expire;
		}
	}

	public function getExternalExpiration() {
		
	}

	public function getEmailExpiration() {
		return null;
	}

	public function getSkipBackwardExpiration() {
		
	}

	public function getSkipForwardExpiration() {
		
	}

	public function getShuffleExpiration() {
		
	}

	public function getPauseExpiration() {
		
	}

	public function getPageExpiration() {
		
	}

	public function getStopExpiration() {
		return $this->getPageExpiration();
	}

	public function getEndpageExpiration() {
		return $this->getPageExpiration();
	}
}
