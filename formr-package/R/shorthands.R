#' Gives the first non-missing element
#'
#' Just a simple shorthand to get the first, non-missing argument per default.
#' Can give more than one element and can include missing elements.
#' The inverse of \code{\link{last}}.
#'
#' @param x vector of which you want the first element
#' @param n number of elements to take from the beginning
#' @param na.rm whether to remove missings first, defaults to TRUE
#' @export
#' @examples
#' first( c(NA,1:10) )
#' last( c(NA, 1:10), 2, TRUE )

first = function(x, n = 1, na.rm = TRUE) {
    if(na.rm) x = na.omit(x)
    head(x, n)
}

#' Gives the last non-missing element
#'
#' Just a simple shorthand to get the last, non-missing argument per default.
#' Can give more than one element and can include missing elements.
#' The inverse of \code{\link{first}}.
#'
#' @param x vector of which you want the last element
#' @param n number of elements to take from the end
#' @param na.rm whether to remove missings first, defaults to TRUE
#' @export
#' @examples
#' last( c(1:10,NA) )
#' last( c(1:10,NA), 2, TRUE )

last = function(x, n = 1, na.rm = TRUE) {
    if(na.rm) x = na.omit(x)
    tail(x, n)
}

#' Gives the last element, doesn't omit missings
#'
#' Just a simple shorthand to get the current element (in a formr df,
#' where the last element is always the one from the current session).
#'
#' @param x vector of which you want the current element
#' @export
#' @examples
#' current( c(1:10,NA) )
#' current( 1:10 )
current = function(x) {
    tail(x, 1)
}


#' check whether a character string contains another
#'
#' Just a simple shorthand so that inexperienced R users don't have
#' to use somewhat complex functions such as \code{\link{grepl}} and \code{\link{stringr::str_detect}}
#' with non-default arguments (e.g. fixed params).
#'
#' @param haystack string in which you search
#' @param needle string to search for
#' @export
#' @examples
#' "1, 2, 3, 4, you" %contains% "you"
#' "1, 2, 3, 4, you" %contains% 1 # unlike str_detect casts all needles as characters
#' "1, 2, 3, 4, you" %contains% 343

"%contains%" = function(haystack, needle) {
    stringr::str_detect(haystack, stringr::fixed(as.character(needle)) )
}


#' percentage of missings for each variable in a data.frame
#'
#' This functions simply reports the number of missings as the
#' percentage of the maximum number of rows.
#' It also works on single variables.
#'
#' @param df data.frame or variable
#' @param vars subset of variables, defaults to all
#' @export
#' @examples
#' fruits = c("apple", "banana", NA, "pear", "pinapple", NA)
#' pets = c("cat", "dog", "anteater", NA, NA, NA)
#' favorites = data.frame(fruits, pets)
#' miss_frac(favorites)
#' miss_frac(favorites$fruits)
#' miss_frac(favorites, 2)

miss_frac = function(df, vars = 1:NCOL(df)) { 
    if(NCOL(df) == 1) fracts = sum(is.na(df))
    else if(NCOL(df[,vars]) == 1) fracts = sum(is.na(df[,vars]))
    else fracts = colSums( plyr::colwise(is.na)(df[,vars]) )
    round( fracts / NROW(df) , 2) 
}

#' aggregates two variables from two sources into one
#'
#' Takes two variables with different missings
#' and gives one variable with values of the second
#' variable substituted where the first had missings.
#'
#' @param df data.frame or variable
#' @param new_var new variable name
#' @param var1 first source. Assumed to be new_var.x (default suffixes after merging)
#' @param var2 second source. Assumed to be new_var.y (default suffixes after merging)
#' @param remove_old_variables Defaults to not keeping var1 and var2 in the resulting df.
#' @export
#' @examples
#' cars$dist.x = cars$dist
#' cars$dist.y = cars$dist
#' cars$dist.y[2:5] = NA
#' cars$dist.x[10:15] = NA # sprinkle missings
#' cars$dist = NULL # remove old variable
#' cars = aggregate2sources(cars, 'dist')
aggregate2sources = function(df, new_var, var1 = NULL, var2 = NULL, remove_old_variables = TRUE) {
	if(is.null(var1) && is.null(var2)) {
		var1 = paste0(new_var,".x")
		var2 = paste0(new_var,".y")
	}
    if(exists(new_var, where = df)) {
        warning(paste(new_var,"already exists. Maybe delete it or choose a different name, if you're saving over your original dataframe."))
    }
	df[, new_var ] = df[ , var1 ]
	oldmiss = sum(is.na(df[, new_var]))
	df[ is.na( df[, var1] ) , new_var] = df[ is.na( df[, var1] ) , var2]
    
    if(remove_old_variables) {
    	df[, var1] = NULL
    	df[, var2] = NULL
    }
	
	message( paste(oldmiss - sum(is.na(df[, new_var]))	, " fewer missings") )
	df
}

# todo: in_time_window() days_passed_since()