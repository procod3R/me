<?php
/**
 * Copyright (C) 2012 Pim de Haan
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/**
 * The Pirate Bay Proxy class
 * Allows the user to easily create a proxy to The Pirate Bay.
 * Simple copy both the .htaccess and this file to any map in the webserver
 * and that's it.
 * 
 * Afterwards, please add your proxy to IKWILTHEPIRATEBAY.NL so that
 * people can find it.
 */
class Proxy
{
    /**
     *
     * @var curl_handle
     */
    protected $ch;
    
    /**
     * URI to add before relative urls as well as default URL
     * @var string
     */
    protected $prefix = 'http://thepiratebay.org';
    
    /**
     * Array of domains whose links will be routed through the proxy
     * @var array
     */
    protected $blockedDomains = array('thepiratebay.org');
    
    /**
     * Mime type of page
     * @var string
     */
    protected $pageType;
    
    /**
     * Callback for setting a specific banner. The DOM is given as argument
     * @var callback
     */
    protected $bannerCallback;
    
    public function __construct()
    {
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_HEADER, true);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Opera/9.23 (Windows NT 5.1; U; en)');
    }
    
    /**
     * @see $bannerCallback
     * @param calback $callback
     */
    public function setBannerCallback($callback)
    {
        if(!is_callable($callback))
            throw new InvalidArgumentException('Argument should be callable');
        $this->bannerCallback = $callback;
    }
    
    /**
     * Run
     * @param string $url
     * @param array $get $_GET global var
     * @param array $post $_POST global var
     * @return string Response 
     */
    public function run($url, $get, $post)
    {
        // Use default
        if(empty($url))
            $url = $this->prefix;
        // Decode
        else
            $url = $this->decodeUrl($url);
        
        // Apppend get params to request
        if($get) {
            $url .= '?'.http_build_query($get);
        }
        
        curl_setopt($this->ch, CURLOPT_URL, $url);
        
        // set optional post params
        if($post) {
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($this->ch, CURLOPT_POST, true);
        }
        
        // See below
        $return = $this->curlExecFollow($this->ch);
        
        // Throw exception on error
        if($return === false)
            throw new Exception($this->error());
        
        // Strip redirect headers
        $body = $return;
        while(strpos($body, 'HTTP') === 0) {
            list($header, $body) = explode("\r\n\r\n", $body, 2);
        }
        
        // Set response headers
        $this->setResponseHeaders($header);
               
        
        // Rewrite links according to page type
        if($this->pageType == 'text/html')
            $body = $this->rewriteHtml($body);
        elseif($this->pageType == 'text/css')
            $body = $this->rewriteLinksInCss($body);
        
        return $body;
    }
    
    protected function setResponseHeaders($header)
    {
        // Headers that should be mapped to client
        $mappedHeaders = array(
            'Set-Cookie',
            'Expires',
            'Last-Modified',
            'Cache-Control',
            'Content-Type',
            'Pragma'
        );
        
        // Parse headers
        $headers = $this->parseHeaders($header);
        foreach($headers as $name => $value) {
            // If header isn't mapped, don't set it
            if(!array_search($name, $mappedHeaders))
                continue;
            
            // Support for multiple values with same name
            if(is_array($value))
                foreach($value as $part)
                    header($name.': '.$part, false);
            else
                header($name.': '.$value);
        }
        // Set page type
        list($this->pageType) = explode(';', $headers['Content-Type']);
    }
    
    // Parse headers into array
    protected function parseHeaders($header)
    {
        $retVal = array();
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
        foreach( $fields as $field ) {
            if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
                $match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
                if( isset($retVal[$match[1]]) ) {
                    $retVal[$match[1]] = array($retVal[$match[1]], $match[2]);
                } else {
                    $retVal[$match[1]] = trim($match[2]);
                }
            }
        }
        return $retVal;
    }
    
    /**
     * Convert html for use in proxy
     * @param string $source HTML input
     * @return string HTML output 
     */
    protected function rewriteHtml($source)
    {
        // Use dom to easily find links
        $dom = new DOMDocument;
        @$dom->loadHTML($source);
        
        // Map field to attribute name
        $map = array(
            'form' =>'action',
            'a' => 'href',
            'img' => 'src',
            'script' => 'src',
            'link' => 'href'
        );
        
        // Rewrite each type
        foreach($map as $tagName => $attributeName) {
            // Use Xpath to find all nodes
            foreach($dom->getElementsByTagName($tagName) as $node) {
                // Rewrite attribute accordingly
                if($node->hasAttribute($attributeName)) {
                    $attribute = $node->getAttributeNode($attributeName);
                    $attribute->value = $this->rewriteLink($attribute->value);
                }
            }   
        }
        
        // Rewrite links in <style> tag
        foreach($dom->getElementsByTagName('style') as $node) {
            $node->nodeValue = $this->rewriteLinksInCss($node->nodeValue);
        }
        
        // Call bannerCallback to update DOM
        if(isset($this->bannerCallback)) {
            $callback = $this->bannerCallback;
            $dom = $callback($dom);
        }
        
        $html = $dom->saveHTML();
        
        // Allow this ajax call to be routed trough the proxy as well
        $html = str_replace("new Ajax.Updater('artistDetails', '/ajax_details_artinfo.php', {",
                "new Ajax.Updater('artistDetails', '".$this->rewriteLink('/ajax_details_artinfo.php')."', {", $html);
        return $html;
        
    }
    
    /**
     * Update links in (inline) css
     * @param string $css
     * @return string 
     */
    protected function rewriteLinksInCss($css)
    {
        // Match on url('x') in css
        return preg_replace_callback('#url\(\'?([^\'\)]+)\'?\)#', array($this, 'rewritleLinksInCssCallback'), $css);
    }
    
    /**
     * Helper function
     * @param array $matches
     * @return string 
     */
    protected function rewritleLinksInCssCallback($matches) {
        return "url('".$this->rewriteLink($matches[1])."')";
    }
    
    /**
     * Update url so that it routes trough the proxy
     * @param string $url
     * @return string 
     */
    public function rewriteLink($url)
    {
        // Make relative links absolute
        if(strpos($url, '/') === 0 && strpos($url, '/', 1) !== 1) {
            $url = $this->prefix . $url;
        // Add http: to protocol-relative links
        } elseif(strpos($url, '//') === 0) {
            $url = 'http:'.$url;
        }
        
        // Only rewrite blocked domains
        $host = parse_url($url, PHP_URL_HOST);
        foreach($this->blockedDomains as $domain) {
            if(strpos($host, $domain) !== false) {
                $url = $this->encodeUrl($url);
                break;
            }
        }
        
        return htmlentities($url);
    }
    
    /**
     *
     * @param string $url
     * @return string 
     */
    protected function encodeUrl($url)
    {
        return base64_encode($url);
    }    
    
    /**
     *
     * @param string $url
     * @return string 
     */
    protected function decodeUrl($url)
    {
        return base64_decode($url);
    }
    
    /**
     * Get error message
     * @return string 
     */
    protected function error()
    {
        return curl_error($this->ch);
    }
    
    /**
     * Allow redirects under safe mode
     * @param curl_handle $ch
     * @return string 
     */
    protected function curlExecFollow($ch)
    {
        $mr = 5; 
        if (ini_get('open_basedir') == '' && (ini_get('safe_mode') == 'Off' || ini_get('safe_mode') == '')) { 
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $mr > 0); 
            curl_setopt($ch, CURLOPT_MAXREDIRS, $mr); 
        } else { 
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 
            if ($mr > 0) { 
                $newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); 

                $rch = curl_copy_handle($ch); 
                curl_setopt($rch, CURLOPT_HEADER, true); 
                curl_setopt($rch, CURLOPT_NOBODY, true); 
                curl_setopt($rch, CURLOPT_FORBID_REUSE, false); 
                curl_setopt($rch, CURLOPT_RETURNTRANSFER, true); 
                do { 
                    if(strpos($newurl, '/') === 0)
                            $newurl = $this->prefix.$newurl;
                    
                    curl_setopt($rch, CURLOPT_URL, $newurl); 
                    $header = curl_exec($rch); 
                    if (curl_errno($rch)) { 
                        $code = 0; 
                    } else { 
                        $code = curl_getinfo($rch, CURLINFO_HTTP_CODE); 
                        if ($code == 301 || $code == 302) { 
                            preg_match('/Location:(.*?)\n/', $header, $matches); 
                            $newurl = str_replace(' ', '%20', trim(array_pop($matches)));
                        } else { 
                            $code = 0; 
                        } 
                    } 
                } while ($code && --$mr); 
                curl_close($rch); 
                if (!$mr) { 
                    if ($maxredirect === null) { 
                        trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING); 
                    } else { 
                        $maxredirect = 0; 
                    } 
                    return false; 
                } 
                curl_setopt($ch, CURLOPT_URL, $newurl); 
            } 
        } 
        return curl_exec($ch); 
    }
} 

try {
    // Use '' al default
    if(isset($_GET['url'])) {
        $url = $_GET['url'];
        unset($_GET['url']);
    } else {
        $url = '';
    }
    $proxy = new Proxy();
    echo $proxy->run($url, $_GET, $_POST);
} catch(Exception $e) {
    echo 'Error: '.$e->getMessage();
}
