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
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' }

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
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' formr_import(survey_name = "training_diary" )
#' }

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
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' formr_items(survey_name = "training_diary" )
#' }

formr_items = function(survey_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/survey/",survey_name,"/export_item_table?format=json"))
	if(resp$status_code == 200) jsonlite::fromJSON(
			httr::content(resp,encoding="utf8",as="text")
		,simplifyDataFrame = F)
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
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' formr_item_displays(survey_name = "training_diary" )
#' }

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
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' formr_shuffled(run_name = "different_drills" )
#' }

formr_shuffled = function(run_name, host = "https://formr.org") {
	resp = httr::GET( paste0(host,"/admin/run/",run_name,"/random_groups_export?format=json"))
	if(resp$status_code == 200) as.data.frame(
		jsonlite::fromJSON(
			httr::content(resp,encoding="utf8",as="text")
		))
	else stop("This run does not exist.")
}

#' Random date in range
#' 
#' taken from Dirk Eddelbuettel's answer
#' here http://stackoverflow.com/a/14721124/263054
#'
#' @param N desired number of random dates
#' @param lower lower limit
#' @param upper upper limit

random_date_in_range <- function(N, lower="2012/01/01", upper="2012/12/31") {
	st <- as.POSIXct(as.Date(lower))
	et <- as.POSIXct(as.Date(upper))
	dt <- as.numeric(difftime(et,st,units="sec"))
	ev <- sort(runif(N, 0, dt))
	rt <- st + ev
	rt
}

#' Simulate data based on item table
#'
#' Once you've retrieved an item table using \code{\link{formr_items}} you can use this
#' function to sample data from the possible choices.
#' At the moment random data is only generated for choice-type
#' items and numeric ones, as these are most likely to enter data analysis.
#' Does not yet handle dates, times, text, locations, colors
#'  
#'
#' @param item_list the result of a call to \code{\link{formr_connect}}
#' @param n defaults to 300
#' @export
#' @examples
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' sim = formr_simulate_from_items(item_list = formr_items("training_diary"), n = 100)
#' summary(lm(pushups ~ pullups, data = sim))
#' }

formr_simulate_from_items = function (item_list, n = 300)
{
	sim = data.frame(id = 1:n)
	sim$created = random_date_in_range(n, Sys.time() - 10000000, Sys.time())
	sim$ended = sim$created + lubridate::seconds(
		rpois( n, lambda = length(item_list) * 20) # assume 20 seconds per item
	)
	for(i in seq_along(item_list)) {
		item = item_list[[i]]
		if(item$type %in% c("note","mc_heading")) next;
		if( length( item$choices) )  { # choice-based items
			sample_from = type.convert( names(item$choices), as.is = F)
			
			sim[, item$name] = sample(sample_from,size=n,replace=T)
		} else if(stringr::str_detect(item$type, "^[0-9.,]+$")) {
			limits = as.numeric(
				stringr::str_split(item$type_options,pattern=stringr::fixed(","))[[1]]
			)
			if(length(limits)==3) {
				sample_from = seq(from = limits[1],to = limits[2], by = limits[3])
				sim[, item$name] = sample(sample_from,size=n,replace=T)
			}
		}
	}
	sim
}


#' Aggregate data based on item table
#'
#' Once you've retrieved an item table using \code{\link{formr_items}} you can use this
#' function to sample data from the possible choices.
#' At the moment random data is only generated for choice-type
#' items and numeric ones, as these are most likely to enter data analysis.
#' Does not yet handle dates, times, text, locations, colors
#'  
#'
#' @param survey_name case-sensitive name of a survey your account owns
#' @param item_list an item_list, will be auto-retrieved based on survey_name if omitted
#' @param results survey results, will be auto-retrieved based on survey_name if omitted
#' @param host defaults to https://formr.org
#' @export
#' @examples
#' \dontrun{
#' formr_connect(email = "you@@example.net", password = "zebrafinch" )
#' icar_items = formr_items(survey_name="ICAR",host = "http://localhost:8888/formr/")
#' # get some simulated data and aggregate it
#' sim_results = formr_simulate_from_items(icar_items)
#' sim_agg = formr_aggregate(survey_name = "ICAR",item_list = icar_items, results = sim_results)
#' 
#' # get actual data
#' actual = formr_aggregate(survey_name="ICAR")
#' summary(lm(ICAR_matrix ~ ICAR_verbal, data = sim_agg))
#' summary(lm(ICAR_matrix ~ ICAR_verbal, data = actual))
#' }

formr_aggregate = function (survey_name, 
														item_list = formr_items(survey_name, host = host),
														results = formr_import(survey_name, host = host),
														host = "https://formr.org")
{

	# reverse items
	
	names = character(length(item_list))
	for(i in seq_along(item_list)) {
		item = item_list[[i]]
		if( length( item$choices) )  { # choice-based items
			if(stringr::str_detect(item$name, "^[a-zA-Z0-9_]+?[0-9]+R$")) {# with a number and an "R" at the end
				possible_replies = names(item$choices)
				# save as item name with the R truncated
				results[, stringr::str_sub(item$name, 1, -2) ] = max(possible_replies) + 1 - results[, item$name ] # reverse
			}
		}
		names[i] = item$name
	}
	
	scale_stubs = stringr::str_match(names, "^([a-zA-Z0-9_]+?)[0-9]+$")[,2] # fit the pattern
	scales = unique(na.omit(scale_stubs[duplicated(scale_stubs)])) # only those which occur more than once
	# todo: should check whether they all share the same reply options (choices, type_options)
	for(i in seq_along(scales)) {
		scale = scales[i]
		# if the scale name ends in an underscore, remove it
		if(stringr::str_sub(scale, -1) == "_") {
			save_scale = stringr::str_sub(scale,1, -2)
		} else
		{
			save_scale = scale
		}
		
		if(exists(save_scale,where=results)) {
			warning(paste("Would have generated scale",save_scale,"but a variable of that name existed already."))
		} else {
			scale_item_names = names[which(scale_stubs == scale)]
			if(! setequal(
				intersect(scale_item_names, names(results)),
				scale_item_names)) {
				warning("Some items were missing. ", paste(setdiff(scale_item_names, names(results)), collapse = " "))
			} else {
				results[, save_scale] = rowMeans( results[, scale_item_names ] )
				cat(paste0("\n\n",save_scale))
				print(
					psych::alpha(results[, scale_item_names ], check.keys = F)
				)
			}
		}
	}
	results
}