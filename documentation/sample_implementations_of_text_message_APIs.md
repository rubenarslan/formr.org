# Sample implementations of text message APIs

## Twilio
library(httr)
To = "492222" # retrieve the stored phone number
From = "15005000"
Body = paste0("Hey, click this link: https://dev.formr.org/YourStudy/?code=",paste(rpois(64, 2),collapse="") )
Account = "IDID"
Token = "Tokentokentoken"
result = POST(
    paste0("https://api.twilio.com/2010-04-01/Accounts/",Account,"/Messages.json"), 
    authenticate(Account, Token), 
    body = list(From = From, To = To, Body = Body)
)
result2 = content(result)
if(is.null(result2$error_code)) { FALSE } else { TRUE }


## Clickatell

library(httr)
To = "492222" # retrieve the stored phone number
Body = paste0("Hey, click this link: https://dev.formr.org/YourStudy/?code=",paste(rpois(64, 2),collapse="") )
Token = "Tokentokentoken"
result = POST(
    "https://api.clickatell.com/rest/message", 
    verbose(),
    add_headers(
	"X-Version" = 1,
	"Content-Type" = "application/json",
	"Authorization" = paste("Bearer", Token),
	"accept" = "application/json"
	), 
    body = paste0('{"to":["',To,'"],"text":"',Body,'"}')
)
(result2 = content(result))

if(is.null(result2$error_code)) { FALSE } else { TRUE }