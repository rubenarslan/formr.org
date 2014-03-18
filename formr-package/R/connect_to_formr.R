#' Connect to formr
#'
#' Connects to formr using your normal login and the httr library
#' which supports persistent session cookies.
#'
#' @param email your registered email address
#' @param password your password
#' @param host defaults to https://formr.org
#' @export
#' @examples
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )

formr_connect = function(email, password, host = "https://formr.org") {
 	resp = httr::POST( paste0(host,"/public/login"),body=  list(email = email, password = password) )
 	text = httr::content(resp,encoding="utf8",as="text")
 	if(grepl("Success!",text,fixed = T)) TRUE
 	else if(grepl("Error.",text,fixed = T)) stop("Incorrect credentials.")
 	else warning("Already logged in.")
}



#' Download data from formr
#'
#' After connecting to formr using \code{\link{formr_connect}}
#' you can download data using this command.
#'
#' @param survey_name case-sensitive name of a survey your account owns
#' @param host defaults to https://formr.org
#' @export
#' @examples
#' formr_import(survey_name = "training_diary" )

formr_import = function(survey_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/survey/",survey_name,"/export_results?format=json"))
	if(resp$status_code == 200) as.data.frame(
		jsonlite::fromJSON(
			httr::content(resp,encoding="utf8",as="text")
		))
	else stop("This survey does not exist.")
}

#' Download items from formr
#'
#' After connecting to formr using \code{\link{formr_connect}}
#' you can download items using this command.
#'
#' @param survey_name case-sensitive name of a survey your account owns
#' @param host defaults to https://formr.org
#' @export
#' @examples
#' formr_items(survey_name = "training_diary" )

formr_items = function(survey_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/survey/",survey_name,"/export_item_table?format=json"))
	if(resp$status_code == 200) as.data.frame(
		jsonlite::fromJSON(
			httr::content(resp,encoding="utf8",as="text")
		))
	else stop("This survey does not exist.")
}


#' Download detailed result timings and display counts from formr
#'
#' After connecting to formr using \code{\link{formr_connect}}
#' you can download detailed times and display counts for each item using this command.
#'
#' @param survey_name case-sensitive name of a survey your account owns
#' @param host defaults to https://formr.org
#' @export
#' @examples
#' formr_item_displays(survey_name = "training_diary" )

formr_item_displays = function(survey_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/survey/",survey_name,"/export_itemdisplay?format=json"))
	if(resp$status_code == 200) as.data.frame(
		jsonlite::fromJSON(
			httr::content(resp,encoding="utf8",as="text")
		))
	else stop("This survey does not exist.")
}

#' Download random groups
#'
#' formr has a specific module for randomisation.
#' After connecting using \code{\link{formr_connect}}
#' you can download the assigned random groups and merge them with your data.
#'
#' @param run_name case-sensitive name of the run in which you randomised participants
#' @param host defaults to https://formr.org
#' @export
#' @examples
#' formr_shuffled(run_name = "different_drills" )

formr_shuffled = function(run_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/run/",run_name,"/random_groups_export?format=json"))
	if(resp$status_code == 200) as.data.frame(
		jsonlite::fromJSON(
			httr::content(resp,encoding="utf8",as="text")
		))
	else stop("This run does not exist.")
}


formr_connect("rubenarslan@gmail.com","", host = "http://localhost:8888/formr/")
df = formr_import("training_diary", host = "http://localhost:8888/formr/")
items = formr_items("training_diary", host = "http://localhost:8888/formr/")
shuffles = formr_shuffled("training_diary", host = "http://localhost:8888/formr/")
