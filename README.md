# Psytests survey framework

## Runs and their modules

Simple surveys can be made into elaborate studies by using "runs".

Runs allow you to chain various modules and thus:

* manage access and eligibility for a study
* use different pathways for different users using branches
* send e-mail invites and reminders
* implement delays/pauses
* add external modules
* loop surveys and thus enable diaries, experience-sampling studies using branches
* give feedback based on user input, using [knitr](http://yihui.name/knitr/) and [Markdown](http://daringfireball.net/projects/markdown/) through [R](http://www.r-project.org/) and [OpenCPU](https://public.opencpu.org/pages/)

Using branching and pauses, the following designs should be possible:

* simple one-shot surveys
* surveys with eligibility rules (using branch conditions, e.g. `demographics.age > 30`)
* diary studies (using branch conditions `COUNT(diary.session_id) < 14` -> start diary again the next day -> if finished continue)
* longitudinal studies using pauses (ie. wait 2 months after last participation). The items of wave 2 need not be clear at wave 1!

### Pause
This simple component allows you to delay the continuation of the run until a certain date, time of day or to wait relative to a date that a user specified (such as her graduation date or the last time he cut his nails). See the [OpenCPU + R + Knitr + Markdown](#opencpu) section to find out how to personalise the text shown while waiting.

### Branch
Branches are components that allow to execute readonly SQL commands on all surveys you created. Depending on whether the results evaluates to true/1 or false/0, you can go to different positions in the run - these can be later in the run or earlier in the run (thus creating loops).

### TimeBranch
These components are the bastard children of a Pause + Branch: If the user accesses the run within the specified time frame (like a Pause), the run jumps to one position in the run (like a Branch). If she doesn't, the run progresses to a different position in the run (e.g. to a reminder email). This component is useful, if you need to set up a period during which a survey is accessible or if you want to send reminders automatically after some time elapsed. 
See the [OpenCPU + R + Knitr + Markdown](#opencpu) section to find out how to customise the text shown while waiting.

### Email
Using an SMTP gateway that you can set up in the admin area, you can send emails to your users. Using the tag `{{login_link}}`, you can send users a personalised link to the run. See the [OpenCPU + R + Knitr + Markdown](#opencpu) section to find out how to personalise email text.

### External link
These are simple external links - you can use them to send users to other, specialised data collection modules, such as a social network generator. If you insert the placeholder `%s`, it will be replaced by the users run_session code, allowing you to link data later. You can choose to "end" this component before the user is redirected to the link or by enabling your external module to call our API to close it, when it's done. 

### Page
Simple text pages. See the [OpenCPU + R + Knitr + Markdown](#opencpu) section to find out how to generate personalised feedback.

### Survey
Surveys are series of questions that are created using simple spreadsheets/**item tables** (ie. Excel, OpenOffice, etc.: *.xls, *.ods, *.xlsx, *.csv etc.).
Survey results are stored in MySQL tables of the same name, which can be used for various conditions later on.

You can add the same survey several times or even loop using branches.  
You can also use the same survey across different runs. For example this would allow you to ask respondents for their demographic information only once. You'd do this by using a Branch with the condition `COUNT(demographics.session_id) > 0` and skipping over the demographics survey, if true.

Survey names may only contain the characters `a-zA-Z0-9_` and need to start with a letter.

#### Item table criteria

* The format must be one of .csv, .xls, .xlsx, .ods (OpenOffice), .xml, .txt
* You can use two sheets
* The first sheet should have the name **survey**, if no such sheet exists, we use the first one.
	* The first line has to contain the column names you used. Name and type should be leftmost.
	* The following column names are used. You can add others, they will be ignored. 
		* **_name_** (mandatory). This can only contain `a-zA-Z0-9_` and needs to start with a letter.
		* **_type_** (mandatory). See below.
		* **label**. You can use [Markdown](http://daringfireball.net/projects/markdown/) and HTML in the question texts. You can also use [Font Awesome](http://fontawesome.io) icons.
		* **skipif** You can refer to the same survey here or you can reference other surveys using `survey_name.variable_name`. SQL syntax.
		* **optional** You can make an item optional (most items are mandatory by default), by using the `*` character in the optional-column. Items optional by default (`check`, `btncheck`, `mmc`) can be made mandatory by using the the `#` character in the optional-column.
		* **choice1, choice2, ..., choice14** (you can use these columns to quickly add choices. If you use many choices repeatedly or need more than 14 choices, it makes more sense to put them on the choices sheet)
* The second, optional sheet should have the name **choices**, if no such sheet exists, we use the second one.
	* The following column names are used (in order).
		* **list name** - you can reference the list of choices using this name on the 'survey' sheet.
		* **name** - the text for the choice that will be stored. This can only contain `a-zA-Z0-9_`.
		* **label** - the text for the choice that is displayed (if you leave this column out or empty, we'll use the name text)

#### Items
Surveys support the following item types. HTML5 form elements and validation are used and polyfilled where necessary using the [Webshims lib](http://afarkas.github.io/webshim/demos/index.html).

* `instruction` display text. instructions are displayed at least once and disappear only when there are no unanswered items left behind them (so putting an instruction directly before another ensures it will be displayed only once)
* `submit` display a submit button. No items are displayed after the submit button, until all of the ones preceding it have been answered. This is useful for pagination and to ensure that answers required for `skipif` or substitutions have been given. 
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

You can make item display contingent on simple and complex conditions like `(survey1.married = 1) OR (survey2.in_relationship = 1 AND survey2.cohabit = 1)`.

## <a id="opencpu"></a> OpenCPU + R + Knitr + Markdown
In pauses, time-branches, emails and pages you can display text to the user. This text is easily formatted using [Markdown](http://daringfireball.net/projects/markdown/) a simple syntax that formats text nicely if you simply write like you would write a plain text email. Markdown can be freely mixed with HTML, so you can e.g. use insert icons from the [Font Awesome](http://fontawesome.io) library using `<i class="icon-smile"></i>`.

If you check the "knitr" checkbox, where Markdown can be used, the text will not just be parsed as Markdown (which is mostly static, unless you use Javascript), but also sent to OpenCPU and parsed by [knitr](http://yihui.name/knitr/). Knitr allows for mixing R syntax chunks and Markdown. [R](http://www.r-project.org/) is a popular open-source statistical programming language, that you can use via [OpenCPU](https://public.opencpu.org/pages/), a RESTful interface to the language that deals with the problem that R was not meant to be used as part of web apps and is insecure. R data frames with the same names as the surveys they derive from will be available in this knitr call, they contain all data that the current user has filled out so far.  
Combined with norm data etc. you can tailor feedback to the user's input, e.g. show where the user lies on the bell curve etc.

### Example
This may sound complicated at first, but should be really simple to use, while still providing most of the functionality available to R users (e.g. pretty [ggplot2](http://ggplot2.org/) plots).

Using this syntax will yield the following results (assuming that the surveys "demographics" and "mood_diary" exist and contain the appropriate data)

	Hi `r demographics[1,]$first_name`!
	
	This is a graph showing how **your mood** fluctuated across the 50 days that you filled out our diary.
	
	### Graph
	```{r mood.plot}
	library(ggplot2)
	mood_diary$mood <- rowSums(user_data[,c('mood1','mood2','mood3')])
	qplot(Day, mood, data = mood_diary) + geom_smooth() + scale_y_continuous("Your mood") + theme_bw()
	```
	
#### This will be displayed to the user

<p>Hi Petunia!</p>

<p>This is a graph showing how <strong>your mood</strong> fluctuated across the 50 days that you filled out our diary.</p>

<h3>Graph</h3>

<p><img src="data:image/png;base64,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rDghZPbs2XfddRch5Lbbbvviiy8UW3fVBX1tbe2+ffsIIQcOHLj66qtpV4cCXW2Bt99+u7m5efv27dOmTSM6W/fVq1e/8cYbhBCbzRYOh/Wz7p999tnbb799ww03tLW13XTTTfpZcULIk08++R//8R+EkL17906ZMkWxdVfdDVOCIPzsZz9rbm42mUyvvPJKZmYm7RopZNWqVdXV1YsXL9bVFrj//vu3bt0auym0sLDw3Xff1c+6nzt3bunSpYFAIBqNvvjii9XV1fpZ95jy8vKGhgZdNfju7u4f//jHXV1dNpvtxRdfLCkpUWbdVRf0AAAgLdUN3QAAgLQQ9AAAjEPQg06lpqZyHMdxnM1mmz179scff0y7RgByQdCDfu3ataunp6exsfEHP/jBwoULDx48SLtGALJA0IN+JSUlpaamut3uurq6Bx988He/+13s/bVr15aUlNjt9lmzZjU2NhJCfvSjH8UfdPzYY489+OCD1CoNMHIIegBCCIn36Nva2urq6l577bW2traKiorYDCQLFy784IMPYp/cuHHj4sWLadYVYIQQ9ACEEJKdnX327FlCSFZWVlNTU21trd1uz8zM7OvrI4R897vf3b9/f19f34kTJ86fPz937lza9QUYAZU+ShBAYR0dHfn5+YQQk8n08ssv//Wvf01JSbFarUlJSYSQpKSkuXPnfvTRR6dOnbrzzjuNRiPt+gKMAHr0AIQQsmnTphkzZhBC3n777c2bN3/44Ydbt26955574h9YuHDh5s2bMW4DWoQePejXwMBAb29vf3//+++//+yzz+7atYsQ0tXV5XK57HZ7R0fHc889l5ubG/vwwoULH3vsMYvFUltbS7XWACOGHj3oV21tbVpa2qRJk954441NmzZNnz6dELJ06VKr1VpQUHDHHXc88sgjn376aWzqsZKSkvz8/FtvvdVkQvcINAZNFnSqt7f3ku+npKRs2bIl/md7e3v8dXJyMsZtQIsQ9ADD8/l8+/fvP3Xq1Pz582nXBWDEMHQDMLwtW7bcc889L7zwgtlspl0XgBHDNMUAAIxDjx4AgHEIegAAxiHoAQAYh6AHAGAcgh4AgHEIegAAxiHoAQAY9/8BYOnKQC1VvO4AAAAASUVORK5CYII=" alt="plot of chunk mood.plot"/> </p>

## Problems and plans
Todo: Should allow a "service message" to be displayed in runs, to make run completely inactive.

### Security
#### Database
End user input should be safe, we use prepared queries etc.  

However, study creators could potentially wreak havoc, because they can write SQL expressions for skipIfs, substitutions, branches, etc. This could be ameliorated by using the least privilege principle, i.e. where these SQL expressions are allowed users can only read the tables that they created.  
For now, the system assumes a high level of trust in study creators.

#### API
* you can create run access tokens using the API
* you can end "External" units using the API
* todo: Should be storing an API key hash, not the API key itself.

## Credit

### Author:
Ruben C. Arslan (2013)

### Based on work and ideas by:
Linus Neumann, Jaap J. A. Denissen, Karsten Gebbert, Jörg Basedow

#### Funded by 

#### Friedrich-Schiller-Universität Jena
* Julia Zimmermann
* Franz J. Neyer

#### Humboldt Universität zu Berlin
Jaap J. A. Denissen