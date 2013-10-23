<?php
class OpenCPU {
	private $instance;
	private $user_data = '';
	private $curl_c;
	public function __construct()
	{
		global $settings;
		$this->instance = $settings['opencpu_instance'];
		$this->curl_c = curl_init();
#		curl_setopt($this->curl_c, CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
		curl_setopt($this->curl_c, CURLOPT_URL, $this->instance.'ocpu/library/base/R/identity/json');
		curl_setopt($this->curl_c, CURLOPT_POST, 1); // Method is "POST"
		curl_setopt($this->curl_c, CURLOPT_RETURNTRANSFER, 1); // Returns the curl_exec string, rather than just Logical value
	}
	public function addUserData($datasets)
	{
		foreach($datasets AS $df_name => $data):
			$this->user_data .= $df_name . ' = as.data.frame(RJSONIO::fromJSON("'.addslashes(my_json_encode($data)).'", nullValue = NA), stringsAsFactors=F)
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

#		pr($source);
		
		return $this->knit($source);
	}
	public function knit($source)
	{
#		pr($source);
		$post = array('x' => '{
library(knitr); library(markdown);
markdownToHTML(text = 
	knit(text = "' . addslashes($source) . '"),
    fragment.only = T
)
}');
#pr(htmlspecialchars($post['x']));
		curl_setopt($this->curl_c, CURLOPT_POSTFIELDS, http_build_query($post));
		
		$result = curl_exec($this->curl_c);
		curl_close($this->curl_c);
		$html = json_decode($result);
		
		if(!$html):
			alert($result,'alert-error');
			alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-error');
			return false;
		endif;
		
		return $html[0];
	}
	public function selftest() 
	{
		$source = '{
		library(knitr); library(markdown);
		markdownToHTML(text = 
			knit(text = "__Hello__ World `r 1`"),
		    fragment.only = T
		)
		}';
		
/*		$source = '{
		library(knitr); library(markdown);
		paste(markdownToHTML(text = 
			knit(text = "__Hello__ World"),
		    fragment.only = T
		), "```{r}1```")
		}';
*/		curl_setopt($this->curl_c, CURLOPT_POSTFIELDS, http_build_query(array('x' => $source)));
		curl_setopt($this->curl_c, CURLOPT_HEADER, 1);

		// Then, after your curl_exec call:
		$header_size = curl_getinfo($this->curl_c, CURLINFO_HEADER_SIZE);
		$result = curl_exec($this->curl_c);
		list($header, $body) = explode("\r\n\r\n", $result, 2);
		$result = $body;
		$status = curl_getinfo($this->curl_c,CURLINFO_HTTP_CODE);
		curl_close($this->curl_c);
		$html = json_decode($result);
		
		alert("HTTP header: <pre style='background-color:transparent;border:0'>$header</pre>",'alert-error');
		alert("HTTP status $status: $result",'alert-error');
		alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-error');
		
		return $html[0];
		
	}
}
function my_json_encode($arr)
{
	if(!defined("JSON_UNESCAPED_UNICODE")):
	
        //convmap since 0x80 char codes so it takes all multibyte codes (above ASCII 127). So such characters are being "hidden" from normal json_encoding
        array_walk_recursive($arr, function (&$item, $key) { if (is_string($item)) $item = mb_encode_numericentity($item, array (0x80, 0xffff, 0, 0xffff), 'UTF-8'); });
        return mb_decode_numericentity(json_encode($arr, JSON_NUMERIC_CHECK), array (0x80, 0xffff, 0, 0xffff), 'UTF-8');
	else:
		return json_encode($arr,JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
	endif;
}
