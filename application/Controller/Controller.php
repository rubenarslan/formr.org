<?php

abstract class Controller {

    /**
     *
     * @var Site
     */
    protected $site;

    /**
     *
     * @var User 
     */
    protected $user;

    /**
     *
     * @var Run
     */
    public $run;

    /**
     *
     * @var DB
     */
    protected $fdb;

    /**
     *
     * @var Survey
     */
    public $study;

    /**
     *
     * @var Request
     */
    protected $request;
    
    /**
     * @var Response
     */
    protected $response;

    /**
     * @var View
     */
    protected $view;

    protected $css = array();
    protected $js = array();
    protected $vars = array();

    public function __construct(Site &$site) {
        /** @todo do these with dependency injection */
        global $user, $run, $study, $css, $js;
        $this->site = $site;
        $this->user = &$user;
        $this->study = $study;
        $this->run = $run;
        if (is_array($css)) {
            $this->css = $css;
        }
        if (is_array($js)) {
            $this->js = $js;
        }

        $this->fdb = DB::getInstance();
        $this->request = $site->request;
        $this->response = new Response();
    }

    protected function setView($template, $vars = array()) {
        $global = array(
            'site' => $this->site,
            'user' => $this->user,
            'fdb' => $this->fdb,
            'js' => $this->js,
            'css' => $this->css,
            'run' => $this->run,
            'study' => $this->study,
            'meta' => $this->generateMetaInfo(),
            'jsConfig' => $this->getJsConfig(),
        );

        $variables = array_merge($global, $this->vars, $vars);
        $this->view = new View($template, $variables);
    }

    protected function sendResponse($content = null) {
        if ($content === null && $this->view) {
            $content = $this->view->render();
        }
        if ($content !== null) {
            $this->response->setContent($content);
        }

        $this->response->send();
    }

    protected function getPrivateAction($name, $separator = '_', $protected = false) {
        $parts = array_filter(explode($separator, $name));
        $action = array_shift($parts);
        foreach ($parts as $part) {
            $action .= ucwords(strtolower($part));
        }

        if ($protected) {
            return $action;
        }

        $method = $action . 'Action';
        if (!method_exists($this, $method)) {
            throw new Exception("Action '$name' is not found.");
        }
        
        $disabledFeatures = Config::get('disabled_features', array());
        if (in_array('SURVEY.'.$method, $disabledFeatures) || in_array('RUN.'.$method, $disabledFeatures)) {
            formr_error_feature_unavailable();
        }
        
        
        return $method;
    }

    /**
     * @return Site
     */
    public function getSite() {
        return $this->site;
    }

    public function getDB() {
        return $this->fdb;
    }

    protected function registerCSS($files, $name) {
        $this->addFiles($files, $name, 'css');
    }

    protected function registerJS($files, $name) {
        $this->addFiles($files, $name, 'js');
    }

    private function addFiles($files, $name, $type) {
        if (!$files || !in_array($type, array('js', 'css'))) {
            return;
        }
        if (!is_array($files)) {
            $files = array($files);
        }
        foreach (array_filter($files) as $file) {
            if (!isset($this->{$type}[$name])) {
                $this->{$type}[$name] = array();
            }
            $this->{$type}[$name][] = $file;
        }
    }

    protected function registerAssets($which) {
        $assets = get_assets();
        if (!is_array($which)) {
            $which = array($which);
        }
        foreach ($which as $asset) {
            $this->registerCSS(array_val($assets[$asset], 'css'), $asset);
            $this->registerJS(array_val($assets[$asset], 'js'), $asset);
        }
    }

    protected function unregisterAssets($which) {
        if (is_array($which)) {
            foreach ($which as $a) {
                $this->unregisterAssets($a);
            }
        }
        if (isset($this->css[$which])) {
            unset($this->css[$which]);
        }
        if (isset($this->js[$which])) {
            unset($this->js[$which]);
        }
    }

    protected function replaceAssets($old, $new, $module = 'site') {
        $assets = get_default_assets($module);
        foreach ($assets as $i => $asset) {
            if ($asset === $old) {
                $assets[$i] = $new;
            }
        }
        $this->css = $this->js = array();
        $this->registerAssets($assets);
    }

    protected function generateMetaInfo() {
        $meta = array(
            'head_title' => $this->site->makeTitle(),
            'title' => 'formr - an online survey framework with live feedback',
            'description' => 'formr survey framework. chain simple surveys into long runs, use the power of R to generate pretty feedback and complex designs',
            'keywords' => 'formr, online survey, R software, opencpu, live feedback',
            'author' => 'formr.org',
            'url' => site_url(),
            'image' => asset_url('build/img/formr-og.png'),
        );

        return $meta;
    }
    
    protected function getJsConfig() {
        // Initialize JS config
        $config = array();
        // URLs
        $config['site_url'] = site_url();
        $config['admin_url'] = admin_url();
        // Cookie consent
        $cookieconsent = Site::getSettings('js:cookieconsent', '{}');
        if ($cookieconsent && ($decoded = json_decode($cookieconsent, true))) {
            $config['cookieconsent'] = $decoded;
        }
        
        return $config;
    }

    public function errorAction($code = null, $text = null) {
        formr_error($code, $text);
    }

}
