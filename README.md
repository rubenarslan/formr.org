# formr survey framework

#### chain simple forms & surveys into long runs, use the power of R to generate pretty feedback and complex designs

This is a framework that allows you to create simple and complex studies using items spreadsheets for the surveys and "runs" for chaining together various modules. 

The creator and most users of this software work in personality and developmental psychology, but the framework can also be used for sociological panels, simple experiments or as part of an integrated toolkit.

There are three main components: surveys, runs and the R package.

## Surveys
#### ask questions, get data
are simple or complicated forms and surveys used to gather information in a single session.

There is a wide variety of items to choose from: text and number inputs, Likert scales, sliders, geolocation, date pickers, dropdowns and many more. They are geared towards power users, so instead of dragging and dropping elements till your fingers bleed, you upload item spreadsheets that can easily be re-used, combined and shared.

## Runs
#### control your study like a boombox
enable you to link surveys and chain them together. Using a number of boombox-themed control elements to control the participant's way through your study, you can design studies of limitless complexity. You can

- manage access to and eligibility for a study:
- use different pathways for different users:
– send email invites and reminders:
- implement delays/pauses:
- add external modules:
- loop surveys and thus enable diaries and experience-sampling studies:
- give custom feedback, through OpenCPU's R API.
- randomise participants into groups for e.g. A-B-testing or experiments

The following designs and many more are possible:

- simple one-shot surveys
- complex one-shot surveys (using skipping logic, personalised text, complex feedback
- surveys with eligibility limitations
- diary studies including completely flexible automated email reminders
- longitudinal studies (ie. wait 2 months after last participation or re-contact after they return from their exchange year). The items of later waves need not exist in final form at wave 1.
- longitudinal social networks and other studies that require rating a variable number of things or persons


## R package
#### accompanying R package

Wherever you use R in formr you can also use the functions in its R package. If you want to use the package in a different environment, you'll need to install it using these two lines of code.

	install.packages("devtools")
	devtools::install_github("rubenarslan/formr")

The package currently has the following feature sets

- Connecting to formr, importing your data, correctly typing all variables, automatically aggregating scales.
- Easily making feedback plots e.g. 
  `qplot_on_normal(0.8, "Extraversion")`
  The package also has a function to simulate possible data, so you can make feedback plots ahead of collecting data.
- Some shorthand functions for frequently needed operations on the site:
  `first(cars); last(cars); current(cars); "formr." %contains% "mr."`
	

## OpenCPU + R + Knitr + Markdown
[OpenCPU](https://public.opencpu.org/pages/) is a way to safely use complex [R](http://www.r-project.org/) expressions on the web. We use it for all kinds of stuff.

In surveys, pauses, emails and pages you can display text to the user. This text is easily formatted using [Markdown](http://daringfireball.net/projects/markdown/) a simple syntax that formats text nicely if you simply write like you would write a plain text email. Markdown can be freely mixed with HTML, so you can e.g. insert icons from the [Font Awesome](http://fontawesome.io/icons/) library using `<i class="fa fa-smile-o"></i>`.

If you use knitr syntax, where Markdown can be used, the text will not just be parsed as Markdown (which is mostly static), but also be parsed (anew each time) by [knitr](http://yihui.name/knitr/). Knitr allows for mixing R syntax chunks and Markdown.  
[R](http://www.r-project.org/) is a popular open-source statistical programming language, that you can use via [OpenCPU](https://www.opencpu.org/), a RESTful interface to the language that deals with the problem that R was not meant to be used as part of web apps and is insecure. R data frames with the same names as the surveys they derive from will be available in this knitr call, they contain all data that the current user has filled out so far.  
Combined with norm data etc. you can tailor feedback to the user's input, e.g. show where the user lies on the bell curve etc.

## Installation

If you want to test formr, you can simply clone this repository and follow the [instructions](https://github.com/rubenarslan/formr.org/blob/master/documentation/install.md).

#### Credit
See [formr.org/public/team](https://formr.org/public/team) for funding and contact info.

See [composer.json](https://github.com/rubenarslan/formr.org/blob/master/composer.json) for the PHP components we use and
[bower.json](https://github.com/rubenarslan/formr.org/blob/master/webroot/assets/bower.json) for the Javascript and CSS components we use.