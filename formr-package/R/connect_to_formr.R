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
#' @export
#' @examples
#' formr_import(survey_name = "training_diary" )

formr_import = function(survey_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/survey/",survey_name,"/export_tsv"))
	if(resp$status_code == 200) read.delim(textConnection(httr::content(resp,encoding="utf8",as="text")))
	else stop("This survey does not exist.")
}