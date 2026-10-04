<?php

class Geopoint_Item extends Item {
    protected $accepts_array_reply = true;

    // Posted as an array, stored as one string (getReply): it goes back as is.
    public function storedValueForClient($answer) {
        return $answer;
    }


    public $type = 'geopoint';
    public $input_attributes = array('type' => 'text', 'readonly');
    public $mysql_field = 'TEXT DEFAULT NULL';
    protected $append = true;

    protected function setMoreOptions() {
        $this->input_attributes['name'] = $this->name . '[]';
        $this->classes_input[] = "form-control";
    }

    public function getReply($reply) {
        if (is_array($reply)):
            $reply = array_filter($reply);
            $reply = end($reply);
        endif;
        return $reply;
    }

    protected function render_prepended() {
        // Disabled with the item when a showif hides it, like the visible
        // input: neither is then submitted (or found missing).
        $ret = '
			<input type="hidden" name="%s" value=""%s />
			<div class="input-group">
		';
        return sprintf($ret, $this->name, empty($this->input_attributes['disabled']) ? '' : ' disabled');
    }

    protected function render_appended() {
        $ret = '
				<span class="input-group-btn hidden">
					<button type="button" class="btn btn-default geolocator item%s">
						<i class="fa fa-location-arrow fa-fw"></i>
					</button>
				</span>
			</div>
			';
        return sprintf($ret, $this->id);
    }

}
