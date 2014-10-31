<?php
/**
 * Vanilla Router for formr.org
 * Heavily relies on defined .htaccess
 */

class Router {

    protected static $instance = null;

    protected $module;

    protected $submodule;

    protected $controller;

    protected $webroot;

    protected $params;

    protected $file;

    protected $directPathParts = array();

    /**
     * @var Site
     */
    protected $site;

    public function __construct(&$site) {
        $this->site = $site;
    }

    /**
     * @return Router
     */
    public function route() {
        $this->module = $this->site->request->str('module', 'public');
        $this->submodule = $this->site->request->str('submodule');
        $this->controller = str_replace('.php', '', $this->site->request->str('controller'));
        $this->params = $this->site->request->str('params');
        $this->webroot = INCLUDE_ROOT . 'webroot';

        if ($this->module == 'index')  {
            $this->module = 'public';
        }

        $this->site->setPath($this->path($this->module, $this->submodule, $this->controller));
        $this->directPathParts = explode('/', $this->path($this->webroot, $this->module, $this->submodule, $this->controller, $this->params));
        $direct_file = $this->path($this->webroot, $this->module, $this->submodule, $this->controller, $this->params .'.php');
        if (file_exists($direct_file)) {
            $this->file = $direct_file;
            $this->hackRequestParams();
            return $this;
        }

        $module_dir = $this->path($this->webroot, $this->module);
        if (is_dir($module_dir)) {
            if ($this->submodule && is_dir($this->path($module_dir, $this->submodule))) {
                $sub_module_dir = $this->path($module_dir, $this->submodule);

                if ($this->controller && is_dir($this->path($sub_module_dir, $this->controller))) {
                    $this->file = $this->path($sub_module_dir, $this->controller, 'index.php');
                } elseif ($this->controller) {
                    $this->file = $this->path($sub_module_dir, $this->controller.'.php');
                }
            } elseif (!$this->submodule && $this->controller && is_dir($this->path($module_dir, $this->controller))) {
                $this->file = $this->path($module_dir, $this->controller, 'index.php');
            } elseif (!$this->submodule && $this->controller && !is_dir($this->path($module_dir, $this->controller))) {
                $this->file = $this->path($module_dir, $this->controller.'.php');
            } else {
                $this->file = $module_dir . '/index.php';
            }
        } else {
            // assume this is a run
            $this->file = $this->path($this->webroot, 'run.php');
            $this->site->request->run_name = $this->module;
        }

        $this->hackRequestParams();
        return $this;
    }

    public function getFile() {
        return $this->file;
    }

    private function hackRequestParams() {
        $fileFromParams = $this->path($this->module, $this->submodule, $this->params . '.php');
        // Hack for admin survey area
        if ($this->site->inAdminSurveyArea() && !file_exists($this->file)) {
            if ($this->params && file_exists($fileFromParams)) {
                $this->file = $fileFromParams;
				$second_to_last = count($this->directPathParts)-2;
                $this->site->request->study_name = !empty($this->directPathParts[$second_to_last]) ? $this->directPathParts[$second_to_last] : '';
            } else {
                $survey_name = pathinfo($this->file, PATHINFO_FILENAME);
                $this->file = dirname($this->file) . '/index.php';
                $this->site->request->study_name = $survey_name;
            }
        }
        // Hack for run admin area
        if ($this->site->inAdminRunArea() && !file_exists($this->file)) {
            if ($this->params && file_exists($fileFromParams)) {
                $this->file = $fileFromParams;
				$second_to_last = count($this->directPathParts)-2;
                $this->site->request->run_name = !empty($this->directPathParts[$second_to_last]) ? $this->directPathParts[$second_to_last] : '';
            } else {
                $run_name = pathinfo($this->file, PATHINFO_FILENAME);
                $this->file = dirname($this->file) . '/index.php';
                $this->site->request->run_name = $run_name;
            }
        }
        // WTF hack because global array is accessed in almost all of code
        $_GET['run_name'] = $this->site->request->run_name;
        $_GET['study_name'] = $this->site->request->study_name;
    }
    private function path() {
        $paths = func_get_args();
        $path = implode('/', $paths);
        $path = preg_replace('/\/+/', '/', $path);
        $path = preg_replace('/\/\./', '.', $path);
        return $path;
    }

    /**
     * @return Router
     */
    public static function getInstance() {
        if (self::$instance === null) {
            global $site;
            self::$instance = new self($site);
        }
        return self::$instance;
    }

    public static function getWebRoot() {
        return INCLUDE_ROOT . 'webroot';
    }

    public static function isWebRootDir($name) {
        return is_dir(self::getWebRoot() . '/' . $name);
    }
}

