<h3 id="dq-codebook">Codebook: what a record holds</h3>
<p>
    One record per page, as JSON with short field names. <code>dq_flags()</code> in the R helpers turns the records
    into a readable table, so you rarely need this list. <code>v</code> is the format version (2; records written by
    the earlier script pasted into a run's settings say 1 and can be read with the same helpers).
</p>
<div class="table-responsive">
<table class="table table-bordered table-condensed">
    <thead><tr><th>Field</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><th colspan="2">Device and browser (read once)</th></tr>
        <tr><td><code>wd</code>, <code>wdt</code></td><td>The browser reports being remote-controlled; that report was tampered with</td></tr>
        <tr><td><code>hl</code>, <code>nochr</code>, <code>plg0</code>, <code>lang0</code>, <code>perm</code>, <code>swgl</code></td>
            <td>Signs of a browser without a window: headless identification, Chrome without its usual objects, no plugins, no languages, a contradictory notification permission, graphics drawn in software (<code>swgl</code> is -1 where the browser has no 3D graphics to ask about)</td></tr>
        <tr><td><code>glob</code>, <code>ext</code>, <code>ext_res</code>, <code>aib</code></td>
            <td>Names of automation tools found in the page (from a fixed list); names of agent or AI-assistant tools found in elements added to the page (fixed list); number of files a browser extension added to the page; AI-browser name</td></tr>
        <tr><td><code>fam</code>, <code>mob</code>, <code>ptr</code></td><td>Browser family; phone or tablet (also one that claims to be a desktop computer: its pointer is a finger); mouse or touchscreen</td></tr>
        <tr><td><code>fx0</code>, <code>vis0</code></td><td>Whether the window had focus and was visible at the start</td></tr>
        <tr><td><code>uac</code>, <code>uah</code></td><td>Which parts of the browser's identification contradict each other; a checksum of that identification, compared in R with the <code>browser</code> row</td></tr>
        <tr><td><code>eng</code>, <code>eng_ua</code>, <code>eng_mm</code>, <code>ver_mm</code></td>
            <td>The real browser engine, the engine claimed, whether they differ, whether the claimed Chrome version is impossible given what the browser can do</td></tr>
        <tr><td><code>drm</code>, <code>chr_nowv</code></td>
            <td>Which systems for protected video exist (<code>wv</code>, <code>pr</code>, <code>fp</code>; <code>na</code> not checked: phone, tablet, Firefox or Brave; <code>blocked</code> by the server's settings); claims to be Google Chrome but has no Widevine</td></tr>
        <tr><td><code>a11y</code></td><td>One yes/no for accessibility settings (forced colours, high contrast, reduced motion)</td></tr>
        <tr><td><code>ow0</code>, <code>scrvp</code>, <code>winscr</code></td><td>At the first action: no browser window, the screen is exactly as large as the page, the window is larger than the screen</td></tr>

        <tr><th colspan="2">Time and leaving the page</th></tr>
        <tr><td><code>ld</code>, <code>t</code>, <code>t1</code></td><td>Number of times the page was shown, milliseconds on it, milliseconds until the first action</td></tr>
        <tr><td><code>hid_n</code>, <code>hid_ms</code>, <code>blur_n</code>, <code>blur_ms</code></td><td>How often and how long the tab was hidden; how often and how long the window was not in front</td></tr>
        <tr><td><code>copy</code>, <code>copy_q</code>, <code>copy_ch</code>, <code>cut</code>, <code>paste</code>, <code>paste_ch</code>, <code>ctx</code></td>
            <td>Copies (of which: question text), characters copied, cuts, pastes, characters pasted, right-clicks. Never the content</td></tr>

        <tr><th colspan="2">Keyboard and text</th></tr>
        <tr><td><code>keys</code>, <code>bksp</code>, <code>txt_ch</code></td><td>Key presses, corrections (Backspace, Delete), characters written into the page's text fields (not what a field already held when the page was shown, such as a stored answer or a default, and not what the page itself fills in, such as a location or a choice from a list)</td></tr>
        <tr><td><code>kn</code>, <code>kfast</code>, <code>kdwf</code>, <code>kcv</code></td>
            <td>Gaps between keys while typing, gaps under 12 ms, keys held under 8 ms (desktop), regularity of the gaps (near 0 = like a metronome; -1 = too little typed)</td></tr>
        <tr><td><code>kwb</code>, <code>kwc</code>, <code>kws</code>, <code>klp</code>, <code>klp_ws</code></td>
            <td>Where pauses fall: people pause before a new word (a word also starts on a new line and in another field). Pause before a word against within a word; characters typed, of which first letters of words; long pauses (0.5 to 5 s), of which before a word</td></tr>
        <tr><td><code>kcap</code>, <code>kcap_ns</code>, <code>kcap_nosk</code>, <code>kcode0</code>, <code>ins_na</code></td>
            <td>Capital letters; capitals without Shift held; capitals with no Shift press before them; key presses without a key code; letters such as ä or ß that appeared without their key (all desktop only)</td></tr>
        <tr><td><code>fld_nokey</code>, <code>ins_nokey</code>, <code>ins_nokey_ch</code>, <code>ins_repl</code>, <code>ins_other</code></td>
            <td>Text answers with 10 or more characters written on the page that were mostly neither typed nor pasted; text inserted without a key press (times, characters); autocorrect replacements; other inserts</td></tr>

        <tr><th colspan="2">Mouse, touch, scrolling, focus</th></tr>
        <tr><td><code>mv</code>, <code>mv_jump</code>, <code>mv_coal</code>, <code>mv_mz</code></td>
            <td>Mouse movements; jumps of more than 250 pixels; movements with several samples (-1 = the browser does not say); movements whose position changed without any reported motion</td></tr>
        <tr><td><code>pd</code>, <code>pd_ctr</code>, <code>pd_fewmv</code>, <code>pd_scr</code>, <code>pd_newview</code></td>
            <td>Mouse presses; exactly in the centre of what was clicked; with at most 2 movements in the second before; with identical screen and page coordinates; within 0.2 s of the target scrolling into view</td></tr>
        <tr><td><code>pu</code>, <code>pd_hold0</code></td><td>Mouse releases; clicks shorter than 25 ms (information only: a trackpad tap does this too)</td></tr>
        <tr><td><code>tap</code>, <code>tap_r1</code></td><td>Touches; touches with a contact area of one pixel or less</td></tr>
        <tr><td><code>scr</code>, <code>scr_nogest</code>, <code>foc_nogest</code></td><td>Scroll steps; without wheel, touch or key (the page's own scrolling to a question is not counted); focus moved into a field without click or key</td></tr>
        <tr><td><code>foc_prog</code>, <code>clk_nopt</code></td>
            <td>A field was focused by code that is not the page's own (a tool running code inside the page); clicks made without a pointer and without a key. Not counted: the click a browser makes itself for Enter, Space and the arrow keys, and the one it passes on from a label to its field (Safari on the iPhone and iPad we tested, iOS 17 and 16, does not report this one)</td></tr>
        <tr><td><code>rsz</code>, <code>rsz_side</code>, <code>rsz_bar</code></td><td>Window resizes; a side panel opened; an information bar opened (the last two on desktop only)</td></tr>

        <tr><th colspan="2">Input that did not come from a person's hands</th></tr>
        <tr><td><code>untr</code>, <code>prog</code></td><td>Events made by a script that is not the page's own; items that were answered without ever being clicked, tapped or typed in</td></tr>
        <tr><td><code>act_hid</code>, <code>act_unf</code>, <code>dom_top</code></td><td>Actions while the page was hidden or the window not in front; elements added at the page's top level</td></tr>
    </tbody>
</table>
</div>
