# Sample implementations of text message APIs

Here are two sample scripts that will send text messages via Twilio and Clickatell

## Twilio
```r
library(httr)
To = "492222" # retrieve the stored phone number, you would use something like survey$mobile_number
From = "15005000"
Body = paste0("Hey, click this link: https://formr.org/YourStudy/?code=",survey_run_sessions$session )
Account = "IDID"
Token = "Tokentokentoken"
result = POST(
    paste0("https://api.twilio.com/2010-04-01/Accounts/",Account,"/Messages.json"), 
    authenticate(Account, Token), 
    body = list(From = From, To = To, Body = Body)
)
result2 = content(result)
if(is.null(result2$error_code)) { FALSE } else { TRUE }
```

## Clickatell

```r
library(httr)
To = "492222" # retrieve the stored phone number, you would use something like survey$mobile_number
Body = paste0("Hey, click this link: https://formr.org/YourStudy/?code=",survey_run_sessions$session )
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
```

## Massenversand.de

```r
library(httr) 

nick = test_lt_intro$text_reg_nick # just nickname for adressing the participant 

# this is a shitty way to get a number from a textfield, suggestions for improvement are welcome!

receiver = paste0("0", as.character(test_lt_intro$text_reg_mobile))
sender = "0123456789" 
# don't forget to use html character encoding (e.g. for emptyspaces) 
msg = paste0("Hey%20",nick,",%20click%20this%20link:%20https://dev.formr.org/TestRunLT/?code=", survey_run_sessions$session,collapse="")

id = "xxxxxx" # given by provider 
pw = "yyyyyy" # ...
time = "0"  # send now!, see provider API 
msgtype = "t" # see provider API
tarif = "OA"  # see provider API
test = "0" # see provider API
 
params = paste0(
  "test=", test, "&",

  "receiver=",receiver,"&",
  "sender=",sender,"&",
  "msg=",msg,"&",
  "id=",id,"&",
  "pw=",pw,"&",
  "time=",time,"&",
  "msgtype=",msgtype,"&",
  "tarif=",tarif
  )

# there are two servers, maybe on should try the other one as well in case of failure ...

answ = GET(paste0("https://gate1.goyyamobile.com/sms/sendsms.asp?",params,sep=""))


# returns true if message was not send correctly (see provider API for error codes)

return (rawToChar(answ$content) != "OK")
```