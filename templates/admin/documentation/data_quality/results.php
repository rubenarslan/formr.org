<h3 id="dq-results">Reading the results</h3>
<p>
    Each <code>data_quality</code> column holds one record per page, as text that starts with <code>{"v":2</code>.
    The functions that read them are part of the <a href="https://rubenarslan.github.io/formr/">formr R package</a>,
    version 1.3.0 or later (<code>remotes::install_github("rubenarslan/formr")</code>). Download your results from
    formr as usual and run this in R:
</p>
<pre><code class="r">library(formr)                               # 1.3.0 or later
results &lt;- read.csv("results.csv")           # your formr results export
flags &lt;- dq_flags_by_session(results)        # one row per session and flagged indicator
flags
table(flags$session, flags$group)            # how many flags of which kind per session
dq_rrt(results$rrt_ai == 1)                  # the twin question: share of AI agents and AI help</code></pre>
<p>
    <code>dq_flags_by_session()</code> finds the columns by the names used on this page (<code>dq_p1</code>,
    <code>dq_agent_p1</code>, <code>dq_srv_ua</code>, <code>dq_sig_agent</code>, <code>dq_ch_platform</code>,
    <code>dq_ch_mobile</code>) and leaves out the comparisons whose rows you did not add. If you chose other names,
    pass them (<code>pages</code>, <code>probes</code>, <code>srv_ua</code>, &hellip;). Each flag has a
    <code>group</code>: <em>environment</em> (the browser and the request), <em>input</em> (how clicks and text were
    made) or <em>attention</em> (context about a person). <code>flagged_only = FALSE</code> lists every indicator
    with its value, and <code>dq_parse()</code> turns the records into a table of their own, one row per page.
</p>
<p>
    To try it before you build your own study, import the example run
    (<a href="<?= admin_url('documentation/data-quality-run') ?>"><i class="fa fa-download"></i> Data_Quality.json</a>,
    see <a href="#dq-setup">above</a>): its last page runs the same function on your own visit and shows the result.
</p>
<div class="table-responsive">
<table class="table table-bordered">
    <thead><tr><th>What you see</th><th>What it means</th><th>Weight</th></tr></thead>
    <tbody>
        <tr><td>Any text in a <code>dq_agent</code> field</td>
            <td>Something read the invisible note: an agent, or rarely a screen-reader user who did not skip it. Even
                "no" counts: a person could not have typed it.</td><td>Strong</td></tr>
        <tr><td>Several behaviour flags together (clicks exactly in the centre, text without key presses, keys faster
                than 12 ms apart)</td>
            <td>The input came from a program, not from hands.</td><td>Strong</td></tr>
        <tr><td>"Request signed by an agent"</td>
            <td>An AI agent that says who it is (ChatGPT agent does) made the request.</td><td>Strong</td></tr>
        <tr><td>"Claims Google Chrome but has no Widevine" or "real engine differs from the one claimed"</td>
            <td>An automated or disguised browser.</td><td>Medium alone, strong together with behaviour flags</td></tr>
        <tr><td>Yes on the twin question</td>
            <td>An agent, someone who used AI help, or one of the 0.8% of people with an identical twin.</td>
            <td>Only as a share across the whole sample</td></tr>
        <tr><td>Many tab switches, long time away, many pastes</td>
            <td>Carelessness or looking things up.</td><td>Context, not a sign of an agent</td></tr>
        <tr><td>One isolated flag</td>
            <td>Often harmless, for example an unusual browser setting.</td><td>Weak</td></tr>
    </tbody>
</table>
</div>
<p>
    <strong>Review flagged cases by hand</strong> before excluding anyone, and do not withhold payment because of a
    single flag. What a model says about itself in the hidden field is unreliable (in our tests Gemma called itself
    GPT-4o); what counts is that the field was filled.
</p>
<p>How the two engines differ:</p>
<ul>
    <li><strong>Classic (v1) surveys</strong> load each page separately. A record covers one page load; a page shown
        again after a validation error adds to its record.</li>
    <li><strong>v2 surveys</strong> hold all pages in one document. formr still keeps one record per page and stores
        it with that page's answers: when the page is submitted, and with the answers saved in between. A page
        visited again (Previous, a reload) adds to its record; <code>ld</code> counts the visits.</li>
</ul>
