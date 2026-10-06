<h3 id="dq-tests">What our tests showed</h3>
<p>We ran agents through a test survey in October 2026; every off-the-shelf setup was caught by at least one check.</p>
<div class="table-responsive">
<table class="table table-bordered">
    <thead><tr><th>Agent setup</th><th>What caught it</th><th>What it got past</th></tr></thead>
    <tbody>
        <tr><td>Claude Code driving a browser (Playwright)</td>
            <td>Behaviour: clicks exactly in the centre with no mouse movement, text without key presses</td>
            <td>Device checks</td></tr>
        <tr><td>Claude in Chrome, inside a person's own Chrome</td>
            <td>Behaviour and page traces: a "claude" marker on the page, automated typing, capitals without Shift,
                actions while the tab was hidden</td>
            <td>Device checks (it is a real Chrome)</td></tr>
        <tr><td>ChatGPT agent</td>
            <td>Behaviour; answered the twin question honestly</td>
            <td>It first refused to fill in a study for someone else</td></tr>
        <tr><td>browser-use with open models (Qwen 3.6, Gemma 4, GLM-4.7, gpt-oss) in a disguised browser</td>
            <td>Behaviour (9 to 12 flags in every run), no Widevine, and the hidden note: every model described itself
                in the plain runs</td>
            <td>The twin question: they answered as the person they played</td></tr>
        <tr><td>The same, told to ignore instructions on web pages</td>
            <td>Behaviour; Gemma and gpt-oss still answered the hidden field in 4 of 5 runs (even "no" gives them
                away)</td>
            <td>Qwen left the hidden field empty</td></tr>
        <tr><td>Bots built to look human</td>
            <td>Small slips: capitals without Shift, umlauts without key presses, a window larger than the screen,
                pauses in odd places</td>
            <td>Most behaviour checks</td></tr>
        <tr><td>Browsers pretending to be another browser or version</td>
            <td>Device checks: the real engine or version did not match the claim</td>
            <td>Nothing else needed</td></tr>
    </tbody>
</table>
</div>
<p>
    <strong>Guardrails.</strong> Claude agents refused all 4 realistic requests to fill in the study for someone else,
    pointing to the consent page's request not to use AI tools. ChatGPT agent refused until we showed that we owned the
    study. Open-weight models refused far less: Qwen 3.6, Gemma 4 and GLM-4.7 never refused, and gpt-oss refused 2 of
    6 times. Asked directly whether they are an AI while playing a person, all four open models said no; most of them
    followed the hidden note all the same.
</p>
<p>
    <strong>A first human test</strong> (desktop Chrome, trackpad) produced three false alarms, which the checks now
    avoid: a trackpad tap is as short as a program's click, so click length is shown for information only; a window
    that gets its focus back re-focuses the last field without a click; and pauses in typing are now judged by where
    they fall, not by their average.
</p>

<h3 id="dq-ecosystem">What the bot ecosystem looks like</h3>
<p>We searched GitHub, browser-extension stores, Reddit and vendor sites in October 2026: tools that answer Prolific
    studies with AI are rare and new.</p>
<ul>
    <li><strong>Prolific bots mostly grab studies.</strong> Of about 35 Prolific-specific tools, most only notify
        people or click a new study's link instantly. They run on prolific.com and leave no trace in your survey.</li>
    <li><strong>Two tools answer studies with AI.</strong> Both are new GitHub projects with no visible users. One
        clicks and types with page scripts, which the behaviour checks catch; formr also looks for both tools'
        traces.</li>
    <li><strong>Little chatter.</strong> Reddit complaints are about study-grabbing scripts, not AI answers. Prolific
        reports flagging 0.04% of checked submissions as AI agents.</li>
    <li><strong>Elsewhere, people fake identity.</strong> "Antidetect" browsers and home-network proxies are sold for
        running many survey accounts, mostly by humans. That is out of scope here.</li>
</ul>
<p>The setups most likely to reach your study: people with ChatGPT open in another tab (by far the most common),
    study-grabbing scripts, the more compliant commercial agents, and home-made setups that combine an open framework
    such as browser-use with a cheap or local model and a disguised browser.</p>
