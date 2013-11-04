# formr survey framework

**chain simple forms into longer runs using the power of R to generate pretty feedback and complex designs**

This is a framework that allows you to create simple and complex studies using spreadsheets with items for the surveys and "runs" for chaining together various modules.

## Runs and their modules

Simple surveys can be turned into elaborate studies by using "runs".

Runs allow you to chain various modules and thus:

* manage access and eligibility for a study
* use different pathways for different users using branches
* send e-mail invites and reminders
* implement delays/pauses
* add external modules
* loop surveys and thus enable diaries, experience-sampling studies using branches
* give feedback based on user input, using [knitr](http://yihui.name/knitr/) and [Markdown](http://daringfireball.net/projects/markdown/) through [OpenCPU](https://public.opencpu.org/pages/)'s [R](http://www.r-project.org/) API

Using branching and pauses, the following designs should be possible:

* simple one-shot surveys
* surveys with eligibility rules (using branch conditions, e.g. `demographics.age > 30`)
* diary studies (using branch conditions `nrow(diary) < 14` -> start diary again the next day -> if finished continue)
* longitudinal studies using pauses (ie. wait 2 months after last participation). The items of wave 2 need not be finalised at wave 1.

### Pause
This simple component allows you to delay the continuation of the run until a certain date, time of day or to wait relative to a date that a user specified (such as her graduation date or the last time he cut his nails). See the **OpenCPU + R + Knitr + Markdown** section to find out how to personalise the text shown while waiting.

### Branch
Branches are components that allow to evaluate R conditions on a user's data. Depending on whether the result evaluates to true/1 or false/0, you can go to different positions in the run - these can be later or earlier in the run (the latter creates loops, for e.g. diaries, training interventions, etc.).

### TimeBranch
These components are the bastard children of a Pause + Branch: If the user accesses the run within the specified time frame (like a Pause), the run jumps to one position in the run (like a Branch). If she doesn't, the run progresses to a different position in the run (e.g. to a reminder email). This component is useful, if you need to set up a period during which a survey is accessible or if you want to automatically send reminders after some time elapsed. 
See the **OpenCPU + R + Knitr + Markdown** section to find out how to customise the text shown while waiting.

### Email
Using an SMTP gateway that you can set up in the admin area, you can send emails to your users. Using the tag `{{login_link}}`, you can send users a personalised link to the run, you can also use `{{login_code}}` to use the session code to create custom links, e.g. for inviting peers to rate this person (informants). See the **OpenCPU + R + Knitr + Markdown** section to find out how to personalise email text.

### External link
These are simple external links - use them to send users to other, specialised data collection modules, such as a social network generator. If you insert the placeholder `%s`, it will be replaced by the users run_session code, allowing you to link data later. You can either choose to "finish" this component *before* the user is redirected (the simple way) or enable your external module to call our API to close it only, when the external component is finished (the proper way). 

### Page
Simple text pages. See the **OpenCPU + R + Knitr + Markdown** section to find out how to generate personalised feedback, including plots.

### Survey
Surveys are series of questions that are created using simple spreadsheets/**item tables** (ie. Excel, OpenOffice, etc.: *.xls, *.ods, *.xlsx, *.csv etc.).

You can add the same survey to a run several times or even loop them using branches.  
You can also use the same survey across different runs. For example this would allow you to ask respondents for their demographic information only once. You'd do this by using a Branch with the condition `nrow(demographics) > 0` and skipping over the demographics survey, if true.

Survey names may only contain the characters `a-zA-Z0-9_` and need to start with a letter.

#### Item table criteria

* The format must be one of .csv, .xls, .xlsx, .ods (OpenOffice), .xml, .txt
* You can use two sheets
* The first sheet should have the name **survey**, if no such sheet exists, we use the first one.
	* The first line has to contain the column names you used. Name and type should be leftmost.
	* The following column names are used. You can add others, they will be ignored. 
		* **_name_** (mandatory). This can only contain `a-zA-Z0-9_` and needs to start with a letter.
		* **_type_** (mandatory). See below.
		* **label**. You can use Knitr, [Markdown](http://daringfireball.net/projects/markdown/) and HTML in the question texts. You can also use [Font Awesome](http://fontawesome.io) icons.
		* **skipif** You can refer to the same survey here `variable_name == 2` or you can reference other surveys using `survey_name$variable_name == 2` (evaluated via OpenCPU in R).
		* **optional** You can make an item optional (most items are mandatory by default), by using the `*` character in the optional-column. Items optional by default (`check`, `btncheck`, `mmc`) can be made mandatory by using the the `!` character in the optional-column.
		* **choice1, choice2, ..., choice14** (you can use these columns to quickly add choices. If you use many choices repeatedly or need more than 14 choices, it makes more sense to put them on the choices sheet)
* The second, optional sheet should have the name **choices**, if no such sheet exists, we use the second one.
	* The following column names are used (in order).
		* **list name** - you can reference the list of choices using this name on the 'survey' sheet.
		* **name** - the text for the choice that will be stored. This can only contain `a-zA-Z0-9_`.
		* **label** - the text for the choice that is displayed (if you leave this column out or empty, we'll use the name text)

#### Items
Surveys support the following item types. HTML5 form elements and validation are used and polyfilled where necessary using the [Webshims lib](http://afarkas.github.io/webshim/demos/index.html).

* `instruction` display text. instructions are displayed at least once and disappear only when there are no unanswered items left behind them (so putting an instruction directly before another ensures it will be displayed only once)
* `submit` display a submit button. No items are displayed after the submit button, until all of the ones preceding it have been answered. This is useful for pagination and to ensure that answers required for `skipif` or for dynamically generating item text have been given. 
* multiple choice family
	* `mc` multipe choice (radio buttons), you can choose only one. Choices are (currently) defined using the choice1-12 columns
	* `mmc` multiple multiple choice (check boxes), you can choose several. Choices defined as above
	* `check` a single check box for confirmation of a statement.
	* `btnradio` like `mc` but radio buttons are styled as buttons containing the choice text
	* `btncheckbox` like `mmc` and `btnradio`
	* `btncheck` like `check` and `btnradio`
	* `btnrating min,max,step` This shows choice1 to the left, choice2 to the right and a series of buttons as defined by `min,max,step` in between. Defaults to `1,5,1`
	* `sex` shorthand for `btnradio` with the ♂, ♀ symbols as choices
	* `select` dropdowns, you can choose only one
	* `mselect` dropdowns, choose many
	* `select_add maxType` like `select`, allows users to choose an option not given. Uses [Select2](http://ivaynberg.github.io/select2/). `maxType` can be used to set an upper limit on the length of the user-added option. Defaults to 255.
	* `mselect_add maxType,maxChoose` like `mselect` and `select_add`, allows users to add options not given. `maxChoose` can be used to place an upper limit on the number of chooseable options.
	* `mc_heading` To get a tabular look, assign a constant width to the choices (with classes), give the heading the same choices as the `mc`s, and give the following `mc`s (or `mmc`s)  the same classes + hide_label. 
* `range min,max,step` these are sliders. The numeric value chosen is not displayed. Text to be shown to the left and right of the slider can be defined using the choice1 and choice2 fields. Defaults are `1,100,1`.
* `range_list min,max,step` like `range` but the individual steps are visually indicated using ticks and the chosen number is shown to the right. 
* `color` allows you to pick a color, using the OS color picker (or one polyfilled by Webshims)
* simple input family
	* `text` allows you to enter a text in a single-line input field. Adding a number `text 100` defines the maximum number of characters that may be entered.
	* `textarea` displays a multi-line input field
	* `letters` like `text`, allows only letters, not numbers.
	* `number min,max,step` for numbers. `step` defaults to `1`, using `any` will allow any decimals.
	* `email` for email addresses
	* `url`, `cc` (credit card number), `tel` are allowed too, but webshims doesn't polyfill them yet and most major browsers don't support them
* datetime family
	* `time` for time. a small clock is prepended to the input
	* `date` for a date
	* `datetime` for a date & time
	* `month`, `yearmonth` etc. should work too, but don't in most browsers
* `geolocation` shows a text input and a geolocation arrow. If the user allows it, the geolocation object is stored as a JSON string, if not the user can enter a text string.
* "server" family
	* `ip` saves the value of the PHP superglobal `$_SERVER['REMOTE_ADDR']` ie. the user's IP.
	* `referrer` saves the last outside referrer (if any), ie. how the user got to the site
	* `server var` saves the `$_SERVER` value with the index given by `var`. Can be used to store one of `'HTTP_USER_AGENT',	'HTTP_ACCEPT',	'HTTP_ACCEPT_CHARSET',	'HTTP_ACCEPT_ENCODING',	'HTTP_ACCEPT_LANGUAGE',	'HTTP_CONNECTION',	'HTTP_HOST',	'QUERY_STRING',	'REQUEST_TIME',	'REQUEST_TIME_FLOAT'`

#### skipif

You can make item display contingent on simple and complex conditions like `(survey1$married == 1) | (survey2$in_relationship == 1 & survey2$cohabit == 1)`.

## OpenCPU + R + Knitr + Markdown
[OpenCPU](https://public.opencpu.org/pages/) is a way to safely use complex [R](http://www.r-project.org/) expressions on the web. We use it for all kinds of stuff.

In pauses, emails and pages you can display text to the user. This text is easily formatted using [Markdown](http://daringfireball.net/projects/markdown/) a simple syntax that formats text nicely if you simply write like you would write a plain text email. Markdown can be freely mixed with HTML, so you can e.g. insert icons from the [Font Awesome](http://fontawesome.io) library using `<i class="icon-smile"></i>`.

If you use knitr syntax, where Markdown can be used, the text will not just be parsed as Markdown (which is mostly static, unless you use Javascript), but also be parsed (anew each time) by [knitr](http://yihui.name/knitr/). Knitr allows for mixing R syntax chunks and Markdown.  
[R](http://www.r-project.org/) is a popular open-source statistical programming language, that you can use via [OpenCPU](https://public.opencpu.org/pages/), a RESTful interface to the language that deals with the problem that R was not meant to be used as part of web apps and is insecure. R data frames with the same names as the surveys they derive from will be available in this knitr call, they contain all data that the current user has filled out so far.  
Combined with norm data etc. you can tailor feedback to the user's input, e.g. show where the user lies on the bell curve etc.

R expressions are also evaluated in many other places, e.g. in the simplest case to find out which address an email should be sent to, or what date a pause should be relative to. More complex logic will probably take place in branches, where you might even want to do some basic data analysis

### Example
This may sound complicated at first, but should be really simple to use, while still providing most of the functionality available to R users (e.g. pretty [ggplot2](http://ggplot2.org/) plots).

Using this syntax will yield the following results (assuming that the surveys "demographics" and "mood_diary" exist and contain the appropriate data)

	Hi `r demographics$first_name`!
	
	This is a graph showing how **your mood** fluctuated across the 50 days that you filled out our diary.
	
	### Graph
	```{r mood.plot}
	library(ggplot2)
	mood_diary$mood <- rowSums(mood_diary[,c('mood1','mood2','mood3')])
	qplot(Day, mood, data = mood_diary) + geom_smooth() + scale_y_continuous("Your mood") + theme_bw()
	```
	
#### This will be displayed to the user

Hi Petunia!

This is a graph showing how **your mood** fluctuated across the 50 days that you filled out our diary.

### Graph

(Image)


## Installation

If you want to test formr, you can simply clone this repository. 
You need to rename the "config_default" directory to "config" and supply your database settings. You will also have to make the folders "tmp" and "backups" writeable. 
If you get internal server errors, these most likely stem from .htaccess files or the aforementioned folder permissions.

## Problems and plans
### Security

#### API
* you can create run access tokens using the API
* you can end "External" units using the API

## Credit

### Author:
Ruben C. Arslan (2013)

### Based on work, ideas, and feedback by:
Linus Neumann, Jaap J. A. Denissen, Karsten Gebbert, Julia Zimmermann, Sarah Lennartz, Isabelle Habedank, Jörg Basedow

#### Funded by 

#### Friedrich-Schiller-Universität Jena
* Julia Zimmermann
* Franz J. Neyer

#### Humboldt Universität zu Berlin
Jaap J. A. Denissen