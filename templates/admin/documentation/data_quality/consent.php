<h3 id="dq-consent">The consent text</h3>
<p>
    <strong>Consent is required.</strong> In the EU, reading this information from a participant's device needs their
    consent (in Germany: § 25 TDDDG), so add this paragraph to your consent page:
</p>
<pre class="fmr-doc-wrap"><?= h($consentText) ?></pre>
<ul>
    <li>Leave out the last sentence if you do not use <code>agent_probe</code> rows.</li>
    <li>If you use neither the <code>browser</code> row nor the <code>server</code> rows, write "and a checksum of
        your browser's identification" in place of "and the browser identification your browser sends": every record
        keeps that checksum (<code>uah</code>), also without those rows.</li>
    <li>Adjust the deletion period to your own plan.</li>
    <li>Keep the sentence asking people not to use AI tools: in our tests it made Claude and ChatGPT agents refuse to
        fill in the survey.</li>
</ul>
