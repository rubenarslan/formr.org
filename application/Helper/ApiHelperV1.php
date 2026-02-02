<?php

class ApiHelperV1 extends ApiBase
{

    private $resources = [];

    public function __construct(Request $request, DB $db, $token_data)
    {
        parent::__construct($request, $db, $token_data);

        $this->resources = [
            'user' => new UserResource($request, $db, $token_data),
            'surveys' => new SurveyResource($request, $db, $token_data),
            'runs' => new RunResource($request, $db, $token_data),
            'sessions' => new SessionResource($request, $db, $token_data),
            'results' => new ResultsResource($request, $db, $token_data),
            'files' => new FileResource($request, $db, $token_data),
            'structure' => new StructureResource($request, $db, $token_data),
        ];
    }

    public function user()
    {
        return $this->getResource('user')->handle();
    }

    public function surveys($surveyName = null)
    {
        $resource = $this->getResource('surveys');
        return $resource->handle();
    }

    public function runs($runName = null, $subResource = null, $extra = null)
    {
        $resource = $this->getResource('runs');
        return $resource->handle();
    }

    private function handleSessions($runName)
    {
        $resource = $this->getResource('sessions');
        return $resource->handle($runName);
    }

    private function handleResults($runName)
    {
        $resource = $this->getResource('results');
        return $resource->handle($runName);
    }

    private function handleFiles($runName)
    {
        $resource = $this->getResource('files');
        return $resource->handle($runName);
    }

    private function handleStructure($runName)
    {
        $resource = $this->getResource('structure');
        return $resource->handle($runName);
    }

    private function getResource($name)
    {
        if (!isset($this->resources[$name])) {
            $this->setData(Response::STATUS_NOT_FOUND, 'Not Found', ['error' => "Resource '$name' not found in V1 API."]);
            return $this;
        }
        return $this->resources[$name];
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return call_user_func_array([$this, $name], $arguments);
        }

        $this->setData(Response::STATUS_METHOD_NOT_ALLOWED, 'Method Not Allowed', [
            'code' => Response::STATUS_METHOD_NOT_ALLOWED,
            'message' => "Action '$name' is not available in this API version."
        ]);
        return $this;
    }
}
