<h3>API</h3>

The formr API is the primary way to get data/results out of the platform. It's a low-level HTTP-based API that you can use principally to get results of a 
study for specified participants (sessions)

Resource requests to the formr API require that you have a valid access token which you can obtain by providing api credentials (a client id and a client 
secret)

API base URL: https://formr.org/api or https://api.formr.org


#Obtaining Client ID and Client Secret.
--------------------------------------

Access to the API is heavily restricted due to privacy issues so only the administrators of formr are able to provide api credentials to formr users. To 
obtain api credentials send an email to rubenarslan@gmail.com or cyril.tata@gmail.com and your credentials will be sent to you in a few minutes.

#Obtaining An Access Token
-------------------------

An access token is an opaque string that identifies a formr user and can be used to make API calls without further authentication. formr api access tokens 
are short-lived tokens and have a life span of about an hour.

To generate an access token you need to make an HTTP POST request to the token endpoint of the API

<code>

POST /oauth/access_token?
     client_id={client-id}
    &client_secret={client-secret}
    &grant_type=client_credentials

</code>

This call will return a JSON object containing an access token which can be used to access the api without further authentication requred.

Sample successful response

<code>
{
	"access_token":"77c48497f09e95c504613635f0dc13d563504b4d",
	"expires_in":3600,
	"token_type":"Bearer",
	"scope":null
}
</code>

The attribute 'expires_in' indicates the number of seconds for which is token is valid from its time of creation. If there is an error for example if the 
client details are not correct, then an error object is returned containing an error_description and sometimes an error_uri where you can read more about the 
generated error. An example of an error object is

<code>
{
	"error":"invalid_client",
	"error_description":"The client credentials are invalid"
}
</code>


#Making Resource Requests using generated access token.
-----------------------------------------------------

With the generated access token, you are able to make requests to the resource endpoints of the formr API. For now only the results resource endpoint has 
been implement

##Getting study results over the API
----------------------------------

### Request
-----------
To obtain the results of a particular session in a particular run, send a GET HTTP request to the get endpoint along side the access_token obtained above 
and a request object. A request object in this case MUST be a JSON formatted string and passed using a parameter name called "request". For example

<code>

GET /get/results?
     access_token={access-token}
    &request={json-string-representing-request}

</code>

The JSON string representing the "request" object for the GET request has to be of the following format

<code>
request = {
	run: {
		name:  "run name",
		sessions: ["sdaswew434df", "fdgdfg4323"],
		surveys: [{
			name: "survey_1",
			items: ["survey_1_item_1", "survey_1_item_2", "survey_1_item_3"]
		},
		{
			name: "survey_2",
			items: ["survey_2_item_1", "survey_2_item_2", "survey_2_item_3"]
		}]
	}
}
</code>

Parameters of the run object:

name (required): This should be name name of the run as shown on formr.org

sessions (required): An array of strings representing the sessions of interest whose results will be returned. At the moment the API does not support 
returning the results of all the sessions in the run in one request but this might be implemented in the future. A single session can also be specified 
with the "session" parameter which MUST be a string an not an array of strings.

surveys (optional): An array of objects. Each object represents a survey in the run that will be included in the results set. If no survey(s) is(are) 
specified, all the surveys in the run will be included in the results set. A single survey can be specified by using the parameter name "survey" which must be an object and not an array of one object. Each survey object can have the following attributes:
	name: The name of the survey as represented in formr.org
	items: An array of items in the survey that should be included in the results set. If this is not specified, all the items in the survey will be 
included in the results set which is generally not recommended.

###Response
-----------
The response to a results request is a JSON object. The keys of this JSON structure are the names of the survey that were indicated in the requested and the value associated to each survey entry is an array of objects representing the results collected for that survey for all the requested sessions. An example of a response object could be the following:

<code>

{
	"survey_1": [{
		"session": "sdaswew434df",
		"survey_1_item_1": "answer1",
		"survey_1_item_2": "answer2",
		"survey_1_item_2": "answer3,
	},
	{
		"session": "fdgdfg4323",
		"survey_1_item_1": "answer4",
		"survey_1_item_2": "answer5",
		"survey_1_item_2": "answer6,
	}],
	"survey_2": [........]
}

</code>


#Using the formr API in R
--------------------------

// some note on the R package and how to include the library to the R work space

## Connecting to the API with client ID and client secret

// Code showing call to helper function and paramters

## Getting results over the API

// Code showing call to result helper funciton and parameter






