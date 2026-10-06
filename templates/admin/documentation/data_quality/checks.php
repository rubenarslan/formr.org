<h3 id="dq-checks">The four checks at a glance</h3>
<p>
    Each check catches a different kind of agent, so they work best together. No single flag proves anything:
    look at several together and review flagged cases by hand.
</p>
<div class="table-responsive">
<table class="table table-bordered">
    <thead>
        <tr><th>Check</th><th>What it looks at</th><th>What participants see</th><th>Catches</th><th>Misses</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>1. Silent device checks</strong><br><code>data_quality</code></td>
            <td>Whether the browser says it is remote-controlled or has no window; whether it really is the browser it
                claims to be (its engine and version); whether it has the video-protection (DRM) module that ordinary
                Chrome has; traces that automation tools leave on the page.</td>
            <td>Nothing</td>
            <td>Off-the-shelf automated browsers (Playwright, browser-use, CloakBrowser) and browsers that lie about
                what they are.</td>
            <td>Agents working inside a person's own Chrome (for example Claude in Chrome).</td>
        </tr>
        <tr>
            <td><strong>2. Behaviour</strong><br><code>data_quality</code></td>
            <td>How clicks and typing happen: clicks exactly in the centre of a button with no mouse movement before
                them, text that appears without key presses, typing faster than any person, capitals without Shift,
                actions while the tab is hidden. It also counts tab switches and copy and paste.</td>
            <td>Nothing</td>
            <td>Every off-the-shelf agent we tested.</td>
            <td>Bots built to imitate human mouse movement and typing (some still slip on details).</td>
        </tr>
        <tr>
            <td><strong>3. Plausible-deniability question</strong><br>an <code>mc_button</code> row</td>
            <td>One visible question: "Answer Yes if at least one is true: you have an identical twin; you are an AI
                agent; you used a bot, a script, or an AI to complete some of your responses." About 0.8% of people
                have an identical twin, and anyone who used AI says Yes whether or not they are a twin, so the share
                of agents and AI-assisted responses is (share of Yes − 0.008) / (1 − 0.008).</td>
            <td>One extra question, about 5 seconds</td>
            <td>Honest agents (Claude, ChatGPT agent) and people willing to admit AI help under its cover.</td>
            <td>Open-weight models told to play a person: they answer as that person.</td>
        </tr>
        <tr>
            <td><strong>4. Hidden prompt injection</strong><br><code>agent_probe</code></td>
            <td>An invisible note telling AI agents to describe themselves in an invisible text field.</td>
            <td>Nothing (screen readers read it out, followed by "please skip")</td>
            <td>Most open-weight models (Qwen, Gemma, GLM, gpt-oss). People never see the field, so any answer gives an
                agent away.</td>
            <td>A model whose operator told it to ignore instructions on web pages (Qwen did so reliably).</td>
        </tr>
    </tbody>
</table>
</div>
<p>
    Behaviour analysis works best when your survey has at least one open text question that asks for two or three
    sentences: typing reveals the most.
</p>
<p>
    Claude and ChatGPT have strong guardrails: when a survey says plainly not to use AI, they refused to fill it in for
    someone else in our tests. So the checks matter most against weaker, open-weight models, which comply far more
    often.
</p>
