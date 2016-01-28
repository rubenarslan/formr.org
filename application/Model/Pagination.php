<?php

class Pagination {

	public $maximum = 0;

	private $maximum_page;
	private $start = 0;
	private $per_page = 100;
	private $page = 0;
	private $enable_show_all = false;

	public function __construct($maximum, $per_page = 100, $enable_show_all = false) {
		if (is_numeric($per_page)) {
			$this->per_page = (int) $per_page;
		}
		$this->enable_show_all = $enable_show_all;

		$this->setMaximum($maximum);
	}

	public function getLimits() {
		if (isset($_GET['page']) and is_numeric($_GET['page'])):
			$this->page = (int) $_GET['page'] - 1;
			if ($this->page > $this->maximum_page):
				$this->page = $this->maximum_page;
			elseif ($this->page === -1 AND $this->enable_show_all):
				return "0," . $this->maximum;
			elseif ($this->page < 0 AND ! $this->enable_show_all):
				$this->page = 0;
			endif;
			$this->start = $this->page * $this->per_page;
		endif;

		return $this->start . "," . $this->per_page;
	}

	public function setPage($page) {
		if (is_numeric($page)) {
			$this->page = (int) $page;
		}

		if ($this->page < 0) {
			throw new Exception("Pagination page number must be positive.");
		}
	}


	private function setMaximum($maximum) {
		$maximum = (int)$maximum;
		if (!$maximum) {
			$this->maximum = 0;
			$this->maximum_page = 0;
		}

		$this->maximum = (int)$maximum;
		$this->maximum_page = (int) floor(($this->maximum - $this->per_page) / $this->per_page); // get the last page that can display this many x, divide it by x, 
		if ($this->maximum_page < 0):
			$this->maximum_page = 0; // but if it's negative make it 0
		endif;
	}

	public function render($url) {
		if ($this->maximum_page === 0):
			return '';
		else:
			if (mb_substr($url, -1) != "&" AND mb_substr($url, -1) != "?") {
				$url.="?";
			}

			$pages = range(0, $this->maximum_page); // all pages
			if ($this->maximum_page > 15) {
				$pagination_size = ' pagination-sm';
			} elseif ($this->maximum_page < 5) {
				$pagination_size = ' pagination-lg';
			} else {
				$pagination_size = '';
			}

			echo '<ul class="pagination' . $pagination_size . '">';
			foreach ($pages as $page):
				if ($page == $this->page) {
					$active = ' class="active"';
				} else {
					$active = '';
				}
				$page++;
				$link_text = $page;
				echo "<li$active><a href='" . WEBROOT . "{$url}page=$page'>$link_text</a></li>";
			endforeach;

			if ($this->enable_show_all):
				if ($this->page === -1) {
					$active = ' class="active"';
				} else {
					$active = '';
				}
				echo "<li$active><a href='" . WEBROOT . "{$url}page=0'>Show all</a></li>";
			endif;

			echo '</ul>';
		endif;
	}

}
