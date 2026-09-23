<h3>Form (v2) — the new survey engine</h3><hr />

<p class="lead">A <strong>Form</strong> unit shows a survey as one JavaScript-rendered document: pages are submitted in the background, <code>showif</code>s react instantly, answers are autosaved, and a page that cannot reach the server is queued and replayed later. It is opt-in per study and lives next to the classic <strong>Survey</strong> unit, which keeps working unchanged.</p>

<div class="alert alert-info">
    <strong>The one authoring rule that changes:</strong> in a Form, <code>showif</code> is evaluated <em>in the participant's browser as JavaScript</em>. Server-side R goes into the <code>value</code> column, and a hidden item's <code>value</code> is how you feed an R result into a showif. Everything else — the spreadsheet format, item types, R elsewhere in the run — stays as it was. See <a href="#form_v2_showif">showif and value</a>.
</div>

<ul class="list-inline" id="form_v2_toc">
    <li><a href="#form_v2_what">What it is</a></li>
    <li><a href="#form_v2_enable">Enabling it</a></li>
    <li><a href="#form_v2_showif">showif &amp; value</a></li>
    <li><a href="#form_v2_scan">Compatibility scan</a></li>
    <li><a href="#form_v2_layout">Layouts</a></li>
    <li><a href="#form_v2_offline">Autosave &amp; offline</a></li>
    <li><a href="#form_v2_items">New item types</a></li>
    <li><a href="#form_v2_data">Data &amp; results</a></li>
    <li><a href="#form_v2_settings">Instance settings</a></li>
    <li><a href="#form_v2_upgrade">Upgrade checklist</a></li>
</ul>

<h4 id="form_v2_what"><i class="fa fa-fw fa-wpforms"></i> What Form (v2) is, and when to use it</h4>

<p>Classic Survey units render one page per request: every submit is a full page load, and every <code>showif</code> that cannot be decided in the browser goes back to the server. A Form unit instead renders <em>all</em> pages of the survey into a single document at the first load. From then on:</p>
<ul>
    <li><strong>Pages are submitted without a reload.</strong> The page boundaries are still your <code>submit</code> items; each page is posted in the background and the next page appears in place.</li>
    <li><strong><code>showif</code>s are live.</strong> They are compiled to JavaScript and re-evaluated on every input, with no round-trip.</li>
    <li><strong>Answers are autosaved.</strong> Each committed answer is saved in the background (at most one request per 20 seconds per page, plus a final flush when the tab is hidden or closed), so a participant who breaks off mid-page still leaves partial data.</li>
    <li><strong>Offline queue.</strong> If a page submit fails because there is no network (or the server errors), the page is stored in the browser and replayed once the participant is back online — see <a href="#form_v2_offline">Autosave &amp; offline</a>.</li>
    <li><strong>Optional "solo" layout</strong> — one item per screen, auto-advance on single-choice items — and localized engine strings (buttons, banners) in the participant's language. See <a href="#form_v2_layout">Layouts</a>.</li>
    <li><strong>PWA items</strong> (<code>add_to_home_screen</code>, <code>push_notification</code>, <code>request_phone</code>, <code>request_cookie</code>) work as in v1.</li>
</ul>

<p><strong>For participants</strong> the form feels like an app: no flicker between pages, instant follow-up questions, and resilience on flaky connections. <strong>For your data</strong> the item table, item names and stored values are the same; what changes is how the results are stored and read (long format, a per-study <code>iteration</code> counter and a recorded layout per response), which is covered in <a href="#form_v2_data">Data &amp; results</a>.</p>

<p><strong>When to use it:</strong> for any new study. For a study that is already collecting data, keep it on the Survey unit until you have run the <a href="#form_v2_scan">compatibility scan</a>, fixed what it flags, and walked through the upgraded study yourself — see the <a href="#form_v2_upgrade">upgrade checklist</a>. Participants who are mid-form when you switch finish on the old engine; only new sessions get the Form.</p>

<h4 id="form_v2_enable"><i class="fa fa-fw fa-toggle-on"></i> Enabling Form (v2) for a study</h4>

<p>Every study has a <strong>rendering engine</strong> (<code>v1</code>, the classic Survey pipeline, or <code>v2</code>, the Form engine). New studies start on v1. There are three ways to put a study on v2 — all from the admin interface, none of them touches your items:</p>

<dl class="dl-horizontal dl-wider">
    <dt>Add a Form unit</dt>
    <dd>
        In the run editor, <strong>Add Form (form_v2, beta)</strong> creates a Form unit; picking a study in its dropdown and saving links the study to the unit <em>and</em> switches that study to the v2 engine. The button only appears if the instance administrator has enabled <code>form_v2_enabled</code> (see <a href="#form_v2_settings">Instance settings</a>); Form units that already exist keep working whether or not that setting is on.
    </dd>
    <dt>Upgrade an existing study</dt>
    <dd>
        On the study's settings page (<strong>Surveys → <em>your study</em></strong>) the <strong>Rendering engine</strong> box shows a compatibility summary and an <strong>Upgrade to form_v2</strong> button. Upgrading sets the study to v2 and converts every run unit that uses the study as a classic Survey — in any run, including other people's — into a Form unit pointing at the same study. Sessions that are already inside such a unit are left alone: those participants finish on the classic engine, while every session that reaches the unit from then on gets the Form. Nothing blocks the switch (not even flagged scan results), so run the <a href="#form_v2_scan">compatibility scan</a> first.
    </dd>
    <dt>Import the example bundle</dt>
    <dd>
        The run editor's <strong>Import</strong> button accepts <code>documentation/run_components/Form_v2_Basic.json</code> from the formr repository: a Form unit plus an end page. The Form embeds the tiny <code>form_v2_basic</code> study (a multiple-choice item with a JavaScript showif on a follow-up, a <code>rank random touch</code>, a <code>visual_analog_scale</code>, a <code>bot_check</code> and one submit), so a single import creates the study under your account (reusing a same-named study if you have one), links it and stamps it v2. Your own runs export the same way: tick <em>Include survey items</em> and each Form's study travels with the bundle.
    </dd>
</dl>

<p><strong>Switch back to v1</strong>, next to the upgrade button, is safe at any time: it only changes the study's engine, and a Form unit whose study is on v1 simply renders through the classic pipeline. You can switch forward again later.</p>

<p>Once a study is on v2, its settings page gains a <strong>Form_v2 settings</strong> block:</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>Setting</th><th>Default</th><th>What it does</th></tr></thead>
    <tbody>
        <tr><td>Enable offline queue</td><td>on</td><td>A page submission that fails (no network, server error) is stored in the participant's browser and replayed when connectivity returns. Turn it off for studies whose answers must not sit on the device — a submission then fails visibly when offline. See <a href="#form_v2_offline">Autosave &amp; offline</a>.</td></tr>
        <tr><td>Show "Previous" button</td><td>off</td><td>Lets participants move back to an earlier page of the form. Going back shows the page as it was already rendered; dynamic values and labels on it are not re-computed.</td></tr>
        <tr><td>Layout</td><td>Default</td><td><em>Default — multiple items per page</em> or <em>Solo — one item per screen</em>. See <a href="#form_v2_layout">Layouts</a>.</td></tr>
        <tr><td>Participant language</td><td><code>en</code></td><td>A BCP 47 tag (<code>en</code>, <code>de</code>, <code>de-AT</code>, …). Sets the page's <code>lang</code>/<code>dir</code> attributes and translates the engine's own strings — buttons, save indicator, offline banner, validation fallbacks. English and German are built in; other tags keep the attribute but fall back to English strings. Your item labels are always shown as you wrote them.</td></tr>
    </tbody>
</table>
<p>Three classic settings are ignored on v2 and say so on the page: the maximum number of items per page and the "use paging" option (pages always come from your <code>submit</code> items) and "instant validation" (the browser always validates on page advance).</p>

<h4 id="form_v2_showif"><i class="fa fa-fw fa-code"></i> <code>showif</code> and <code>value</code> in a Form</h4>

<p>Two columns of the item table decide what a participant sees and what is pre-filled. On a Form they follow two rules:</p>
<ol>
    <li><strong><code>showif</code> is JavaScript.</strong> It runs in the participant's browser and is re-evaluated whenever an input changes. Familiar R-flavoured conditions are rewritten to JavaScript for you when you upload the sheet; what cannot be rewritten is listed by the compatibility scan, and the fix is either a JavaScript rewrite or the hidden-item bridge below.</li>
    <li><strong><code>value</code> is R.</strong> Anything that is not empty or a plain number is sent to R on the server. Writing it bare or wrapped in <code>r(...)</code> means the same thing — the wrapper is stripped before evaluation.</li>
</ol>

<h5>Writing a <code>showif</code></h5>
<p>Every item of the form is available under its name as a live variable, and the usual R-flavoured showifs are rewritten to JavaScript for you when you upload the sheet. So <code>mood == 3</code>, <code>age &gt;= 18 &amp;&amp; consent == 1</code> or <code>pets %contains% "cat"</code> work exactly as before. These rewrites are applied:</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>You write (R-style)</th><th>What runs in the browser</th></tr></thead>
    <tbody>
        <tr><td><code>current(x)</code>, <code>tail(x, 1)</code></td><td><code>x</code> — the current answer</td></tr>
        <tr><td>single <code>&amp;</code> / <code>|</code>, <code>TRUE</code> / <code>FALSE</code></td><td><code>&amp;&amp;</code> / <code>||</code>, <code>true</code> / <code>false</code></td></tr>
        <tr><td><code>x %contains% "s"</code></td><td><code>x.toString().indexOf("s") &gt; -1</code></td></tr>
        <tr><td><code>x %contains_word% "s"</code></td><td>a whole-word match on <code>x</code></td></tr>
        <tr><td><code>x %begins_with% "s"</code>, <code>x %starts_with% "s"</code></td><td><code>x.toString().indexOf("s") === 0</code></td></tr>
        <tr><td><code>x %ends_with% "s"</code></td><td><code>x.toString().endsWith("s")</code></td></tr>
        <tr><td><code>stringr::str_length(x)</code></td><td><code>x.length</code></td></tr>
        <tr><td><code>is.na(x)</code></td><td><code>isNA(x)</code></td></tr>
    </tbody>
</table>
<p>On top of the item variables, these helpers are in scope (their names are <em>reserved</em> — you cannot name an item after them):</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>Helper</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td><code>isNA(x)</code></td><td>true for an unanswered item: <code>null</code>, <code>undefined</code>, the empty string, an empty selection, or <code>NaN</code>. Note that <code>0</code> is an answer, not NA.</td></tr>
        <tr><td><code>answered(x)</code></td><td><code>!isNA(x)</code></td></tr>
        <tr><td><code>contains(x, needle)</code></td><td>for a multiple-selection item: was <code>needle</code> ticked; for text: does it contain the substring. False when <code>x</code> is NA.</td></tr>
        <tr><td><code>containsWord(x, word)</code></td><td>whole-word match in the text of <code>x</code></td></tr>
        <tr><td><code>startsWith(x, s)</code>, <code>endsWith(x, s)</code></td><td>prefix / suffix test on the text of <code>x</code></td></tr>
        <tr><td><code>last(x)</code></td><td>the last element of a multiple selection; a single value is returned unchanged</td></tr>
    </tbody>
</table>
<p>How answers look inside a showif: a number-like answer is a number (so <code>age &gt; 17</code> works without quotes), anything else is a string; an unanswered <code>mc</code> is <code>null</code>; a <code>mc_multiple</code> / <code>select_multiple</code> is an array of the ticked choice values (use <code>contains(pets, 2)</code>, not <code>pets == 2</code>); an unticked <code>check</code> is <code>0</code>, matching what R sees. When a showif hides an item, that item's value is retracted from the live variables (as it will not be submitted), so showifs chained on it react too.</p>

<pre>showif: mood == 3
showif: trigger == "yes" &amp;&amp; answered(plain)
showif: !contains(animals, 1)
showif: last(daily_mood) &gt; 5</pre>

<p><strong>Only the browser evaluates a showif.</strong> An item with a showif starts out hidden and appears as soon as its showif is true; the server never runs it. The browser knows every item of this form — including answers given on earlier pages, when the participant reloads or comes back later — and the results of <code>calculate</code> items. Anything else, such as a run variable like <code>ran_group</code> or an item from another survey, is an unknown name: a showif that reads one is not true, so its item stays hidden. Bring such a value onto the form with a hidden item (<a href="#form_v2_bridge">below</a>). A showif on a <code>calculate</code> item has no effect, because a calculate is computed on the server; put the condition into its R instead (<code>if (condition) value else NA</code>). The compatibility scanner flags both.</p>

<h5>Writing a <code>value</code></h5>
<p>A number is a literal default. Anything else is R, evaluated on the server with the participant's current answers overlaid on their data — so <code>sticky</code> keeps its meaning, and so do expressions like these:</p>
<pre>value: 5
value: sticky
value: paste(answered_items, collapse=", ")
value: sample(c(1,1,1,2,2,2,2), 7)
value: r(complex_score(current(q1), current(q2)))</pre>
<p>The R source never reaches the browser. When the form is rendered, every dynamic <code>value</code> (and every label or choice label with inline R) is recorded on the server as an <em>allowlisted call</em> for that item; the browser only ever refers to the recorded call and can only supply answers to the study's own items. A participant cannot run R of their own, cannot reach other studies' data, and a call is revoked the moment the item is deleted or its expression changes in the sheet. Expressions that error in R resolve to NA, which arrives in the item as an empty value; a logical arrives as <code>TRUE</code> or <code>FALSE</code>. Results are cached for a few minutes per participant and answer set (see <a href="#form_v2_settings">Instance settings</a>).</p>

<p><strong>Page-scoped resolution.</strong> Dynamic values, labels (containing <code>`r …`</code>), choice labels and <code>note_iframe</code> documents are resolved when the participant reaches their page, in one batched request after the previous page has been stored — so a value on page 2 can depend on answers from page 1. The first page works the same way, so its dynamic content paints a moment after the rest of the page. Keep heavy dynamic content off the first page if that flicker matters.</p>
<p><strong><code>calculate</code> items</strong> are the study's own computation, so they never go through the browser: the server evaluates and stores them when the participant reaches their page, against the answers stored by then (as in a classic survey, a calculate uses earlier pages, not its own). A logical is stored as <code>TRUE</code>/<code>FALSE</code>, NA as missing.</p>

<h5 id="form_v2_bridge">Bridging R into a showif</h5>
<p>To gate visibility on something only R can compute, put the R into a <strong>hidden item's <code>value</code></strong> and reference that item's name from the showif:</p>
<table class="table table-condensed">
    <thead><tr><th>name</th><th>type</th><th>showif</th><th>value</th></tr></thead>
    <tbody>
        <tr><td><code>treat</code></td><td><code>hidden</code></td><td></td><td><code>r(ifelse(demographics$age &gt; 30, 1, 2))</code></td></tr>
        <tr><td><code>older_block</code></td><td><code>note</code></td><td><code>treat == 1</code></td><td></td></tr>
        <tr><td><code>younger_block</code></td><td><code>note</code></td><td><code>treat == 2</code></td><td></td></tr>
    </tbody>
</table>
<p>The hidden item is resolved on the server, its result is written into the form, and the showifs react to it like to any other answer. You can read the resolved value while testing, it is stored with the results, and several showifs can reuse it without paying for the R call again.</p>

<h5 id="form_v2_reserved">Reserved item names</h5>
<p>These names are rejected when you upload a sheet, on every engine: <code>session_id</code>, <code>created</code>, <code>modified</code>, <code>ended</code>, <code>id</code>, <code>study_id</code>, <code>iteration</code> (columns of the results), and <code>isNA</code>, <code>answered</code>, <code>contains</code>, <code>containsWord</code>, <code>startsWith</code>, <code>endsWith</code>, <code>last</code> (the showif helpers).</p>

<h4 id="form_v2_scan"><i class="fa fa-fw fa-search"></i> The compatibility scan</h4>

<p>Before you move an existing study to the Form engine, scan it. The scan reads every non-empty <code>showif</code> and <code>value</code> of the study and sorts them into buckets. It changes nothing — it is a report. Two ways to run it:</p>
<ul>
    <li><strong>Admin interface:</strong> on the study's settings page. While the study is still on v1, the <em>Rendering engine</em> box shows the number of flagged expressions next to <strong>Upgrade to form_v2</strong> and a <strong>Full compatibility report</strong> button; once it is on v2, the <em>Form_v2 settings</em> block has a <strong>Run v2 compatibility scan</strong> button. The report lists, per item, the source, the transpiled JavaScript and the suggested fix.</li>
    <li><strong>Command line</strong> (instance administrators): <code>php bin/form_v2_compat_scan.php &lt;study_id|study_name&gt;</code> prints the same report and exits with status 0 if the study is clean and 2 if anything is flagged, so it can gate a deployment.</li>
</ul>

<table class="table table-condensed table-striped">
    <thead><tr><th>Column</th><th>Bucket</th><th>Meaning</th><th>What to do</th></tr></thead>
    <tbody>
        <tr><td rowspan="3"><code>showif</code></td><td><em>empty</em></td><td>no expression</td><td>—</td></tr>
        <tr><td><em>JS-OK</em></td><td>transpiles to JavaScript without leftover R</td><td>nothing; test it in the browser anyway</td></tr>
        <tr><td><em>needs JS rewrite</em> (flagged, fix: <em>rewrite in JS</em>)</td><td>the transpiled expression still contains R-only pieces: function calls such as <code>ifelse</code>, <code>c</code>, <code>paste</code>, <code>grepl</code>, <code>sprintf</code>, <code>unique</code>, <code>which</code>, …; <code>tail</code>/<code>head</code> with an argument other than <code>1</code>; an <code>is.na()</code> that did not transpile; <code>%in%</code> or <code>%%</code>; the assignment arrow <code>&lt;-</code>; a bare <code>NA</code>; or <code>$</code> member access like <code>survey$item</code></td><td>Rewrite it with JavaScript operators and the <a href="#form_v2_showif">helpers</a>: <code>x %in% c(1,2)</code> becomes <code>[1,2].includes(x)</code> or <code>x == 1 || x == 2</code>; <code>ifelse(a, b, c)</code> becomes <code>a ? b : c</code>; <code>is.na(x)</code> becomes <code>isNA(x)</code>; <code>survey$item</code> becomes just <code>item</code> if it is on the form. If the logic genuinely needs R (data from another survey, a function from your R package), move it into a hidden item's <code>value</code> and test that item's name in the showif — see <a href="#form_v2_bridge">Bridging R into a showif</a>.</td></tr>
        <tr><td rowspan="3"><code>value</code></td><td><em>empty</em></td><td>no default</td><td>—</td></tr>
        <tr><td><em>literal</em></td><td>a number, used as-is</td><td>—</td></tr>
        <tr><td><em>R</em></td><td>everything else, including <code>sticky</code>; evaluated on the server</td><td>nothing — values are never flagged</td></tr>
    </tbody>
</table>

<p>String literals are ignored when looking for R tokens, so a label such as <code>"paste here"</code> does not trigger a false alarm. The scan is a heuristic: a flagged showif is not necessarily broken (at runtime an expression the browser cannot evaluate simply leaves the item as the server rendered it), and a JS-OK showif can still reference a name the browser doesn't know. Always walk through the study yourself after switching.</p>

<h4 id="form_v2_layout"><i class="fa fa-fw fa-columns"></i> Layout modes</h4>

<p>The <strong>Layout</strong> setting on the survey's settings page (visible once the study is on v2) chooses how a page is presented. Both layouts use the same items, the same pages and the same data; only the presentation differs. The layout a participant actually got is recorded on their session, so you can change it mid-study without corrupting anything (it is not exported, though).</p>

<h5>Default — multiple items per page</h5>
<ul>
    <li>All items of an authored page (everything up to the next <code>submit</code> item) are shown together, as in the classic engine. A progress line reads <em>Page 2 of 5</em>.</li>
    <li>The <strong>Next</strong> button sticks to the bottom of the viewport. On phones (screens narrower than 768&nbsp;px) it becomes a full-width floating bar so it is always reachable without scrolling to the end of a long page. A page that has its own <code>submit</code> item uses that button's label instead.</li>
    <li><strong>Previous</strong> appears only if <em>Allow "Previous" button</em> is switched on for the study. It is off by default: with it off, the browser's back button is also neutralised, because every page of the form is already in the document and a participant could otherwise edit a page that has been submitted.</li>
    <li>A small <strong>save indicator</strong> (a pill next to the navigation) shows <em>Saving…</em>, <em>Saved</em>, <em>Synced</em> or <em>Saved offline</em> — see <a href="#form_v2_offline">Offline and autosave</a>.</li>
</ul>

<h5>Solo — one item per screen</h5>
<p>Each item gets a screen of its own; the participant works through the page item by item, the screen scrolls to the next item as soon as one is answered, and the page is submitted after the last item of the page. Items hidden by a showif are skipped and appear as soon as their showif becomes true. Design for it: keep labels short, and put a <code>note</code> before a block of questions rather than lengthy help text on every item.</p>
<ul>
    <li><strong>Auto-advance.</strong> Single-choice items (<code>mc</code>, <code>mc_button</code>, <code>rating_button</code>, <code>select_one</code>, sliders such as <code>range</code> and <code>visual_analog_scale</code>) advance when a choice is made. For a plain radio item the <strong>OK</strong> button is hidden until an option is picked; selects and sliders keep OK visible because they may carry a default the participant simply accepts. Checkboxes, multiple selects, text fields and textareas need an explicit <strong>OK</strong> (the hint under a multiple-choice item says <em>Choose all that apply, then OK</em>).</li>
    <li><strong>Keyboard.</strong> <kbd>Enter</kbd> continues on text inputs, choice items and notes (hint: <em>press Enter ↵</em>). In a textarea <kbd>Enter</kbd> inserts a newline; <kbd>Ctrl</kbd>+<kbd>Enter</kbd> (<kbd>⌘</kbd>+<kbd>Enter</kbd> on a Mac) continues. <kbd>↓</kbd> and <kbd>↑</kbd> move to the next and previous item when the focus is not inside a text field or a radio/checkbox group (there the arrows move between options) and the current screen fits the viewport (otherwise they scroll). Widgets with their own keyboard handling — the rank list, date pickers, searchable selects — keep it.</li>
    <li><strong>Back</strong> goes to the previous item of the current page. Going back to a <em>previous page</em> is only possible with <em>Allow "Previous" button</em> on.</li>
    <li>The progress bar never moves backwards, even when a showif reveals additional items mid-page; the <em>3 of 7</em> counter stays exact.</li>
    <li>On phones the layout accounts for the on-screen keyboard: a screen that fits the visible area is locked in place, longer screens scroll normally.</li>
</ul>
<p>Everything else — autosave, the offline queue, validation, R-computed values — behaves identically in both layouts. The two example spreadsheets <code>pwa_items_default.xlsx</code> and <code>pwa_items_solo.xlsx</code> are byte-identical; they exist only to be uploaded twice with the setting flipped.</p>

<h4 id="form_v2_offline"><i class="fa fa-fw fa-plug"></i> Offline and autosave</h4>

<h5>What is saved when</h5>
<table class="table table-condensed table-striped">
    <thead><tr><th>Moment</th><th>What happens</th><th>Indicator</th></tr></thead>
    <tbody>
        <tr><td>An answer changes</td><td><strong>Autosave.</strong> The changed answers are sent in the background, at most once every 20 seconds (the last change always lands). Only answers that pass the item's own validation are stored; invalid ones are silently skipped until the page is submitted. Two item types are never autosaved: <code>bot_check</code> (its token is single-use) and file uploads (bytes travel only with the page submission).</td><td><em>Saving…</em> → <em>Saved</em></td></tr>
        <tr><td>The tab is hidden or closed</td><td>Pending changes are flushed with a beacon so a participant who closes the app mid-page loses at most what they typed in the last few seconds.</td><td>—</td></tr>
        <tr><td><strong>Next</strong> / <strong>Submit</strong></td><td><strong>Page submission.</strong> The page is validated in the browser, then posted. The server validates again and stores the answers; on validation errors the page stays with the errors highlighted, exactly as in the classic engine. On success the next page is shown — the rest of the form is already in the browser, so this takes no round-trip except for R-computed values and labels on the new page.</td><td><em>Saved</em></td></tr>
        <tr><td>Last page submitted</td><td>A short <em>Thanks — sending you to the next step…</em> overlay, then the participant moves on in the run.</td><td>—</td></tr>
    </tbody>
</table>
<p>Autosaved answers are what a participant sees again when they reopen the form on the same page. Autosave never advances the participant and never completes the form — only page submissions do.</p>

<h5>The offline queue</h5>
<p>With <strong>Offline queue</strong> on for the study (the default), a page submission that fails because the network is down or the server answers with a 5xx error is stored in the browser (IndexedDB) instead of being lost, and the participant continues to the next page as if it had gone through. A rejection with a 4xx status — validation errors, an expired session — is <em>not</em> queued; it is shown right away. What the participant sees:</p>
<ul>
    <li>The save indicator shows <em>Saved offline</em>; a banner says <em>You're offline. This submission is queued and will be sent when you reconnect.</em></li>
    <li>While offline, the banner reads <em>Offline — submissions will sync automatically when you reconnect.</em></li>
    <li>When the connection returns, or the form is opened again, the queue is sent in order: <em>Syncing 2 queued submission(s)…</em>, then <em>All queued submissions have been sent.</em> and the indicator shows <em>Synced</em>. Sending happens from an open page of the form; a closed tab does not sync in the background.</li>
</ul>
<p>Each queued submission carries a unique id, so a page that reaches the server twice (the response was lost, the participant reloaded) is stored once. R-computed values on later pages are resolved when the connection is back, which means a queued page whose <code>value</code> depends on the previous (also queued) page's answers is filled in only once both have been sent.</p>

<h5>When a queued page is rejected</h5>
<p>Answers are validated when they arrive, not when they were queued. If a queued page fails server-side validation — a required item that a client-side check let through, a choice that no longer exists — the participant is brought back to fix it: a banner says <em>Your answers for page 3 could not be saved: please fix the highlighted answers and submit the page again</em> with a <em>Go to page 3</em> button; the page opens with the errors marked; submitting it again clears the entry from the queue and sending resumes. Nothing from a rejected page is stored until it is resubmitted, and later queued pages wait behind it. Two other outcomes empty the queue without asking: the participant has meanwhile moved on to a different form (the stale entries are dropped), or the session has expired — then the banner asks them to open their personal study link to sign in again and retry. Submissions that keep failing for other reasons are kept on the device and retried automatically; the banner offers <em>Retry now</em>.</p>

<h5>Limits and lifetime</h5>
<ul>
    <li>Uploaded files are queued too, but a single file larger than the instance limit (<code>form_v2_offline_blob_max_mb</code>, 10&nbsp;MB by default) is refused with <em>Submission too large to queue offline…</em>; the participant has to retry that page online.</li>
    <li>Queued answers are plain text on the participant's device. Following a <strong>logout</strong> link in the run wipes that run's queue, including unsent entries; other runs' queues on the same device are left alone.</li>
    <li>The queue is per browser: a participant who switches devices before syncing takes the unsent pages with the old device.</li>
    <li>With <strong>Offline queue</strong> off, a failed submission shows an error and the participant stays on the page.</li>
</ul>
