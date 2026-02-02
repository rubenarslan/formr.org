<?php

abstract class BaseResource extends ApiBase
{

    /** 
     * Stores the parsed URI segments 
     * @var array 
     */
    protected $path_segments;

    abstract public function handle();

    protected function getRequestMethod()
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    protected function getUriSegment($index)
    {
        if (!isset($this->path_segments)) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            $path = trim($path, '/');

            $segments = explode('/', $path);

            $v1Index = array_search('v1', $segments);

            if ($v1Index !== false) {
                $this->path_segments = array_slice($segments, $v1Index + 1);
            } else {
                $this->path_segments = $segments;
            }
        }

        return $this->path_segments[$index] ?? null;
    }

    protected function getJsonBody()
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    protected function response($code, $msg, $data = [])
    {
        $this->setData($code, $msg, $data);
        return $this;
    }

    protected function error($code, $msg)
    {
        $this->setData($code, $this->getStatusText($code), [
            'code' => $code,
            'message' => $msg
        ]);
        return $this;
    }

    private function getStatusText($code)
    {
        $statusTexts = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            415 => 'Unsupported Media Type',
            500 => 'Internal Server Error',
        ];
        return $statusTexts[$code] ?? 'Error';
    }
}
