<h3>v2, the new survey engine</h3><hr />

<p class="lead">A <strong>Form</strong> unit shows a survey as one JavaScript-rendered document: pages are submitted in the background, <code>showif</code>s react instantly, answers are autosaved, and a page that cannot reach the server is queued and replayed later. It is opt-in per study and lives next to the classic <strong>Survey</strong> unit, which keeps working unchanged.</p>

<div class="alert alert-info">
    <strong>The one authoring rule that changes:</strong> in v2, <code>showif</code> is evaluated <em>in the participant's browser as JavaScript</em>. Server-side R goes into the <code>value</code> column, and a hidden item's <code>value</code> is how you feed an R result into a showif. Everything else — the spreadsheet format, item types (except <code>calculate</code>, which a Form does not run), R elsewhere in the run — stays as it was. See <a href="#form_v2_showif">showif and value</a>.
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

<p><strong>For participants</strong> the form feels like an app: no flicker between pages, instant follow-up questions, and resilience on flaky connections. <strong>For your data</strong> the item table, item names and results are the same, with a few differences covered in <a href="#form_v2_data">Data &amp; results</a>.</p>

<p><strong>When to use it:</strong> for any new study. For a study that is already collecting data, keep it on v1 until you have run the <a href="#form_v2_scan">compatibility scan</a>, fixed what it flags, and walked through the upgraded study yourself — see the <a href="#form_v2_upgrade">upgrade checklist</a>. Participants who are mid-form when you switch continue on the Form from where they are.</p>

<h4 id="form_v2_enable"><i class="fa fa-fw fa-toggle-on"></i> Putting a survey on v2</h4>

<p>Every survey runs on one of two engines: <strong>v1</strong>, the classic one, or <strong>v2</strong>. A new survey is on v1 unless you choose otherwise. You add a survey to a run the same way on either engine, with <strong>Add Survey</strong>; its list of surveys marks v2 surveys with <em>(v2)</em>, and the unit shows the survey on the survey's engine. There are three ways to put a survey on v2; none of them changes your items:</p>

<dl class="dl-horizontal dl-wider">
    <dt>Upload it on v2</dt>
    <dd>
        On <strong>Add a new survey</strong>, choose <strong>Engine: v2</strong> (offered when the administrator of your formr server has enabled v2). A survey exported from formr remembers its engine, so an exported v2 survey comes back on v2. Items v2 cannot run (<code>calculate</code>, see <a href="#form_v2_calculate">below</a>) are refused at upload.
    </dd>
    <dt>Move an existing survey</dt>
    <dd>
        On the survey's settings page (<strong>Surveys → <em>your survey</em></strong>), the box <strong>Survey engine: v1 or v2</strong> has <strong>Move to v2</strong> and a <a href="#form_v2_scan">compatibility check</a>. Moving is refused while a run uses the survey as a classic Survey (to try v2, upload a copy), while the survey has items v2 cannot run, and while the check flags showifs. If you are sure the check is wrong about a showif, <strong>Move to v2 anyway</strong> on the check's page moves the survey regardless.
    </dd>
    <dt>Import the example bundle</dt>
    <dd>
        The run editor's <strong>Import</strong> button accepts <code>documentation/run_components/Form_v2_Basic.json</code> from the formr repository: a v2 survey plus an end page. The bundle contains the small <code>form_v2_basic</code> survey (a multiple-choice item with a JavaScript showif on a follow-up, a <code>rank random touch</code>, a <code>visual_analog_scale</code>, a <code>bot_check</code> and one submit), so one import creates the survey on v2 under your account and adds it to the run. Your own runs export the same way: tick <em>Include survey items</em> and each survey travels with the bundle, engine included.
    </dd>
</dl>

<p><strong>Switch back to v1</strong> is possible at any time. It changes only the survey's engine; every run that shows the survey then shows it on v1. You can move it to v2 again later.</p>

<p>Once a study is on v2, its settings page gains a <strong>Form_v2 settings</strong> block:</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>Setting</th><th>Default</th><th>What it does</th></tr></thead>
    <tbody>
        <tr><td>Enable offline queue</td><td>on</td><td>A page submission that fails (no network, server error) is stored in the participant's browser and replayed when connectivity returns. Turn it off for studies whose answers must not sit on the device — a submission then fails visibly when offline. See <a href="#form_v2_offline">Autosave &amp; offline</a>.</td></tr>
        <tr><td>Show "Previous" button</td><td>off</td><td>Lets participants move back to an earlier page of the form. Going back shows the page as it was already rendered; dynamic values and labels on it are not re-computed.</td></tr>
        <tr><td>Layout</td><td>Default</td><td><em>Default — multiple items per page</em> or <em>Solo — one item per screen</em>. See <a href="#form_v2_layout">Layouts</a>.</td></tr>
        <tr><td>Participant language</td><td><code>en</code></td><td>A BCP 47 tag (<code>en</code>, <code>de</code>, <code>de-AT</code>, …). Sets the page's <code>lang</code>/<code>dir</code> attributes and translates the engine's own strings — buttons, save indicator, offline banner, validation fallbacks. English and German are built in; other tags keep the attribute but fall back to English strings. Your item labels are always shown as you wrote them.</td></tr>
        <tr><td>Button labels</td><td>empty</td><td>Your own wording for the <strong>Next</strong> and <strong>Submit</strong> buttons (plain text, up to 100 characters). Empty keeps the built-in label in the participant language. The Next label also labels a <code>submit</code> item that has no label of its own.</td></tr>
    </tbody>
</table>
<p>Three classic settings are ignored on v2 and say so on the page: the maximum number of items per page and the "use paging" option (pages always come from your <code>submit</code> items) and "instant validation" (the browser always validates on page advance).</p>

<h4 id="form_v2_showif"><i class="fa fa-fw fa-code"></i> <code>showif</code> and <code>value</code> in v2</h4>

<p>Two columns of the item table decide what a participant sees and what is pre-filled. In v2 they follow two rules:</p>
<ol>
    <li><strong><code>showif</code> is JavaScript.</strong> It runs in the participant's browser, as written, and is re-evaluated whenever an input changes. A v1 showif is R, so the compatibility scan lists every one that still contains R; the fix is a JavaScript rewrite or the hidden-item bridge below.</li>
    <li><strong><code>value</code> is R.</strong> Anything that is not empty or a plain number is sent to R on the server, as written.</li>
</ol>

<h5>Writing a <code>showif</code></h5>
<p>Every item of the form is available under its name as a live variable, so <code>mood == 3</code> or <code>age &gt;= 18 &amp;&amp; consent == 1</code> work as they are. The R idioms of a v1 showif have to be rewritten:</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>v1 (R)</th><th>v2 (JavaScript)</th></tr></thead>
    <tbody>
        <tr><td><code>current(x)</code>, <code>tail(x, 1)</code></td><td><code>x</code> — the current answer</td></tr>
        <tr><td>single <code>&amp;</code> / <code>|</code>, <code>TRUE</code> / <code>FALSE</code></td><td><code>&amp;&amp;</code> / <code>||</code>, <code>true</code> / <code>false</code></td></tr>
        <tr><td><code>x %contains% "s"</code></td><td><code>contains(x, "s")</code></td></tr>
        <tr><td><code>x %contains_word% "s"</code></td><td><code>containsWord(x, "s")</code></td></tr>
        <tr><td><code>x %begins_with% "s"</code>, <code>x %starts_with% "s"</code></td><td><code>startsWith(x, "s")</code></td></tr>
        <tr><td><code>x %ends_with% "s"</code></td><td><code>endsWith(x, "s")</code></td></tr>
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

<p><strong>Only the browser evaluates a showif.</strong> An item with a showif starts out hidden and appears as soon as its showif is true; the server never runs it. The browser knows every item of this form — including answers given on earlier pages, when the participant reloads or comes back later. Anything else, such as a run variable like <code>ran_group</code> or an item from another survey, is an unknown name: a showif that reads one is not true, so its item stays hidden. Bring such a value onto the form with a hidden item (<a href="#form_v2_bridge">below</a>). The compatibility scanner flags these names.</p>

<h5>Writing a <code>value</code></h5>
<p>A number is a literal default. Anything else is R, evaluated on the server with the participant's current answers overlaid on their data — so <code>sticky</code> keeps its meaning, and so do expressions like these:</p>
<pre>value: 5
value: sticky
value: paste(answered_items, collapse=", ")
value: sample(c(1,1,1,2,2,2,2), 7)
value: complex_score(current(q1), current(q2))</pre>
<p>The R source never reaches the browser. When the form is rendered, every dynamic <code>value</code> (and every label or choice label with inline R) is recorded on the server as an <em>allowlisted call</em> for that item; the browser only ever refers to the recorded call and can only supply answers to the study's own items. A participant cannot run R of their own, cannot reach other studies' data, and a call is revoked the moment the item is deleted or its expression changes in the sheet. Expressions that error in R resolve to NA, which arrives in the item as an empty value; a logical arrives as <code>TRUE</code> or <code>FALSE</code>. Results are cached for a few minutes per participant and answer set (see <a href="#form_v2_settings">Instance settings</a>).</p>

<p><strong>Page-scoped resolution.</strong> Dynamic values, labels (containing <code>`r …`</code>), choice labels and <code>note_iframe</code> documents are resolved when the participant reaches their page, in one batched request after the previous page has been stored — so a value on page 2 can depend on answers from page 1. The first page works the same way, so its dynamic content paints a moment after the rest of the page. Keep heavy dynamic content off the first page if that flicker matters.</p>
<p id="form_v2_calculate"><strong><code>calculate</code> items are not supported.</strong> v2 runs R only for what a page shows; it does no computation of its own. A study with a <code>calculate</code> item — or any other item that takes no input but carries R in <code>value</code>, such as a <code>server</code> item with a value — cannot be uploaded on v2, switched to v2 or linked to a Form unit; the compatibility scan lists these items. Replace each one by what it was for:</p>
<ul>
    <li><strong>the form needs the value</strong> (a showif, a pre-filled answer, a random assignment the next page branches on): a <code>hidden</code> item with the same <code>value</code> — see <a href="#form_v2_bridge">below</a>;</li>
    <li><strong>feedback or a later unit needs it</strong> (a score on the end page, eligibility for a Skip unit, a payment code): compute it there, in that unit's R, from the stored answers.</li>
</ul>

<h5 id="form_v2_bridge">Bridging R into a showif</h5>
<p>To gate visibility on something only R can compute, put the R into a <strong>hidden item's <code>value</code></strong> and reference that item's name from the showif:</p>
<table class="table table-condensed">
    <thead><tr><th>name</th><th>type</th><th>showif</th><th>value</th></tr></thead>
    <tbody>
        <tr><td><code>treat</code></td><td><code>hidden</code></td><td></td><td><code>ifelse(demographics$age &gt; 30, 1, 2)</code></td></tr>
        <tr><td><code>older_block</code></td><td><code>note</code></td><td><code>treat == 1</code></td><td></td></tr>
        <tr><td><code>younger_block</code></td><td><code>note</code></td><td><code>treat == 2</code></td><td></td></tr>
    </tbody>
</table>
<p>The hidden item is resolved on the server, its result is written into the form, and the showifs react to it like to any other answer. You can read the resolved value while testing, it is stored with the results, and several showifs can reuse it without paying for the R call again.</p>
<p>Three things to keep in mind. A hidden item is resolved when its page is shown, against the answers of <em>earlier</em> pages — its own page's answers are still empty at that moment. Its value is posted by the browser with autosave and the page submit, so a participant (or a bot) can change it; anything you analyse or act on is safer computed in a later unit's R from the stored answers. And if the page's dynamic content cannot be loaded (network, rate limit, offline), the hidden item is submitted empty.</p>
<p>If the hidden item's R names another item of this survey, it is computed again whenever its page is resolved again — after the participant went back and changed that answer, say — and the new result replaces the old one. Any other value is computed once and kept: <code>sticky</code> and earlier entries, another survey's data, a value that names only itself, a <code>sample()</code> — so a random assignment is not drawn again on every revisit.</p>
<p><strong>A page of nothing but hidden items is never shown.</strong> Its values are computed against the answers given so far and stored with the submit of the last shown page before it, so hidden items after the final submit button are computed from every answer and stored with the final page. Hidden items before the first shown page are computed when the form opens (their values can decide which pages show) and stored with the first page's submit.</p>
<p>Nothing on the form is secret: every answer this form stores is readable in the participant's browser, because showifs can use it. A value participants must not be able to see (their experimental condition, say) belongs outside the form, for example in an earlier survey of the run, and must not be referenced from a showif.</p>

<h5 id="form_v2_reserved">Reserved item names</h5>
<p>These names are rejected when you upload a sheet, on every engine: <code>session_id</code>, <code>created</code>, <code>modified</code>, <code>ended</code>, <code>expired</code>, <code>id</code>, <code>study_id</code>, <code>iteration</code> (columns of the results; in any capitalization, <code>Created</code> too, except <code>id</code>), and <code>isNA</code>, <code>answered</code>, <code>contains</code>, <code>containsWord</code>, <code>startsWith</code>, <code>endsWith</code>, <code>last</code> (the showif helpers) and <code>init</code>.</p>

<h4 id="form_v2_scan"><i class="fa fa-fw fa-search"></i> The compatibility scan</h4>

<p>Before you move an existing survey to v2, check it. The check reads every <code>showif</code> and <code>value</code> of the survey, sorts them into the groups below, and lists the items v2 cannot run. It changes nothing in your survey. Two ways to run it:</p>
<ul>
    <li><strong>In formr:</strong> the survey's settings page has a <strong>Check v2 compatibility</strong> button, in the <strong>Survey engine: v1 or v2</strong> box while the survey is on v1 and under <strong>v2 settings</strong> once it is on v2. The page lists each item to change, what is wrong and how to fix it.</li>
    <li><strong>Command line</strong> (instance administrators): <code>php bin/form_v2_compat_scan.php &lt;study_id|study_name&gt;</code> prints the same report and exits with status 0 if the study is clean and 2 if anything is flagged, so it can gate a deployment.</li>
</ul>

<table class="table table-condensed table-striped">
    <thead><tr><th>Column</th><th>Bucket</th><th>Meaning</th><th>What to do</th></tr></thead>
    <tbody>
        <tr><td rowspan="4"><code>showif</code></td><td><em>empty</em></td><td>no expression</td><td>—</td></tr>
        <tr><td><em>JS-OK</em></td><td>no R in it</td><td>nothing; test it in the browser anyway</td></tr>
        <tr><td><em>needs JS rewrite</em> (flagged, fix: <em>rewrite in JS</em>)</td><td>the showif contains R: function calls such as <code>ifelse</code>, <code>c</code>, <code>paste</code>, <code>grepl</code>, <code>current</code>, <code>tail</code>, …; <code>is.na()</code>; an infix operator such as <code>%in%</code> or <code>%contains%</code>; a single <code>&amp;</code> or <code>|</code>; <code>TRUE</code>/<code>FALSE</code>; a namespace such as <code>stringr::</code>; the assignment arrow <code>&lt;-</code>; a bare <code>NA</code>; or <code>$</code> member access like <code>survey$item</code></td><td>Rewrite it with JavaScript operators and the <a href="#form_v2_showif">helpers</a>: <code>x %in% c(1,2)</code> becomes <code>[1,2].includes(x)</code> or <code>x == 1 || x == 2</code>; <code>ifelse(a, b, c)</code> becomes <code>a ? b : c</code>; <code>is.na(x)</code> becomes <code>isNA(x)</code>; <code>survey$item</code> becomes just <code>item</code> if it is on the form. If the logic genuinely needs R (data from another survey, a function from your R package), move it into a hidden item's <code>value</code> and test that item's name in the showif — see <a href="#form_v2_bridge">Bridging R into a showif</a>.</td></tr>
        <tr><td><em>not on the form</em> (flagged)</td><td>the showif reads a name that isn't an item of this survey — a run variable such as <code>ran_group</code>, or another survey's item. The browser only knows this form's items, so the item would stay hidden</td><td>compute the value in a hidden item's <code>value</code> on this form and reference that item — see <a href="#form_v2_bridge">Bridging R into a showif</a></td></tr>
        <tr><td><code>type</code></td><td><em>not supported in v2</em> (flagged, blocks the switch)</td><td>a <code>calculate</code> item, or another item that takes no input but carries R in <code>value</code></td><td>replace it as described under <a href="#form_v2_calculate"><code>calculate</code> items are not supported</a></td></tr>
        <tr><td rowspan="3"><code>value</code></td><td><em>empty</em></td><td>no default</td><td>—</td></tr>
        <tr><td><em>literal</em></td><td>a number, used as-is</td><td>—</td></tr>
        <tr><td><em>R</em></td><td>everything else, including <code>sticky</code>; evaluated on the server</td><td>nothing — values are never flagged</td></tr>
    </tbody>
</table>

<p>String literals are ignored when looking for R tokens, so a label such as <code>"paste here"</code> does not trigger a false alarm. The scan is a heuristic, but take its showif flags seriously: a showif the browser cannot evaluate keeps its item hidden for every participant, which is why flagged showifs stop the upgrade unless you choose <strong>Upgrade anyway</strong>. Always walk through the study yourself after switching.</p>

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
<p>Each queued submission carries a unique id, so a page that reaches the server twice (the response was lost, the participant reloaded) is stored once. R-computed content needs the server: a page shown while offline keeps its placeholders — dynamic labels stay unresolved and R-computed values (including hidden items) stay empty, and are submitted empty. Pages shown after the connection is back are resolved as usual.</p>

<h5>When a queued page is rejected</h5>
<p>Answers are validated when they arrive, not when they were queued. If a queued page fails server-side validation — a required item that a client-side check let through, a choice that no longer exists — the participant is brought back to fix it: a banner says <em>Your answers for page 3 could not be saved: please fix the highlighted answers and submit the page again</em> with a <em>Go to page 3</em> button; the page opens with the errors marked; submitting it again clears the entry from the queue and sending resumes. Nothing from a rejected page is stored until it is resubmitted, and later queued pages wait behind it. Two other outcomes empty the queue without asking: the participant has meanwhile moved on to a different form (the stale entries are dropped), or the session has expired — then the banner asks them to open their personal study link to sign in again and retry. Submissions that keep failing for other reasons are kept on the device and retried automatically; the banner offers <em>Retry now</em>.</p>

<h5>Limits and lifetime</h5>
<ul>
    <li>Uploaded files are queued too, but a single file larger than the instance limit (<code>form_v2_offline_blob_max_mb</code>, 10&nbsp;MB by default) is refused with <em>Submission too large to queue offline…</em>; the participant has to retry that page online.</li>
    <li>Queued answers are plain text on the participant's device. Logging out of the run (<em>Delete Session Now / Logout</em> on its settings page, or its <code>logout</code> address) deletes that run's queue on the device, including unsent entries; other runs' queues on the same device are left alone. Uninstalling the app does not.</li>
    <li>The queue is per browser: a participant who switches devices before syncing takes the unsent pages with the old device.</li>
    <li>With <strong>Offline queue</strong> off, a failed submission shows an error and the participant stays on the page.</li>
</ul>

<h4 id="form_v2_items"><i class="fa fa-fw fa-plus-square"></i> New item types</h4>
<ul>
    <li><strong><code>rank</code></strong> — the participant orders the choices by drag and drop, buttons or keyboard. v2 only.</li>
    <li><strong><code>bot_check</code></strong> — a proof-of-work human check that runs on your own formr server, with no third party involved. v2 only.</li>
    <li><strong><code>visual_analog_scale</code></strong> — a slider without a visible default; works in classic surveys too.</li>
</ul>
<p>All three are described in the <a href="<?= site_url('documentation#available_items') ?>">item types</a> reference. <code>calculate</code> is the one item type v2 does not run (<a href="#form_v2_calculate">see above</a>).</p>

<h4 id="form_v2_data"><i class="fa fa-fw fa-table"></i> Data &amp; results</h4>
<p>A Form stores answers where a classic survey does, under the same item names: the results table, the exports and R elsewhere in the run (<code>study_name$item</code>) work unchanged. The differences:</p>
<ul>
    <li><strong>Partial data.</strong> Autosave stores each answer as it is given, so a participant who breaks off mid-page leaves what they answered so far.</li>
    <li><strong>Clearing an answer withdraws it.</strong> When a participant empties a field or deselects a choice, the next autosave removes the answer stored earlier, so it neither comes back after a reload nor stays in the data.</li>
    <li><strong>A hidden item is missing.</strong> When a showif hides an item, an answer autosaved while it was shown is removed at the next page submit — as in a classic survey, where a hidden item is always missing.</li>
    <li><strong>A choice value <code>0</code> is stored</strong> in <code>mc_multiple</code>, <code>mc_multiple_button</code>, <code>select_multiple</code> and <code>select_or_add_multiple</code> answers (the classic engine drops it: ticking 0 and 2 stores <code>2</code> there, <code>0, 2</code> in a Form). Switching a study to v2 widens those results columns where needed.</li>
    <li><strong><code>select_or_add_multiple</code> separates its answers with semicolons</strong> (<code>Berlin, Germany;Paris</code>), so a choice may contain a comma. A choice containing a semicolon is refused when the survey is uploaded to v2 or switched to it. A <code>select_or_add_one</code> or <code>select_or_add_multiple</code> choice list written in one cell is split at semicolons too (<code>red;green;blue</code>). The classic engine keeps separating both with commas; the compatibility check flags a list written in one cell with commas, which would be a single choice in a Form.</li>
    <li><strong>Layout per response.</strong> The layout each response was collected under is recorded on its session (not in the exports), so changing the layout mid-study does not rewrite history.</li>
</ul>

<h4 id="form_v2_settings"><i class="fa fa-fw fa-sliders"></i> Instance settings</h4>
<p>These are set by the instance administrator in <code>config/settings.php</code>; the defaults apply when a key is absent. The operator's guide, <code>documentation/form_v2_upgrade.md</code> in the formr repository, has the details.</p>
<table class="table table-condensed table-striped">
    <thead><tr><th>Setting</th><th>Default</th><th>What it does</th></tr></thead>
    <tbody>
        <tr><td><code>form_v2_enabled</code></td><td><code>false</code></td><td>Offers v2: the <strong>Engine</strong> choice on <strong>Add a new survey</strong> and <strong>Move to v2</strong> on a survey's settings page. Surveys already on v2 keep working without it.</td></tr>
        <tr><td><code>form_v2_rate_limits</code></td><td>30 / 90 / 10 / 20 per minute</td><td>Per participant session: page resolutions (they run R), writes (page submits, autosaves, queue replays), the unauthenticated PWA beacon (per IP), and <code>bot_check</code> challenges.</td></tr>
        <tr><td><code>form_v2_rcall_ttl</code></td><td>300&nbsp;s</td><td>How long a resolved value, label or choice label is reused for the same participant and the same answers.</td></tr>
        <tr><td><code>form_v2_offline_blob_max_mb</code></td><td>10</td><td>Largest file a page may carry into the offline queue.</td></tr>
        <tr><td><code>bot_check_*</code></td><td>see the operator's guide</td><td>Difficulty, memory and lifetime of the <code>bot_check</code> puzzle, and its signing secret (derived from the instance's encryption key by default).</td></tr>
    </tbody>
</table>

<h4 id="form_v2_upgrade"><i class="fa fa-fw fa-list-ol"></i> Upgrade checklist for a running study</h4>
<ol>
    <li>Run the <a href="#form_v2_scan">compatibility check</a>.</li>
    <li>Fix what it flags: rewrite each flagged showif in JavaScript or bridge it through a hidden item, and replace each <code>calculate</code> item (<a href="#form_v2_calculate">how</a>). Upload the corrected sheet.</li>
    <li>Try the study on v2 before switching it: export it as xlsx, upload the file under a new name with <strong>Engine: v2</strong>, and walk through every page with <strong>Test Survey</strong>. Check the flagged items and the R-computed values.</li>
    <li>If the study uses the install or push items, configure the run as an installable app and check that its manifest loads.</li>
    <li>Add the copy to your run and set its <strong>v2 settings</strong> (offline queue, Previous button, layout, language, button labels). <strong>Move to v2</strong> on the settings page works only for a survey that no run uses as a classic Survey; <strong>Switch back to v1</strong> undoes it at any time.</li>
</ol>
