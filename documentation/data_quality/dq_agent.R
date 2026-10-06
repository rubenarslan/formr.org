# Reading the records of formr's `data_quality` items (one JSON record per page), the answers to
# `agent_probe` items and the randomised-response question. Needs the jsonlite package.
# Admin documentation: /admin/documentation/data-quality on your formr instance.
#
# dq_parse(x): character vector of JSON records (one per page) -> data.frame, one row per page
# dq_flags(d, probe, srv_ua): data.frame of indicators summed over pages, with a flag column
# dq_rrt(yes): share of agents and AI-assisted responses from the randomised-response question
dq_parse <- function(x) {
  x <- x[!is.na(x) & nzchar(x)]
  rows <- lapply(x, function(s) {
    j <- tryCatch(jsonlite::fromJSON(s), error = function(e) NULL)
    if (is.null(j)) return(NULL)
    as.data.frame(lapply(j, function(v) if (length(v) == 0) NA else v), stringsAsFactors = FALSE)
  })
  rows <- Filter(Negate(is.null), rows)
  if (!length(rows)) return(data.frame())
  cols <- unique(unlist(lapply(rows, names)))
  do.call(rbind, lapply(rows, function(r) { r[setdiff(cols, names(r))] <- NA; r[cols] }))
}

# Adler-32 checksum (hex) of a string, same as `uah` in the JS record
dq_adler32 <- function(x) {
  x <- utils::tail(x, 1)
  if (!length(x) || is.na(x)) return(NA_character_)
  a <- 1; b <- 0
  for (ch in utf8ToInt(x)) { a <- (a + ch) %% 65521; b <- (b + a) %% 65521 }
  if (b == 0) sprintf("%x", as.integer(a)) else sprintf("%x%04x", as.integer(b), as.integer(a)) # = JS (b*65536+a).toString(16)
}

# randomised response (disjunction): "Yes if at least one is true: identical twin / AI agent / used a bot, script or AI".
# Everyone in the sensitive group (agent or AI help, share pi) says Yes whether or not they are a twin, so a twin who also
# used AI is counted once; everyone else says Yes only if a twin (rate p). P(yes) = pi + (1 - pi) * p, hence
# pi_hat = (lambda - p) / (1 - p) (not lambda - p). Only assumption: the twin rate among people without AI help is p.
# yes: logical/0-1 vector (TRUE = "Yes"); p: probability of the innocuous statement. Default: share of people who are
# identical twins (~4 monozygotic twin pairs per 1,000 births -> ~0.8% of people); calibrate on verified humans if possible,
# since self-reported zygosity is imperfect and twins' share on a given platform is unknown.
dq_rrt <- function(yes, p = 0.008) {
  yes <- as.logical(yes[!is.na(yes)]); n <- length(yes); lambda <- mean(yes)
  est <- (lambda - p) / (1 - p); se <- sqrt(lambda * (1 - lambda) / n) / (1 - p)
  c(n = n, yes_rate = lambda, ai_share = est, se = se, lower = est - 1.96 * se, upper = est + 1.96 * se)
}

# srv_ua: the User-Agent header stored by a formr `browser` item (optional)
# probe: what was typed into the agent_probe fields (people never see them, so any text is a flag)
dq_flags <- function(d, probe = NA, srv_ua = NA) {
  if (!nrow(d)) return(data.frame(indicator = "no dq record", value = NA, flag = NA))
  s <- function(k) sum(as.numeric(d[[k]]), na.rm = TRUE)
  mx <- function(k) max(as.numeric(d[[k]]), na.rm = TRUE)
  txt <- function(k) paste(unique(unlist(strsplit(stats::na.omit(as.character(d[[k]])), ","))), collapse = ",")
  share <- function(a, b) if (b > 0) round(a / b, 2) else NA
  pd <- s("pd")
  ins <- s("ins_nokey_ch"); tch <- s("txt_ch")
  kcv <- suppressWarnings(min(as.numeric(d$kcv[as.numeric(d$kcv) >= 0]), na.rm = TRUE))
  kwb <- suppressWarnings(min(as.numeric(d$kwb[as.numeric(d$kwb) >= 0]), na.rm = TRUE))
  last <- function(x) { x <- utils::tail(x, 1); if (!length(x) || is.na(x)) "" else as.character(x) }
  js_uah <- unique(stats::na.omit(as.character(d$uah)))
  ua_diff <- nzchar(last(srv_ua)) && length(js_uah) > 0 && !(dq_adler32(last(srv_ua)) %in% js_uah)
  probe_txt <- if (length(probe) && !is.na(probe[1]) && nzchar(probe[1])) probe[1] else ""
  rows <- list(
    # automation environment
    c("navigator.webdriver true", mx("wd"), mx("wd") > 0),
    c("webdriver flag tampered", mx("wdt"), mx("wdt") > 0),
    c("headless UA / no window.chrome / no plugins / no languages / permission mismatch",
      paste(mx("hl"), mx("nochr"), mx("plg0"), mx("lang0"), mx("perm"), sep = "/"),
      mx("hl") + mx("nochr") + mx("plg0") + mx("lang0") + mx("perm") > 0),
    c("no browser window when first interacting", mx("ow0"), mx("ow0") > 0),
    c("window larger than screen (spoofed screen)", mx("winscr"), mx("winscr") > 0),
    c("screen size = viewport (emulated device)", mx("scrvp"), NA),
    c("software WebGL renderer", mx("swgl"), mx("swgl") > 0),
    c("automation globals", txt("glob"), nzchar(txt("glob"))),
    c("agent/AI-assistant names in injected DOM", txt("ext"), nzchar(txt("ext"))),
    c("extension resources injected into page", s("ext_res"), NA),
    c("accessibility setting active (forced colours, high contrast, reduced motion)", mx("a11y"), NA),
    c("AI browser brand", txt("aib"), nzchar(txt("aib"))),
    c("agent_probe field filled", probe_txt, nzchar(probe_txt)),
    c("user-agent incoherent (UA vs platform vs client hints)", txt("uac"), nzchar(txt("uac"))),
    c("User-Agent header differs from navigator.userAgent", if (nzchar(last(srv_ua))) as.integer(ua_diff) else NA, ua_diff),
    c("real JS engine differs from the engine the UA claims (real vs claimed)", paste(txt("eng"), txt("eng_ua"), sep = " vs "), mx("eng_mm") > 0),
    c("UA Chrome version outside the range its JS features allow", mx("ver_mm"), mx("ver_mm") > 0),
    c("claims Google Chrome but has no Widevine (automation launch or non-Google build)", mx("chr_nowv"), mx("chr_nowv") > 0),
    c("DRM key systems available (wv Widevine, pr PlayReady, fp FairPlay; na not checked; blocked by a Permissions-Policy)", txt("drm"), NA),
    # synthetic input
    c("script-made events that are not the page's own", s("untr"), s("untr") > 0),
    c("answers changed without click/tap/key", s("prog"), s("prog") > 0),
    c("text answers mostly not typed or pasted (fields)", s("fld_nokey"), s("fld_nokey") > 0),
    c("text inserted without key presses (events / chars / other inserts)", paste(s("ins_nokey"), ins, s("ins_other"), sep = " / "), ins >= 20 && ins > 0.5 * tch),
    c("share of text typed via keys (keys / chars in fields)", share(s("keys"), tch), tch >= 20 && s("keys") < 0.5 * tch),
    c("key intervals < 12 ms (of intervals)", paste(s("kfast"), s("kn"), sep = " / "), s("kn") >= 10 && s("kfast") > 0.3 * s("kn")),
    c("key dwell < 8 ms", s("kdwf"), s("kn") >= 10 && s("kdwf") > 0.5 * s("keys")),
    c("typing rhythm CV within bursts (min over pages; ~0 = metronome)", if (is.finite(kcv)) kcv else NA, is.finite(kcv) && kcv < 0.15),
    c("pause before new word / within word (median ratio within bursts; info)", if (is.finite(kwb)) kwb else NA, NA),
    c("long pauses (0.5-5 s) at word starts: share / expected share / long pauses",
      paste(share(s("klp_ws"), s("klp")), share(s("kws"), s("kwc")), s("klp"), sep = " / "),
      s("klp") >= 8 && s("kwc") > 0 && s("klp_ws") / s("klp") < 1.3 * s("kws") / s("kwc")),
    c("corrections (Backspace/Delete) / typed keys", paste(s("bksp"), s("keys"), sep = " / "), s("keys") >= 300 && s("bksp") == 0),
    c("capitals: Shift not held / no Shift key press / capitals", paste(s("kcap_ns"), s("kcap_nosk"), s("kcap"), sep = " / "), s("kcap_ns") + s("kcap_nosk") > 0),
    c("non-ASCII letters inserted without their key", s("ins_na"), s("ins_na") > 0),
    c("key presses without key code", s("kcode0"), s("kcode0") >= 3),
    c("mouse presses / at exact centre", paste(pd, s("pd_ctr"), sep = " / "), pd >= 3 && s("pd_ctr") > 0.5 * pd),
    c("mouse presses with <= 2 moves in prior second", s("pd_fewmv"), pd >= 3 && s("pd_fewmv") > 0.8 * pd),
    c("mouse presses with screen == client coordinates", s("pd_scr"), pd >= 1 && s("pd_scr") == pd),
    c("clicks released within 25 ms / clicks (info: trackpad tap-to-click does this too)", paste(s("pd_hold0"), s("pu"), sep = " / "), NA),
    c("clicks within 200 ms of target scrolling into view", s("pd_newview"), pd >= 2 && s("pd_newview") > 0.3 * pd),
    c("pointer moves with coalesced samples (share; device-dependent)", if (mx("mv_coal") < 0) "unsupported" else share(s("mv_coal"), s("mv")), NA),
    c("pointer moves without movementX/Y", s("mv_mz"), s("mv") >= 10 && s("mv_mz") > 0.5 * s("mv")),
    c("touches with radius <= 1 px / touches", paste(s("tap_r1"), s("tap"), sep = " / "), s("tap") >= 3 && s("tap_r1") == s("tap")),
    c("pointer moves / jumps > 250 px", paste(s("mv"), s("mv_jump"), sep = " / "), s("mv") > 0 && s("mv_jump") > 0.3 * s("mv")),
    c("scrolls without wheel/touch/key", paste(s("scr_nogest"), s("scr"), sep = " / "), s("scr") >= 3 && s("scr_nogest") > 0.8 * s("scr")),
    c("focus moved without click/key", s("foc_nogest"), s("foc_nogest") > 1),
    c("focus() called by code that is not the page's (run in the page by a tool)", s("foc_prog"), s("foc_prog") > 0),
    c("clicks without a pointer (not Enter/Space or label)", s("clk_nopt"), s("clk_nopt") > 0),
    c("actions while page hidden / unfocused", paste(s("act_hid"), s("act_unf"), sep = " / "), s("act_hid") + s("act_unf") > 0),
    c("info bar / side panel opened", paste(s("rsz_bar"), s("rsz_side"), sep = " / "), s("rsz_bar") + s("rsz_side") > 0),
    # carelessness / outside help
    c("seconds on pages", round(s("t") / 1000), NA),
    c("tab hidden: times / seconds", paste(s("hid_n"), round(s("hid_ms") / 1000), sep = " / "), s("hid_n") > 0),
    c("window unfocused: times / seconds", paste(s("blur_n"), round(s("blur_ms") / 1000), sep = " / "), s("blur_n") > 0),
    c("copy (of question text) / chars", paste0(s("copy"), " (", s("copy_q"), ") / ", s("copy_ch")), s("copy_q") > 0),
    c("paste / chars (share of text in fields)", paste0(s("paste"), " / ", s("paste_ch"), " (", share(s("paste_ch"), tch), ")"), s("paste") > 0),
    c("browser: family / mobile / pointer", paste(d$fam[1], d$mob[1], d$ptr[1], sep = " / "), NA)
  )
  out <- as.data.frame(do.call(rbind, rows), stringsAsFactors = FALSE)
  names(out) <- c("indicator", "value", "flag")
  out$flag <- ifelse(is.na(out$flag), "", ifelse(as.logical(out$flag), "⚑", ""))
  out
}

dq_md <- function(f) {
  paste(c("| Indicator | Value | Flag |", "|---|---|---|",
          paste0("| ", f$indicator, " | ", ifelse(is.na(f$value) | f$value %in% c("", "NA"), "–", paste0("`", f$value, "`")), " | ", f$flag, " |")), collapse = "\n")
}
