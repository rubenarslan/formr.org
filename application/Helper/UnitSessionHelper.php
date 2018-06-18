<?php


class UnitSessionHelper {

	/**
	 *
	 * @var DB 
	 */
	protected $db;

	public function __construct(DB $db) {
		$this->db = $db;
	}

	public function getSurveyExpiration() {
		
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

	public function getPageExpiration() {
		
	}

	public function getPauseExpiration() {
		
	}
}
