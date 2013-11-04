<?php
class OpenCPU {
	private $instance;
	private $user_data = '';
	private $curl_c;
	public $http_status = null;
	public function __construct($instance)
	{
		$this->instance = $instance;
		$this->curl_c = curl_init();
		curl_setopt($this->curl_c, CURLOPT_RETURNTRANSFER, 1); // Returns the curl_exec string, rather than just Logical value
	}

	public function r_function($function,$post,$headers = false)
	{
		curl_setopt($this->curl_c, CURLOPT_URL, $this->instance.'/ocpu/library/'.$function);
		
		if($post !== null):
			curl_setopt($this->curl_c, CURLOPT_POST, 1); // Method is "POST"
			curl_setopt($this->curl_c, CURLOPT_POSTFIELDS, http_build_query($post));
		endif;
		if($headers):
			curl_setopt($this->curl_c, CURLOPT_HEADER, 1);
		endif;
		
		$result = curl_exec($this->curl_c);
		
		if($headers):
			$this->header_size = curl_getinfo($this->curl_c, CURLINFO_HEADER_SIZE);
			$this->http_status = curl_getinfo($this->curl_c,CURLINFO_HTTP_CODE);
		endif;
		curl_close($this->curl_c);
		
		return $result;
	}
	
	public function identity($post, $return = '/json',$headers = false)
	{
		return $this->r_function('base/R/identity'.$return, $post, $headers);
	}
	
	public function evaluate($source,$return = '/json',$headers = false)
	{
		$post = array('x' => '{ 
			(function() {
		'.$this->user_data.'
			'.$source.'
			})() }');
			
		$result = $this->identity($post,$return,$headers);
		$parsed = json_decode($result);
		if($parsed===null):
			alert($result,'alert-error');
			alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-error');
			return null;
		elseif(empty($parsed)):
			return null;
		else:
			return $parsed[0];
		endif;
	}
	public function evaluateWith($results_table, $source,$return = '/json',$headers = false)
	{
		$post = array('x' => '{ 
			(function() {
		'.$this->user_data.'
			with('.$results_table.', {
				'.$source.'
				})
			})() }');
			
		$result = $this->identity($post,$return,$headers);
		$parsed = json_decode($result);
		if($parsed===null):
			alert($result,'alert-error');
			alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-error');
			return null;
		elseif(empty($parsed)):
			return null;
		else:
			return $parsed[0];
		endif;
	}
	public function evaluateAdmin($source,$return = '',$headers = true)
	{
		$post = array('x' => '{ 
			(function() {
		'.$this->user_data.'
			'.$source.'
			})() }');
			
		$result = $this->identity($post,$return,$headers);
		return $this->debugCall($result);
	}
	
	public function knit($source,$return = '/json',$headers = false,$options = '"base64_images","smartypants","highlight_code","mathjax"')
	{
		$post = array('x' => '{
library(knitr)
	knit2html(text = "' . addslashes($source) . '",
    fragment.only = T, options=c('.$options.')
)
}');
		return $this->identity($post,$return,$headers);
	}
	public function addUserData($datasets)
	{
		foreach($datasets AS $df_name => $data):
			$this->user_data .= $df_name . ' = as.data.frame(RJSONIO::fromJSON("'.addslashes(my_json_encode($data)).'", nullValue = NA), stringsAsFactors=F)
'; ### loop through the given datasets and import them to R via JSON
		endforeach;
	}
	public function knitForUserDisplay($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
opts_chunk$set(warning=F,message=F,echo=F)
'.
$this->user_data .
'```
'.
		$source;
		
		$result = $this->knit($source,'/json');
		$html = json_decode($result);
		
		if(!$html):
			alert($result,'alert-error');
			alert("<pre style='background-color:transparent;border:0'>".$source."</pre>",'alert-error');
			return false;
		endif;
		
		return $html[0];
	}
	public function knitForAdminDebug($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
opts_chunk$set(warning=T,message=T,echo=T)
'.
$this->user_data .
'```
'.
		$source;
		
		$results = $this->knit($source,'', true);
		return $this->debugCall($results);

	}



	public function knitEmail($source)
	{
		$source =
'```{r settings,message=FALSE,warning=F,echo=F}
email_image = function(x) {
	cid = gsub("[^a-zA-Z0-9]", "", substring(x,8))
	structure(paste0("cid:",cid,".png"), link = x)
}
opts_chunk$set(warning=F,message=F,echo=F)
opts_knit$set(upload.fun=email_image)
'.
$this->user_data .
'```
'.
		$source;
		
		$results = $this->knit($source,'',false,'"smartypants","highlight_code","mathjax"');
		
		$available = explode("\n",$results);
		
		$response = array();
		$response['images'] = array();
		
		foreach($available AS $part):
			$upto = strpos($part,'/files/figure/');
			if($upto!==false):
				$image_id = preg_replace("/[^a-zA-Z0-9]/",'',substr($part,$upto+14)) . '.png';
				$response['images'][ $image_id ] =  $this->instance. $part;
			endif;
		endforeach;
		
		$session = explode('/',$available[0]);
		$session = '/'.$session[1].'/'.$session[2] .'/'.$session[3] . '/';
		// info/text stdout/text console/text R/.val/text
		
		if(in_array($session . 'R/.val',$available))
			$response['body'] = current( json_decode(file_get_contents($this->instance. $session . 'R/.val/json')) );
		
		return $response;
	}
	public function debugCall($results)
	{
		$header = substr($results, 0, $this->header_size);
		$results = substr($results, $this->header_size);
##		list($header, $results) = explode("\r\n\r\n", $results, 2); # does not work with 100 Continue
		if($this->http_status > 302):
			 $response = array(
				 'Response' => '<pre>'. htmlspecialchars($results). '</pre>',
				 'HTTP headers' => '<pre>'. htmlspecialchars($header). '</pre>',
			 );
		else:
			$header_parsed = http_parse_headers($header);
			$available = explode("\n",$results);

			$session = '/ocpu/tmp/'. $header_parsed['X-ocpu-session'] . '/';
#			$session = explode('/',$available[0]);
#			$session = '/'.$session[1].'/'.$session[2] .'/'.$session[3] . '/';
			// info/text stdout/text console/text R/.val/text
			
			$response = array();
			if(in_array($session . 'R/.val',$available))
				$response['Result'] = file_get_contents($this->instance. $session . 'R/.val/text');

			if(in_array($session . 'console',$available))
				$response['Console'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'console/print')).'</pre>';
			if(in_array($session . 'stdout',$available))
			
				$response['Stdout'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'stdout/print')). '</pre>';
			
			$response['HTTP headers'] = '<pre>'. htmlspecialchars($header). '</pre>';
			
			if(in_array($session . 'info',$available))
				$response['Session info'] = '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'info/print')). '</pre>';
		endif;
		
		return $this->ArrayToAccordion($response);
	}
	private function ArrayToAccordion($array)
	{
		$acc = '<div class="accordion" id="opencpu_accordion">';
		$first = ' in';
		foreach($array AS $title => $content):
			if($content == null) $content = stringBool($content);
			$acc .= '
<div class="accordion-group">
	<div class="accordion-heading">
		<a class="accordion-toggle" data-toggle="collapse" data-parent="#opencpu_accordion" href="#collapse'.str_replace(' ', '', $title).'">
			'.$title.'
		</a>
	</div>
	<div id="collapse'.str_replace(' ', '', $title).'" class="accordion-body collapse'.$first.'">
		<div class="accordion-inner">
			'.$content.'
		</div>
	</div>
</div>';
			$first = '';
		endforeach;
		$acc .= '</div>';
		return $acc;
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
