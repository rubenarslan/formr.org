<h3 id="dq-limits">Privacy and limits</h3>
<p>A record holds counts, durations and yes/no values, never what people type or where they move the mouse.</p>
<ul>
    <li><strong>Not stored:</strong> IP address, keystrokes, mouse paths, clipboard contents, a device fingerprint.</li>
    <li><strong>Most identifying item:</strong> the browser identification stored by the <code>browser</code> row.
        Leave that row out if you do not need the comparison it allows.</li>
    <li><strong>Why this is not fingerprinting:</strong> the device checks use similar techniques, but each stores
        only a yes/no answer that already follows from the browser's own identification. Nothing is combined into an
        identifier or used to recognise a device later. Deliberately left out: canvas, audio or graphics-card hashes,
        font lists, exact versions. The DRM check only asks whether a system exists; it requests no licence and reads
        no device identifier.</li>
    <li><strong>Accessibility:</strong> one combined yes/no for settings such as high contrast, used only to avoid
        wrongly flagging people who use assistive technology. Such a setting can hint at a disability, which is why it
        is a single value and named in the consent text.</li>
    <li><strong>No automatic decisions:</strong> flags are for a person to review, not a reason to exclude or withhold
        payment on their own.</li>
    <li><strong>First-party only:</strong> no outside service or script is involved; the records are stored with the
        answers on your formr server. Delete them once data quality has been assessed (the consent text names the
        period).</li>
</ul>
<p>What it cannot catch:</p>
<ul>
    <li>People who pretend to be someone else, such as bought accounts or fake countries of residence. That is a
        separate problem for the participant panel.</li>
    <li>An agent built with care: inside a person's real browser, imitating human typing and told to ignore
        instructions on web pages.</li>
    <li>Disguised browsers such as CloakBrowser or Camoufox with human-like input, when your survey has no question
        with free text.</li>
    <li>A person who pastes questions into ChatGPT shows up only as copy and paste counts and tab switches. The hidden
        note may also be copied along with the page text.</li>
    <li>Screen-reader users hear the hidden note on every page, followed by the request to skip it.</li>
</ul>
<p>
    <strong>Phones and tablets: read their flags with extra care.</strong> formr recognises a phone or tablet, also an
    iPad or an Android tablet that asks for the desktop version of a page and so claims to be a computer. On these,
    the checks that need a physical keyboard, a mouse or a window are switched off (key-hold time, Shift, umlauts, DRM
    and window size). We ran the items on real devices (an iPhone, an iPad, an Android phone and an Android tablet)
    and in Firefox and in Safari's engine on a desktop: the record is written and stored, the real browser is
    recognised, and nothing wrongly says that the browser lies about itself. What these runs could not show is how a
    person's typing and tapping look there, because test devices are driven by a program and their on-screen keyboard
    stays down. Phone keyboards with word suggestions or swipe typing may still trigger typing flags.
</p>
<p>
    <strong>No IP or network checks.</strong> Bot-protection services such as Cloudflare Turnstile also check the
    participant's IP address and network. They catch traffic from data centres, VPNs and known bot networks. formr's
    checks run only inside the page and store no IP address, so they miss this signal. Recruitment platforms such as
    Prolific run some network checks themselves. Adding such a service would bring in a third party that needs its own
    consent.
</p>
<p>
    <strong>False alarms to expect.</strong> "Claims Google Chrome but has no Widevine" also fires for the rare person
    who switched off protected content in Chrome, and on some managed work computers. Browser autofill, dictation and
    writing tools can put text into a field without key presses. This is why one flag alone means little.
</p>

<h3 id="dq-botcheck">How this relates to <code>bot_check</code></h3>
<p>
    <code>bot_check</code> (v2 surveys) is a visible "verify you are human" box. It makes the participant's device do a
    few seconds of computing before the page can be sent, which makes mass submissions by simple scripts expensive. It
    is friction, not detection: an AI agent that drives a real browser clicks the box and waits like anyone else, and
    nothing about it is recorded. The items on this page are the opposite: they block nobody and record what happened.
    The two can be used together.
</p>
<p>
    <strong>For server operators:</strong> the DRM check needs the page to be allowed to ask about protected media. A
    <code>Permissions-Policy</code> response header that switches off <code>encrypted-media</code> would make every
    Chrome look like an automated one; formr and its Docker setup send no such header, and if one is ever added the
    check is skipped (the record then says <code>drm: "blocked"</code>).
</p>
