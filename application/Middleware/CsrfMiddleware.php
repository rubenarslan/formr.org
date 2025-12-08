<?php

class CsrfMiddleware
{
    private string $cookieName = 'formr_token';

    public function handle(Request $request)
    {
        $this->ensureTokenSet();

        if ($request->isHTTPPostRequest()) {
            $this->validateToken($request);
        }
    }

    private function ensureTokenSet()
    {
        if (empty(Session::get(Session::REQUEST_TOKEN))) {
            Session::set(Session::REQUEST_TOKEN, bin2hex(random_bytes(32)));
        }

        if (empty($_COOKIE[$this->cookieName]) || $_COOKIE[$this->cookieName] !== Session::get(Session::REQUEST_TOKEN)) {
            setcookie($this->cookieName, Session::get(Session::REQUEST_TOKEN), [
                'secure' => true,
                'httponly' => false, // Allow JavaScript to read it
                'samesite' => 'Strict',
                'path' => '/',
            ]);
        }
    }

    private function validateToken(Request $request)
    {
        $tokenFromCookie = $_COOKIE[$this->cookieName] ?? '';
        $tokenFromForm = $request->getParam(Session::REQUEST_TOKEN, '');
        $tokenFromSession = Session::get(Session::REQUEST_TOKEN, '');

        if (!$tokenFromCookie || !$tokenFromForm || !$tokenFromSession || !hash_equals($tokenFromCookie, $tokenFromForm) || !hash_equals($tokenFromCookie, $tokenFromSession)) {
            $message = "Your form could not be submitted due to a security verification issue. 
            This can happen if the page has been open for a long time or was refreshed in another tab.";
            throw new \CsrfMiddlewareException($message, 403);
        }
    }
}

class CsrfMiddlewareException extends \Exception {
    public function __construct($message = '', $code = 403, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
