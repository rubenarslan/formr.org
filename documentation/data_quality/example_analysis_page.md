## Thank you!

**This page belongs to the example.** A real study never shows participants what the checks found: that would teach automated tools what to avoid. It is here so that you can see what was recorded about your own visit, and how to read it with the `formr` R package (1.3.0 or later).

```{r echo=FALSE, results='asis'}
# formr hands a run's R code only the columns it names, so the names and
# patterns are written out here although they are the functions' defaults.
mine <- tail(STUDY, 1)   # this participant's row of the study
flags <- dq_flags_by_session(mine,
  pages = "^dq_p[0-9]+$", probes = "^dq_agent_p[0-9]+$",
  srv_ua = "dq_srv_ua", sig_agent = "dq_sig_agent",
  ch_platform = "dq_ch_platform", ch_mobile = "dq_ch_mobile",
  flagged_only = FALSE)
flagged <- flags[flags$flag, c("group", "indicator", "value")]
cat("### ", nrow(flagged), " of ", nrow(flags), " indicators are flagged for your visit\n\n", sep = "")
if (nrow(flagged)) {
  print(knitr::kable(flagged, row.names = FALSE))
} else {
  cat("Nothing on the two pages looked like a program at work or like careless answering.\n")
}
hidden_field <- c(mine$dq_agent_p1, mine$dq_agent_p2)
hidden_field <- hidden_field[!is.na(hidden_field) & nzchar(hidden_field)]
three_statements <- c("1" = "Yes", "2" = "No")[as.character(mine$rrt_ai)]
```

**How to read it.** No single flag proves anything. *environment* is about the browser and the request (does it say it is remote-controlled, is it the browser it claims to be). *input* is about how clicks and text were made (clicks at the exact centre of a button, text without key presses): several of these together mean a program made the input. *attention* is context about a person (time away from the page, copying and pasting).

**The field you could not see:** `r if (length(hidden_field)) paste0("it holds \"", paste(hidden_field, collapse = " | "), "\". People cannot type there, so this alone gives an automated tool away.") else "it is empty, as it is for every person."`

**Your answer to the three statements:** `r three_statements`. On its own this does not show which statement applies; across a sample it gives the share of responses from AI agents or with AI help.

### The same for a whole sample

Download the results of the survey `STUDY` (or fetch them with the package) and run:

```r
library(formr)                               # 1.3.0 or later
results <- read.csv("STUDY.csv")
flags <- dq_flags_by_session(results)        # one row per session and flagged indicator
table(flags$session, flags$group)            # how many flags of which kind per session
dq_rrt(results$rrt_ai == 1)                  # share of AI agents and AI-assisted responses
```

<details><summary>All indicators for your visit</summary>

```{r echo=FALSE, results='asis'}
print(knitr::kable(flags[, c("group", "indicator", "value", "flag")], row.names = FALSE))
```

</details>

<details><summary>The records as they are stored</summary>

Part 1: `r mine$dq_p1`

Part 2: `r mine$dq_p2`

</details>
