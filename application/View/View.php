<?php

class View {
    
    protected $template = null;
    
    protected $variables = array();

    public function __construct($template = null, $variables = array()) {
        $this->setTemplate($template);
        $this->setVariables($variables);
    }

    public function setTemplate($template) {
        if ($template && !is_string($template)) {
            throw new Exception('Invalid template name');
        }
        
        $this->template = $template;
    }

    public function setVariables(array $variables) {
        $this->variables = $variables;
    }

    public function render() {
        if (!$this->template) {
            return;
        }
        return Template::get($this->template, $this->variables);
    }
}