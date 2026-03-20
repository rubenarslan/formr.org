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
        return $this->resources['user']->handle();
    }

    public function surveys()
    {
        return $this->resources['surveys']->handle();
    }

    public function runs()
    {
        return $this->resources['runs']->handle();
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
