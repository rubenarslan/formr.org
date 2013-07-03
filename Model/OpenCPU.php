<?php
class OpenCPU {
	private $instance = 'https://public.opencpu.org/';
	private $user_data = '';
	private $curl_c;
	public function __construct()
	{
		$this->curl_c = curl_init();
		curl_setopt($this->curl_c, CURLOPT_URL, $this->instance.'R/pub/base/identity/json');
		curl_setopt($this->curl_c, CURLOPT_POST, 1); // Method is "POST"
		curl_setopt($this->curl_c, CURLOPT_RETURNTRANSFER, 1); // Returns the curl_exec string, rather than just Logical value
	}
	public function addUserData($datasets)
	{
		foreach($datasets AS $df_name => $data):
			$this->user_data .= $df_name . ' = as.data.frame(RJSONIO::fromJSON("'.addslashes(json_encode($data,JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK)).'", nullValue = NA))
';
		endforeach;
	}
	public function knitForUserDisplay($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
library(knitr)
opts_chunk$set(warning=F,message=F,echo=F)
'.
$this->user_data .
'```
'.
		$source;
		return $this->knit($source);
	}
	public function knitForAdminDebug($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
library(knitr)
opts_chunk$set(warning=T,message=T,echo=T)
'.
$this->user_data .
'```
'.
		$source;

		pr($source);
		
		return $this->knit($source);
	}
	public function knit($source)
	{
#		pr($source);
		$post = array('x' => '{
library(knitr); library(markdown);
sub(
	".*<body>(.*)</body>.*", 
	"\\\\1", 
	markdownToHTML(text = 
		knit(text = "' . addslashes($source) . '")
	)
)
}');
#pr(htmlspecialchars($post['x']));
		curl_setopt($this->curl_c, CURLOPT_POSTFIELDS, http_build_query($post));
		
		$result = curl_exec($this->curl_c);
		curl_close($this->curl_c);
		$html = json_decode($result);
		
		if(!$html):
			alert($result,'alert-error');
			alert($source,'alert-error');
			return false;
		endif;
		
		return $html[0];
	}
}

