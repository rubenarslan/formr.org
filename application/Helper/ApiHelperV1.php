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
