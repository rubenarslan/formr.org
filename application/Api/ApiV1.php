<?php

/**
 * V1 dispatcher. Only top-level resources (user, surveys, runs) are directly
 * addressable at /v1/<resource>/... — everything else (sessions, results,
 * files, structure) requires a run and is routed through RunResource as
 * /v1/runs/{name}/<sub>.
 *
 * Resources are instantiated lazily to avoid running the ApiBase constructor
 * (which hydrates the authenticated user) seven times per request.
 */
class ApiV1 extends ApiBase
{

    public function user()
    {
        return (new UserResource($this->request, $this->db, $this->tokenData))->handle();
    }

    public function surveys()
    {
        return (new SurveyResource($this->request, $this->db, $this->tokenData))->handle();
    }

    public function runs()
    {
        return (new RunResource($this->request, $this->db, $this->tokenData))->handle();
    }
}
