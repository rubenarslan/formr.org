<h3 id="dq-results">Reading the results</h3>
<p>
    Each <code>data_quality</code> column holds one record per page, as text that starts with <code>{"v":2</code>.
    Download your results from formr as usual, save
    <a href="<?= admin_url('documentation/data-quality-r') ?>"><i class="fa fa-download"></i> dq_agent.R</a>
    into the same folder, and run this in R (it needs the <code>jsonlite</code> package). It prints, per participant,
    only the indicators that were flagged. The helpers will move into the formr R package later.
</p>
<pre><code class="r">source("dq_agent.R")
d &lt;- read.csv("results.csv")   # your formr results export
for (i in seq_len(nrow(d))) {
  pages &lt;- unlist(d[i, grep("^dq_p[0-9]+$", names(d))])
  agent &lt;- unlist(d[i, grep("^dq_agent_p[0-9]+$", names(d))])
  agent &lt;- paste(agent[!is.na(agent) &amp; agent != ""], collapse = " | ")
  f &lt;- dq_flags(dq_parse(pages), probe = agent, srv_ua = d$dq_srv_ua[i])
  cat("\n", d$session[i], ":", sum(nzchar(f$flag)), "flags\n")
  print(f[nzchar(f$flag), ], row.names = FALSE)
}
dq_rrt(d$rrt_ai == 1)   # share of agents and AI-assisted responses (ai_share), if you used the twin question</code></pre>
<p>Leave out <code>srv_ua = ...</code> if you have no <code>browser</code> row.</p>
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
<details>
    <summary>The R helpers (<code>dq_agent.R</code>)</summary>
    <pre><code class="r"><?= h($rHelpers) ?></code></pre>
</details>
