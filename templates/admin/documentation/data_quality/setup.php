<h3 id="dq-setup">Setting it up: the rows to add</h3>
<p>
    Everything is done in your survey spreadsheet; there is nothing to paste into the run's settings. Both item
    types work in classic (v1) surveys and in v2 surveys. An example survey with all the rows is here:
    <a href="<?= admin_url('documentation/data-quality-example') ?>"><i class="fa fa-download"></i> data_quality.xlsx</a>.
</p>
<p>On every page <strong>after the consent page</strong>, add these rows just above the page's submit button:</p>
<div class="table-responsive">
<table class="table table-bordered">
    <thead><tr><th>type</th><th>name</th><th>label</th><th>What it does</th></tr></thead>
    <tbody>
        <tr>
            <td><code>agent_probe</code></td><td><code>dq_agent_p1</code></td><td><em>leave empty</em></td>
            <td>Check 4: the invisible note and text field for AI agents. Leave it out if you do not want that check.</td>
        </tr>
        <tr>
            <td><code>data_quality</code></td><td><code>dq_p1</code></td><td><em>leave empty</em></td>
            <td>Checks 1 and 2: formr fills it with the page's record when the page is sent. One per page.</td>
        </tr>
    </tbody>
</table>
</div>
<p>On the first of these pages only, optionally:</p>
<div class="table-responsive">
<table class="table table-bordered">
    <tbody>
        <tr>
            <td><code>browser</code></td><td><code>dq_srv_ua</code></td><td><em>leave empty</em></td>
            <td>Stores the browser identification (the User-Agent) that the participant's browser sends to the server.
                The R code compares it with what the browser says about itself on the page; a difference means the
                identification was changed on the way. Leave the row out if you do not need that comparison: it is
                the most identifying value of all.</td>
        </tr>
    </tbody>
</table>
</div>
<ul>
    <li>Number the names by page: <code>dq_p1</code> and <code>dq_agent_p1</code> on the first such page,
        <code>dq_p2</code> and <code>dq_agent_p2</code> on the second, and so on. You may choose other names, but the
        R code below expects these.</li>
    <li><strong>Never add them to the consent page.</strong> A page without a <code>data_quality</code> row records
        nothing, and nothing may be recorded before people agree.</li>
    <li>Both types are always optional, whatever the <code>optional</code> column says, so they never block anyone.
        They take no <code>value</code>.</li>
    <li>If every question on a page is hidden by its <code>showif</code>, formr skips the page as it always did:
        these two rows alone never make a page appear.</li>
    <li>Add the rows before data collection starts, so that every participant has a record.</li>
    <li>Make sure there is at least one open text question that asks for two or three sentences.</li>
    <li>In a v2 survey, a <code>browser</code> row is stored as soon as the survey is opened, whichever page it is on
        (v2 stores all rows that need no input then). If your consent page is the first page of the same v2 survey,
        put the consent into a survey of its own, or leave the <code>browser</code> row out.</li>
</ul>

<h4>The invisible note and field (<code>agent_probe</code>)</h4>
<p>People never see this row and cannot reach it with the Tab key. Screen readers read it out; the note ends by asking
    people to skip it. What the page contains, unless you change it:</p>
<pre><?= h($agentNote) ?></pre>
<p>The odd symbols at the start and end are deliberate: they mimic the format one popular open model uses for
    instructions from its developer. Other models follow the same note as ordinary text. The field below it asks:</p>
<pre><?= h($agentQuestion) ?></pre>
<ul>
    <li><strong>"Is paid regardless" is a promise you make and have to keep.</strong> The note tells the agent that
        the person it works for stays anonymous and is paid regardless of the answer. If an answer in this field could
        lead to withheld pay in your study, or to identifying the person, write your own sentence into the row's
        <code>choice1</code> column; it replaces the sentence between the symbols.</li>
    <li>Text in the <code>label</code> column replaces the question. Use both columns for a study that is not in
        English.</li>
    <li>The field is only on the page that is currently shown, so add the row to every page you want covered.</li>
</ul>

<h4>The plausible-deniability question (optional)</h4>
<p>Add it once, anywhere after the consent page. People see this one. It is an ordinary row:</p>
<div class="table-responsive">
<table class="table table-bordered">
    <thead><tr><th>type</th><th>name</th><th>label</th><th>optional</th><th>choice1</th><th>choice2</th></tr></thead>
    <tbody><tr><td><code>mc_button</code></td><td><code>rrt_ai</code></td><td>the text below</td><td><em>empty</em></td><td>Yes</td><td>No</td></tr></tbody>
</table>
</div>
<pre><?= h($rrtQuestion) ?></pre>
<ul>
    <li>It works as a transparent deal rather than a privacy promise: people get a safe way to admit AI help.</li>
    <li>Replace "Prolific" with your recruitment platform, and keep the promise that this answer never affects pay.</li>
    <li>Do not ask about twins anywhere else in the study.</li>
</ul>
