<?php
class OpenCPU {
	private $instance;
	private $user_data = '';
	private $curl_c;
	private $http_status = null;
	public function __construct($instance)
	{
		$this->instance = $instance;
		$this->curl_c = curl_init();
#		curl_setopt($this->curl_c, CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
		curl_setopt($this->curl_c, CURLOPT_POST, 1); // Method is "POST"
		curl_setopt($this->curl_c, CURLOPT_RETURNTRANSFER, 1); // Returns the curl_exec string, rather than just Logical value
	}

	public function r_function($function,$post,$headers = false)
	{
		curl_setopt($this->curl_c, CURLOPT_URL, $this->instance.'/ocpu/library/'.$function);
		
		if($post !== null):
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
	
	private function identity($post, $return = '/json',$headers = false)
	{
		return $this->r_function('base/R/identity'.$return, $post, $headers);
	}
	
	public function isTrue($source,$return = 'json',$headers = false)
	{
		$post = array('x' => '{ $source }');
		return $this->identity($post,$return,$headers);
	}
	
	public function knit($source,$return,$headers = false)
	{
		$post = array('x' => '{
library(knitr)
	knit2html(text = "' . addslashes($source) . '",
    fragment.only = T, options=c("base64_images","smartypants")
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

	public function selftest() 
	{
		$source = '{
			options(bitmapType = "Xlib");
		library(knitr); library(markdown); library(ggplot2)
			knit2html(text = "__Hello__ World `r 1`
			```{r}
			qplot(rnorm(10))
			```
			",
		    fragment.only = T, options=c("base64_images","smartypants")
		)
		}';
		$results = $this->identity(array('x' =>  $source),'', true);
		
		if($this->http_status > 302) $alert_type = 'alert-error';
		else $alert_type = 'alert-info';
		alert("HTTP status: ".$this->http_status,'alert-success');
		
		
		return $this->debugCall($results);
	}
	
	private function debugCall($results)
	{
		list($header, $results) = explode("\r\n\r\n", $results, 2);
		
		if($this->http_status > 302):
			 $response = array(
				 'Response' => '<pre>'. htmlspecialchars($results). '</pre>',
				 'HTTP headers' => '<pre>'. htmlspecialchars($header). '</pre>',
			 );
		else:
			list($first) = explode("\n",$results);
			
			$session = explode('/',$first);
			$session = '/'.$session[1].'/'.$session[2] .'/'.$session[3] . '/';
			// info/text stdout/text console/text R/.val/text
		
			 $response = array(
				 'Result' => file_get_contents($this->instance. $session . 'R/.val/text'),
				 'Console' => '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'console/text')).'</pre>',
				 'Stdout' => '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'stdout/text')). '</pre>',
				 'HTTP headers' => '<pre>'. htmlspecialchars($header). '</pre>',
				 'Session info' => '<pre>'. htmlspecialchars(file_get_contents($this->instance. $session . 'info/text')). '</pre>'
			 );
		endif;
		
		return $this->ArrayToAccordion($response);
	}
	private function ArrayToAccordion($array)
	{
		$acc = '<div class="accordion" id="opencpu_accordion">';
		$first = ' in';
		foreach($array AS $title => $content):
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
