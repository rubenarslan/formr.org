<?php

class Timezone_Item extends SelectOne_Item {

    public $mysql_field = 'VARCHAR(255)';
    public $choice_list = '*';
    public $offsets;

    protected function chooseResultFieldBasedOnChoices() {
        
    }

    protected function setMoreOptions() {
        $this->classes_input[] = 'select2zone';

        parent::setMoreOptions();
        $this->setChoices(array());
    }

    public function setChoices($choices) {
        $zonenames = timezone_identifiers_list();
        asort($zonenames);
        $zones = array();
        $offsets = array();
        foreach ($zonenames AS $zonename):
            $zone = timezone_open($zonename);
            $offsets[] = timezone_offset_get($zone, date_create());
            $zones[] = str_replace("/", " - ", str_replace("_", " ", $zonename));
        endforeach;
        $this->choices = $zones;
        $this->offsets = $offsets;
    }
    
    public function getReply($reply) {
        if (isset($this->choices[$reply])) {
            $reply = $this->choices[$reply];
        }
        
        return $reply;
    }

    protected function render_input() {
        $tpl = "
        <script>
        document.addEventListener('DOMContentLoaded', function() {
    // Get the browser's timezone
    let timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    // Replace '/' with ' - ' to match the format in the select options
    timezone = timezone.replace(/\//g, ' - ');

    // Get the select element by its name attribute
    const selectElement = document.querySelector('select[name=\"{$this->name}\"]');
    if (!selectElement) {
        return;
    }
    const options = selectElement.options;

    // Find the option that matches the browser's timezone and select it
    for (let i = 0; i < options.length; i++) {
        if (options[i].text.includes(timezone)) {
            options[i].selected = true;

            // Trigger change event for select2 to update UI
            const event = new Event('change', { bubbles: true });
            selectElement.dispatchEvent(event);
            break;
        }
    }
});
</script>
			<select %{select_attributes}>
				%{empty_option}
				%{options}
			</select>
		";

        $options = '';
        foreach ($this->choices as $value => $option) {
            $selected = array('selected' => $this->isSelectedOptionValue($value, $this->value_validated));
            $options .= sprintf('<option value="%s" %s>%s</option>', $value, self::_parseAttributes($selected, array('type')), $option);
        }

        return Template::replace($tpl, array(
                    'empty_option' => !isset($this->input_attributes['multiple']) ? '<option value=""> &nbsp; </option>' : '',
                    'options' => $options,
                    'select_attributes' => self::_parseAttributes($this->input_attributes, array('type')),
        ));
    }

}
