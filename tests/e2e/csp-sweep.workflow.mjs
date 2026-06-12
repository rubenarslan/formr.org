// CSP sweep orchestration. Run via the Workflow tool:
//   Workflow({ scriptPath: 'tests/e2e/csp-sweep.workflow.mjs' })
//
// Enumerate the admin route surface (parallel, per controller) → merge into the
// crawler's route manifest → run the Playwright CSP crawler (single browser
// step) → triage any real violations → synthesize an enforce-readiness report.
//
// Respects the single-shared-browser rule: only the Crawl phase touches a
// browser, and it is exactly one deterministic step. All fan-out phases are
// read-only and browser-free.

export const meta = {
  name: 'csp-sweep',
  description: 'Sweep the admin UI for CSP violations and assess enforce-readiness',
  phases: [
    { title: 'Enumerate', detail: 'one agent per Admin controller → GET-render route list' },
    { title: 'Merge', detail: 'union routes into fixtures/admin-routes.js' },
    { title: 'Crawl', detail: 'run the Playwright CSP crawler (single browser step)' },
    { title: 'Triage', detail: 'map each real violation to a remediation (conditional)' },
    { title: 'Synthesize', detail: 'enforce-readiness report + final policy' },
  ],
}

const ROOT = '/home/admin/formr-docker/formr_source'

const ROUTE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['controller', 'routes'],
  properties: {
    controller: { type: 'string' },
    routes: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['path', 'needs', 'rendersHtml', 'destructive'],
        properties: {
          path: { type: 'string', description: 'URL path with {run}/{survey}/{id} placeholders' },
          needs: { type: ['string', 'null'], enum: ['run', 'survey', 'id', null] },
          rendersHtml: { type: 'boolean' },
          destructive: { type: 'boolean', description: 'mutates/deletes/sends, or executes on GET' },
        },
      },
    },
  },
}

phase('Enumerate')
const CONTROLLERS = [
  'AdminController', 'AdminRunController', 'AdminSurveyController',
  'AdminMailController', 'AdminAccountController', 'AdminAdvancedController',
]
const enumResults = await parallel(CONTROLLERS.map((c) => () =>
  agent(
    `Read ${ROOT}/application/Controller/${c}.php. List every *Action method — BOTH public and private (private ones dispatch via the router's indexAction fallback). For each, infer the admin URL path (route prefix from setup.php: admin, admin/run, admin/survey, admin/mail, admin/account, admin/advanced; dash->camelCase, underscore literal) and classify:\n` +
    `- rendersHtml: true if it renders a full HTML page via setView()+sendResponse() on GET.\n` +
    `- destructive: true if it deletes/mutates/sends/exports OR executes its effect on a plain GET (e.g. delete_file, delete_run, empty_run, snip, reset, expire, mass mail). When unsure, mark destructive:true (safer to skip in the crawl).\n` +
    `- needs: 'run' | 'survey' | 'id' | null — whether the path needs a run/survey name or numeric id segment; use {run}/{survey}/{id} placeholders in path.\n` +
    `Only include GET-reachable admin pages. Exclude ajax_* / JSON / pure-POST endpoints. Return the structured object.`,
    { label: `enum:${c}`, phase: 'Enumerate', schema: ROUTE_SCHEMA },
  )))
const allRoutes = enumResults.filter(Boolean).flatMap((r) => r.routes.map((rt) => ({ ...rt, controller: r.controller })))
log(`enumerated ${allRoutes.length} routes across ${enumResults.filter(Boolean).length} controllers`)

phase('Merge')
const crawlable = allRoutes.filter((r) => r.rendersHtml && !r.destructive)
await agent(
  `Overwrite ${ROOT}/tests/e2e/fixtures/admin-routes.js so it exports (module.exports = [...]) the union of these GET-render, non-destructive admin routes as { path, needs } objects, deduped by path. Keep the existing file's header comment (read it first), keep paths starting with '/', and keep needs as 'run'|'survey'|'id'|null. Here is the route set:\n${JSON.stringify(crawlable, null, 1)}\nWrite the file and return the count of routes written.`,
  { label: 'merge:manifest', phase: 'Merge' },
)

const CRAWL_SCHEMA = {
  type: 'object',
  additionalProperties: true,
  required: ['pagesCrawled', 'realViolations', 'devBuildEvalViolations'],
  properties: {
    pagesCrawled: { type: 'number' },
    realViolations: { type: 'number' },
    devBuildEvalViolations: { type: 'number' },
    clusters: { type: 'array', items: { type: 'object', additionalProperties: true } },
  },
}

phase('Crawl')
const crawl = await agent(
  `Run the CSP crawler against the dev instance. Use a Bash timeout of 300000 ms (it takes ~2-3 minutes):\n` +
  `cd ${ROOT} && npx playwright test --config tests/e2e/playwright.config.js --project=local-chromium csp-crawl 2>&1 | tail -6\n` +
  `Then read ${ROOT}/tests/e2e/artifacts/csp-violations.json and return its top-level summary: pagesCrawled, realViolations, devBuildEvalViolations, and the deduped clusters array (each: effectiveDirective, blockedURI, sourceFile, count, pages). Report-Only CSP must already be live on the target (csp_mode=report-only); if the run fails to authenticate or the header is absent, say so in the returned object.`,
  { label: 'crawl', phase: 'Crawl', schema: CRAWL_SCHEMA },
)
log(`crawl: ${crawl.pagesCrawled} pages, ${crawl.realViolations} real, ${crawl.devBuildEvalViolations} dev-eval`)

const TRIAGE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['blockedURI', 'directive', 'remediation', 'targetFile', 'rationale'],
  properties: {
    blockedURI: { type: 'string' },
    directive: { type: 'string' },
    remediation: { type: 'string', enum: ['nonce', 'externalize', 'allowlist-origin', 'refactor-handler', 'accept-dev-only', 'ignore'] },
    targetFile: { type: 'string' },
    rationale: { type: 'string' },
  },
}

let triaged = []
const realClusters = (crawl.clusters || []).filter((c) => !/eval/i.test(c.blockedURI || ''))
if (crawl.realViolations > 0 && realClusters.length) {
  phase('Triage')
  triaged = (await parallel(realClusters.map((cl) => () =>
    agent(
      `Triage this real CSP violation cluster from the admin sweep:\n${JSON.stringify(cl)}\n` +
      `Inspect the relevant code under ${ROOT} and map it to ONE remediation: nonce (inline script needs the per-request nonce), externalize (move inline JS to a file), allowlist-origin (add the origin to the matching directive in application/Csp.php), refactor-handler (inline on*= handler → delegated listener), accept-dev-only (dev-build artifact absent in prod), or ignore. Give the concrete target file and a one-line rationale.`,
      { label: `triage:${(cl.effectiveDirective || 'x').split(' ')[0]}`, phase: 'Triage', schema: TRIAGE_SCHEMA },
    )))).filter(Boolean)
}

phase('Synthesize')
const report = await agent(
  `Write a concise enforce-readiness assessment (markdown) for the admin Content-Security-Policy.\n` +
  `Crawl summary: ${JSON.stringify(crawl)}\n` +
  `Triage: ${JSON.stringify(triaged)}\n` +
  `Cover: (1) is the admin CSP safe to flip from report-only to enforce (set csp_mode=enforce in settings)? (2) any remaining real violations and their fixes; (3) confirm the dev-build eval() noise is dev-only and absent in the prod build/ bundle, so it is NOT a blocker; (4) restate the final enforcing policy from application/Csp.php. Be specific and short.`,
  { label: 'synthesize', phase: 'Synthesize' },
)
return report
