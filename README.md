# Psytests survey framework

## Component 1: Surveys

You can design simple surveys using this framework.  
Surveys are series of questions that are created using simple spreadsheets/**item tables** (ie. Excel, OpenOffice, etc.: *.xls, *.ods, *.xlsx, *.csv etc.).
Survey results are stored in MySQL tables of the same name (using only the characters `a-zA-Z0-9_`), which can be used for various conditions later on.

### Item table criteria

* The format must be one of .csv, .xls, .xlsx, .ods (OpenOffice), .xml, .txt
* You can use two sheets
* The first sheet should have the name **survey**, if no such sheet exists, we use the first one.
	* The first line has to contain the column names you used. The order doesn't matter.
	* The following column names are used. You can add others, they will be ignored. 
		* **_name_** (mandatory). This can only contain `a-zA-Z0-9_` and needs to start with a letter.
		* **_type_** (mandatory). See below.
		* **label**. You can use [Markdown](http://daringfireball.net/projects/markdown/) and HTML in the question texts.
		* **skipif** You can refer to the same survey here or you can reference other surveys using `survey_name.variable_name`. SQL syntax.
		* **choice1, choice2, ..., choice14** (you can use these columns to quickly add choices. If you use many choices repeatedly or need more than 14 choices, it makes more sense to put them on the choices sheet)
* The second, optional sheet should have the name **choices**, if no such sheet exists, we use the second one.
	* The following column names are used
	* **list name** - you can reference the list of choices using this name on the 'survey' sheet.
	* **label** - the text for the choice that is displayed (if you leave this column out or empty, we'll use the name text)
	* **name** - the text for the choice that will be stored. This can only contain `a-zA-Z0-9_`.


### Items
Surveys support the following item types. HTML5 form elements and validation are used and polyfilled when necessary using the [Webshims lib](http://afarkas.github.io/webshim/demos/index.html).

* `instruction` display text. instructions are displayed at least once and disappear only when there are no unanswered items left behind them (so putting an instruction directly before another ensures it will be displayed only once)
* `submit` display a submit button. No items are displayed after the submit button, until all of the ones preceding it have been answered. This is useful for pagination and to ensure that answers required for `skipIf` or substitutions have been given. 
* multiple choice family
	* `mc` multipe choice (radio buttons), you can choose only one. Choices are (currently) defined using the MCalt1-12 columns
	* `mmc` multiple multiple choice (check boxes), you can choose several. Choices defined as above
	* `check` a single check box for confirmation of a statement.
	* `btnradio` like `mc` but radio buttons are styled as buttons containing the choice text
	* `btncheckbox` like `mmc` and `btnradio`
	* `btncheck` like `check` and `btnradio`
	* `btnrating min,max,step` This shows MCalt1 to the left, MCalt2 to the right and a series of buttons as defined by `min,max,step` in between. Defaults to `1,5,1`
	* `sex` shorthand for `btnradio` with the ♂, ♀ symbols as choices
	* `select` dropdowns, you can choose only one
	* `mselect` dropdowns, choose many
	* `select_add` like `select`, allows users to choose an option not given. Uses [Select2](http://ivaynberg.github.io/select2/).
	* `mselect_add` like `mselect` and `select_add`, allows users to add options not given
* `range min,max,step` these are sliders. The numeric value chosen is not displayed. Text to be shown to the left and right of the slider can be defined using the MCalt1 and MCalt2 fields. Defaults are `1,100,1`.
* `range_list min,max,step` like `range` but the individual steps are visually indicated using ticks and the chosen number is shown to the right. 
* simple input family
	* `text` allows you to enter a text in a single-line input field. Adding a number `text 100` defines the maximum number of characters that may be entered.
	* `textarea` displays a multi-line input field
	* `letters` like `text`, allows only letters, not numbers.
	* `number min,max,step` for numbers. `step` defaults to `1`, using `any` will allow any decimals.
	* `email` for email addresses
* datetime family
	* `time` for time. a small clock is prepended to the input
	* `date` for a date
	* `datetime` for a date & time
	* `month`, `yearmonth` etc. should work too
* 
* `url`, `cc` (credit card number), `color`, `tel` work too

### skipIf

You can make item display contingent on simple and complex conditions like `(survey1.married = 1) OR (survey2.in_relationship = 1 AND survey2.cohabit = 1)`.

### optional

You can make an item optional (most items are mandatory by default), by using the `*` character in the type-column. Items optional by default (`check`, `btncheck`, `mmc`) can be made mandatory by using the the `#` character in the type-column.


## Component 2: Runs

Simple surveys can be made into elaborate studies by using runs.

Runs allow you to:

* manage access and eligibility for a study and different pathways using branches
* send e-mail invites and reminders
* implement delays
* add external modules
* loop surveys and thus enable diaries, experience-sampling studies etc.
* soon: give feedback based on user input (already primitively possible using branches)

### Problems and plans

#### Security
##### Database
End user input is properly escaped where necessary, we use prepared queries etc.  
However, study creators could potentially wreak havoc, because they can write SQL expressions for skipIfs, substitutions, branches, etc. This could be ameliorated by using the least privilege principle, i.e. where these SQL expressions are allowed users can only read the tables that they created.  
For now, the system assumes a high level of trust in study creators.

##### API
Todo: Should be storing an API key hash, not the API key itself.

### Credit

#### Author:
Ruben C. Arslan (2013)

#### Based on work and ideas by:
Linus Neumann, Karsten Gebbert, Jörg Basedow

#### Funded by 
HU Berlin Jaap J. A. Denissen, FSU Jena