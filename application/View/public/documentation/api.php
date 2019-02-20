<h3>formr API</h3><hr />

<p>
The formr API is the primary way to get data/results out of the platform. It's a low-level HTTP-based API that you can use principally to get results of a 
study for specified participants (sessions)
</p>
<p>
Resource requests to the formr API require that you have a valid access token which you can obtain by providing API credentials (a client id and a client 
secret)
</p>

<p>
API base URL: <br />
<code class="php">https://api.formr.org</code>
</p>


<h4>Obtaining Client ID and Client Secret</h4>
<p>
Access to the API is restricted, so only the administrators of formr are able to provide API credentials to formr users. To 
obtain API credentials, send an email to <a title=" We're excited to have people try this out, so you'll get a test account, if you're human or at least cetacean. But let us know a little about what you plan to do." class="schmail" href="mailto:IMNOTSENDINGSPAMTOcyril.tata@that-big-googly-eyed-email-provider.com">Cyril</a> and your credentials will be sent to you.
</p>

<h4>Obtaining An Access Token</h4>
<p>
An access token is an opaque string that identifies a formr user and can be used to make API calls without further authentication. formr API access tokens 
are short-lived and have a life span of about an hour.
</p>
	
<p>To generate an access token you need to make an HTTP POST request to the token endpoint of the API</p>

<pre>
<code class="http">
POST /oauth/access_token?
     client_id={client-id}
    &amp;client_secret={client-secret}
    &amp;grant_type=client_credentials
</code>
</pre>

<p>
This call will return a JSON object containing an access token which can be used to access the API without further authentication required.
<br />
Sample successful response
</p>

<pre>
<code class="json">
{
	"access_token":"XXXXXX3635f0dc13d563504b4d",
	"expires_in":3600,
	"token_type":"Bearer",
	"scope":null
}
</code>
</pre>

The attribute <code>expires_in</code> indicates the number of seconds for which is token is valid from its time of creation. If there is an error for example if the 
client details are not correct, then an error object is returned containing an error_description and sometimes an error_uri where you can read more about the 
generated error. An example of an error object is

<pre>
<code class="json">
{
	"error":"invalid_client",
	"error_description":"The client credentials are invalid"
}
</code>
</pre>


<h4>Making Resource Requests using generated access token</h4>

With the generated access token, you are able to make requests to the resource endpoints of the formr API. For now only the results resource endpoint has 
been implemented

<h5>Getting study results over the API</h5>

<h6> REQUEST </h6>
To obtain the results of a particular set of sessions in a particular run, send a GET HTTP request to the get endpoint along side the access_token obtained above 
together with the necessary parameters as shown below:

<pre>
<code class="http">
GET /get/results?
     access_token={access-token}
    &amp;run[name]={name of the run as it appears on formr}
    &amp;run[sessions]={comma separated list of session codes OR leave empty to get all sessions}
    &amp;surveys[survey_name1]={comma separated list items to get from survey_name1 OR leave empty to get all items}
    &amp;surveys[survey_name2]={comma separated list items to get from survey_name2 OR leave empty to get all items}
</code>
</pre>

<p>
	<b><i>Notes:</i></b><br />
	<ul>
		<li><i>survey_name1</i> and <i>survey_name2</i> should be the actual survey names</li>
		<li>If you want to get results for all surveys in the run you can omit the <i><b>survey</b></i> parameter</li>
		<li>If you want to get all items from a survey, keep the items list empty.</li>
	</ul>
</p>

<h6>RESPONSE</h6>

<p>
The response to a results request is a JSON object. The keys of this JSON structure are the names of the survey that were indicated in the requested and the value associated to each survey entry is an array of objects representing the results collected for that survey for all the requested sessions. An example of a response object could be the following:
</p>

<pre>
<code class="json">
{
	"survey_1": [{
		"session": "sdaswew434df",
		"survey_1_item_1": "answer1",
		"survey_1_item_2": "answer2",
		"survey_1_item_2": "answer3",
	},
	{
		"session": "fdgdfg4323",
		"survey_1_item_1": "answer4",
		"survey_1_item_2": "answer5",
		"survey_1_item_2": "answer6",
	}],
	"survey_2": [........]
}
</code>
</pre>

<h4>Using the formr API in R</h4>
<pre><code class="r">
# load the httr package
library(httr)

# Login using your client ID and client Secret to get an access token
login <- list( # define login credentials
  client_id = "bb472xxxxxxxxe1918",
  client_secret = "zEfgeyJ0eXXXXXEwYwNGZj",
  grant_type = "client_credentials"
)
request <- POST( # send POST request
  "https://api.formr.org/oauth/access_token",
  body = login, 
  encode = "form"
)
# parse response to get access token
# If there an error then the response object would contain the details of the error in response$error
response <- content(request)
access_token <- response$access_token

#With a valid access token, call API to get the results of a particular study (run)
query <- list(
  access_token = access_token,
  "run[name]" = "/enter run name here/",
  "run[session]" = "/here you can specify a full session code or just a substring(but include the beginning /",
  "surveys[survey_1]" = "item_1, item_2, item_etc.", # comma separated list of items to get from the survey or leave empty string to get all items
  "surveys[survey_2]" = "item_3, item_4, item_etc."
)
request <- GET("https://api.formr.org/get/results", query=query)
results <- content(request)

# With a valid response you can, for example, extract the results of a particular survey say 'survey_1':
survey_1 = results$survey_1
survey_1[, c("item_1","item_2")]
</code></pre>


