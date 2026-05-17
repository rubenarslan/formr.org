<h3>formr API</h3><hr />

<p>
    The formr API is the way to get data out of, push surveys into, and orchestrate runs on the platform from your own code. There are two surfaces:
</p>

<ul>
    <li>
        <b>v1 (recommended)</b> &mdash; a resource-oriented REST API rooted at <code>/api/v1/</code>.
        Covers runs, surveys, sessions, results, files, and your account profile.
        OAuth2 <code>client_credentials</code> grant for authentication, scope-based authorisation,
        optional per-credential restriction to specific runs.
    </li>
    <li>
        <b>Legacy /get/results</b> &mdash; the older results-fetching endpoint. Still works for back-compat,
        documented at the bottom of this page. New integrations should use v1.
    </li>
</ul>

<p>
    The easiest way to consume the v1 API is the
    <a href="https://rubenarslan.github.io/formr/" target="_blank" rel="noopener">formr R package</a>
    (the <code>formr_api_*</code> family). The reference below is the underlying HTTP contract for callers
    in any language.
</p>

<p>
    API base URL: <br />
    <code class="php">https://api.formr.org</code>
</p>

<h4>1. Get API credentials</h4>

<p>
    API access requires <b>admin level 2</b> on your account. If you only have admin level 1 (the default for new accounts),
    open <code>admin/account#api</code> and follow the support-email prompt to request access.
</p>

<p>
    Once your level is set, open <b>Account &rarr; API Credentials</b> (the API tab on your account page).
    You can hold multiple credentials side by side &mdash; each one with its own scope set and run allowlist.
    A common pattern is one narrow read-only credential for a dashboard, plus a separate broader credential for a cron job. Deleting
    one credential does not affect the others.
</p>

<p>To create a credential you will be asked to:</p>

<ol>
    <li>
        <b>Give the credential a label.</b> Used only to tell credentials apart in the UI (e.g.
        <code>dashboard</code>, <code>cron-2026</code>). Must be unique within your account; the label
        <code>internal</code> is reserved.
    </li>
    <li>
        <b>Pick the scopes</b> this credential should grant. Each scope is one verb on one resource family:
        <table style="margin: 0.5em 0;">
            <tr><td><code>user:read</code> / <code>user:write</code></td><td>Read / update your account profile</td></tr>
            <tr><td><code>survey:read</code> / <code>survey:write</code></td><td>Read survey definitions / upload + edit them</td></tr>
            <tr><td><code>run:read</code> / <code>run:write</code></td><td>Read run metadata / create + update + delete runs</td></tr>
            <tr><td><code>session:read</code> / <code>session:write</code></td><td>Read participant sessions / create + advance them</td></tr>
            <tr><td><code>data:read</code></td><td>Read participant response data</td></tr>
            <tr><td><code>file:read</code> / <code>file:write</code></td><td>Download / upload files attached to runs</td></tr>
        </table>
        A credential with only <code>run:read</code> will succeed on <code>GET /v1/runs/{name}</code> and 403 on <code>PATCH /v1/runs/{name}</code>.
    </li>
    <li>
        <b>Optionally restrict the credential to specific runs.</b> Leave the run picker empty to allow this credential to act on
        all of your runs. Tick one or more runs to narrow it. A run-restricted credential implicitly restricts which surveys it can
        touch &mdash; only surveys that appear as units in one of the allowlisted runs are reachable. Brand-new survey creation is
        blocked for run-restricted credentials (the new survey would be unreachable until you linked it into a run).
    </li>
    <li>
        Click <b>Create credential</b>. The <code>client_id</code> and <code>client_secret</code> are shown <b>once</b>. Copy both immediately
        &mdash; the server stores only a SHA-256 hash, so a forgotten secret has to be rotated, not recovered.
    </li>
</ol>

<p>
    Once you have at least one credential, the API tab shows a table of all of them with their labels, scopes, and run counts.
    Each row has a <b>Rotate</b> button (mints a new <code>client_secret</code> while keeping the same <code>client_id</code>;
    optionally update the scopes / runs at the same time) and a <b>Delete</b> button (revokes the credential immediately;
    any service still using it will start getting 401 on the next call).
</p>

<h4>2. Mint an access token</h4>

<p>
    Exchange the client credentials for a bearer access token. Tokens are short-lived (1 hour by default) and stored as a
    SHA-256 hash on the server, so a database compromise alone does not expose replayable tokens.
</p>

<pre>
<code class="http">
POST /oauth/access_token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&amp;client_id={client-id}&amp;client_secret={client-secret}
</code>
</pre>

<p>Successful response:</p>

<pre>
<code class="json">
{
    "access_token": "XXXXXX3635f0dc13d563504b4d",
    "expires_in": 3600,
    "token_type": "Bearer",
    "scope": "run:read run:write survey:read"
}
</code>
</pre>

<p>
    The <code>scope</code> field echoes the scopes you picked when generating the credential.
    If the field is empty (<code>""</code>), the credential was created with no scopes selected and every API call will 403 &mdash;
    rotate it at <code>admin/account#api</code> and pick at least one scope.
</p>

<p>Error response (invalid client):</p>

<pre>
<code class="json">
{
    "error": "invalid_client",
    "error_description": "The client credentials are invalid"
}
</code>
</pre>

<h4>3. Call resource endpoints</h4>

<p>Send the token in the <code>Authorization</code> header on every request:</p>

<pre>
<code class="http">
Authorization: Bearer {access-token}
</code>
</pre>

<p>Resources currently exposed under <code>/api/v1/</code>:</p>

<table style="margin: 0.5em 0;">
    <tr><th align="left">Endpoint</th><th align="left">Method &rarr; scope required</th></tr>
    <tr><td><code>/v1/user/me</code></td><td>GET &rarr; <code>user:read</code></td></tr>
    <tr><td><code>/v1/runs</code></td><td>GET (list) &rarr; <code>run:read</code></td></tr>
    <tr><td><code>/v1/runs/{name}</code></td><td>GET &rarr; <code>run:read</code>; POST / PATCH / DELETE &rarr; <code>run:write</code></td></tr>
    <tr><td><code>/v1/runs/{name}/sessions</code></td><td>GET &rarr; <code>session:read</code>; POST / DELETE &rarr; <code>session:write</code></td></tr>
    <tr><td><code>/v1/runs/{name}/results</code></td><td>GET &rarr; <code>data:read</code></td></tr>
    <tr><td><code>/v1/runs/{name}/files</code></td><td>GET &rarr; <code>file:read</code>; POST / DELETE &rarr; <code>file:write</code></td></tr>
    <tr><td><code>/v1/runs/{name}/structure</code></td><td>GET &rarr; <code>run:read</code>; PUT &rarr; <code>run:write</code></td></tr>
    <tr><td><code>/v1/surveys</code></td><td>GET (list) &rarr; <code>survey:read</code>; POST (upload) &rarr; <code>survey:write</code></td></tr>
    <tr><td><code>/v1/surveys/{name}</code></td><td>GET &rarr; <code>survey:read</code>; PATCH / DELETE &rarr; <code>survey:write</code></td></tr>
</table>

<p>
    A scope check happens <i>before</i> the resource is looked up &mdash; so a token without <code>run:write</code> gets a 403 on
    <code>PATCH /v1/runs/foo</code> regardless of whether <code>foo</code> exists or belongs to your account.
</p>

<h5>Response envelope</h5>

<p>
    Success bodies are the resource (or array of resources) directly. List endpoints (<code>GET /v1/runs</code>,
    <code>GET /v1/surveys</code>) return a bare JSON array. Detail endpoints return a single object.
    Error bodies are <code>{"code": &lt;int&gt;, "message": "&lt;text&gt;"}</code> with the HTTP status carrying the same code.
</p>

<h5>Error shapes you'll see when a scope or allowlist is wrong</h5>

<pre>
<code class="json">
// Token is missing the verb scope this endpoint needs.
HTTP/1.1 403 Forbidden
{"code": 403, "message": "Insufficient permissions: 'run:write' scope required."}

// Credential's run allowlist doesn't include this run.
HTTP/1.1 403 Forbidden
{"code": 403, "message": "This API client is not authorized for run 'foo'."}

// Credential's run allowlist doesn't include any run that uses this survey.
HTTP/1.1 403 Forbidden
{"code": 403, "message": "This API client is not authorized for survey 'bar'."}

// Run-restricted credentials cannot create brand-new surveys.
HTTP/1.1 403 Forbidden
{"code": 403, "message": "Cannot create surveys with a run-restricted API client; add the survey to a run via the admin UI first, then update it via the API."}
</code>
</pre>

<p>
    All four fix paths route through the same place: open <code>admin/account#api</code>, find the credential row in
    the table, click <b>Rotate</b>, adjust the scope tickboxes or the run picker, and confirm. The
    <code>client_id</code> stays the same; only the <code>client_secret</code> changes. (Or create a fresh credential with the
    right shape and delete the old one once your callers have migrated.)
</p>

<h4>4. Example: list runs and read one</h4>

<pre>
<code class="bash">
# 1) Get a token
ACCESS_TOKEN=$(curl -s -X POST https://api.formr.org/oauth/access_token \
  -d grant_type=client_credentials \
  -d client_id=$CLIENT_ID \
  -d client_secret=$CLIENT_SECRET | jq -r .access_token)

# 2) List runs visible to this credential
curl -s https://api.formr.org/v1/runs \
  -H "Authorization: Bearer $ACCESS_TOKEN" | jq

# 3) Read one run (subject to the credential's run allowlist if any)
curl -s https://api.formr.org/v1/runs/my-diary \
  -H "Authorization: Bearer $ACCESS_TOKEN" | jq
</code>
</pre>

<h4>5. Using the formr API in R</h4>

<p>
    The R package handles auth, token refresh, and scoping-aware error hints. Installation and a full walk-through
    are in the
    <a href="https://rubenarslan.github.io/formr/articles/getting-started.html" target="_blank" rel="noopener">Getting Started</a>
    vignette.
</p>

<pre>
<code class="r">
library(formr)

# One-time: store the credentials you generated at admin/account#api
formr_store_keys(
    host = "https://api.formr.org",
    client_id = "YOUR_CLIENT_ID",
    client_secret = "YOUR_CLIENT_SECRET"
)

# Authenticate (auto-picks up stored keys; also auto-picks up the
# embedded token when called inside an OpenCPU R block on formr.org)
formr_api_authenticate(host = "https://api.formr.org")

# Inspect which scopes the credential carries
formr_api_session()$scope
#> [1] "run:read run:write survey:read"

# Call resource helpers
runs    <- formr_api_runs()
details <- formr_api_get_run("my-diary")
</code>
</pre>

<h4>Legacy: /get/results (V0)</h4>

<p>
    The older results endpoint still works for back-compat. Same OAuth token flow as v1; the difference is the URL shape
    and that <code>/get/results</code> returns all surveys of a run in one call rather than per-survey under
    <code>/v1/runs/{name}/results</code>. New integrations should use the v1 endpoints above.
</p>

<pre>
<code class="http">
GET /get/results?
     run[name]={name of the run}
    &amp;run[sessions]={comma-separated list of session codes; empty = all}
    &amp;surveys[survey_name_1]={comma-separated items; empty = all}
    &amp;surveys[survey_name_2]={comma-separated items; empty = all}

Authorization: Bearer {access-token}
</code>
</pre>

<p>
    Response: an object keyed by survey name, each value an array of session-keyed result rows.
</p>
