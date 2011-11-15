<?php

class AsyncHttpClient {

    /**
     * HTTP request methods
     */
    const GET     = 'GET';
    const POST    = 'POST';
    const PUT     = 'PUT';
    const DELETE  = 'DELETE';

    /**
     * HTTP protocol versions
     */
    const HTTP_1 = '1.1';
    const HTTP_0 = '1.0';

    /**
     * Content attributes
     */
    const CONTENT_TYPE   = 'Content-Type';
    const CONTENT_LENGTH = 'Content-Length';

    /**
     * POST data encoding methods
     */
    const ENC_URLENCODED = 'application/x-www-form-urlencoded';
    const ENC_FORMDATA   = 'multipart/form-data';

    protected $uri = null;
    protected $headers = array();
    protected $method = self::GET;
    protected $paramsGet = array();
    protected $paramsPost = array();
    protected $enctype = null;
    protected $timeout = 3;
    protected $response = null;
    protected $callback = null;
    protected $files = array();

    public $config = array(
        'eventbase' => null,
    );

    public function __construct($uri = null, $config = null) {
        if ($uri !== null) {
            $this->setUri($uri);
        }

        if ($config !== null) {
            $this->setConfig($config);
        }
    }

    /**
     * Set configuration parameters for this HTTP client
     *
     * @param  array $config
     * @throws InvalidArgumentException
     */
    public function setConfig($config = array()) {
        if (!is_array($config)) {
            throw new InvalidArgumentException('Array expected, got ' . gettype($config));
        }

        foreach ($config as $k => $v) {
            $this->config[strtolower($k)] = $v;
        }

        return $this;
    }

    public function setUri($uri) {
        $this->uri = $uri;
        return $this;
    }

    public function setMethod($method = self::GET) {
        if ($method == self::POST && $this->enctype === null) {
            $this->setEncType(self::ENC_URLENCODED);
        }

        $this->method = $method;
        return $this;
    }

    public function setEncType($enctype = self::ENC_URLENCODED) {
        $this->enctype = $enctype;
        return $this;
    }

    public function setParameterGet($name, $value = null) {
        if (is_array($name)) {
            foreach ($name as $k => $v)
                $this->_setParameter('GET', $k, $v);
        } else {
            $this->_setParameter('GET', $name, $value);
        }

        return $this;
    }

    public function setHeader($key, $value) {
        $this->headers[$key] = $value;
    }

    public function setHeaders(array $headers) {
        $this->headers = $headers;
    }

    public function setParameterPost($name, $value = null) {
        if (is_array($name)) {
            foreach ($name as $k => $v)
                $this->_setParameter('POST', $k, $v);
        } else {
            $this->_setParameter('POST', $name, $value);
        }

        return $this;
    }

    protected function _setParameter($type, $name, $value) {
        $parray = array();
        $type = strtolower($type);
        switch ($type) {
            case 'get':
                $parray = &$this->paramsGet;
                break;
            case 'post':
                $parray = &$this->paramsPost;
                break;
        }

        if ($value === null) {
            if (isset($parray[$name])) unset($parray[$name]);
        } else {
            $parray[$name] = $value;
        }
    }

    public function request($callback = null) {
        $port = isset($tmp['port']) ? $tmp['port'] : 80;
        $socket = stream_socket_client("$tmp[host]:$port", $errno, $errstr, 
            $this->timeout, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT); 
        stream_set_blocking($socket, 0);

        $base = $this->config['eventbase'];

        $writeEvent = event_new();
        event_set($writeEvent, $socket, EV_WRITE, 
            array($this, 'onAccept'), array($writeEvent, $base));
        event_base_set($writeEvent, $base);
        event_add($writeEvent);

        $readEvent = event_new();
        event_set($readEvent, $socket, EV_READ | EV_PERSIST, 
            array($this, 'onRead'), array($readEvent, $base));
        event_base_set($readEvent, $base);
        event_add($readEvent);

        if(!empty($callback))
            $this->callback = $callback;
    }

    public function onAccept($socket, $event, $args) {
        $body = $this->_prepareBody();
        $headers = $this->_prepareHeaders();

        $url = isset($tmp['query']) ? "$tmp[path]?$tmp[query]" : $tmp['path'];
        $out = "$this->method $url HTTP/1.1\r\n";
        $out .= "Host: $tmp[host]\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($socket, $out);
    }

    public function onRead($socket, $event, $args) {
        while($chunk = fread($socket, 4096)) {
            $this->response.= $chunk;
        }

        if(feof($socket)) {
            fclose($socket);
            event_del($args[0]);
            if($this->callback) {
                call_user_func($this->callback, array(
                    'response' => $this->response,
                ));
            }
        }
    }

    protected function _prepareBody() {
        $mbIntEnc = mb_internal_encoding();
        mb_internal_encoding('ASCII');
        $body = '';

        // If we have files to upload, force enctype to multipart/form-data
        if (count ($this->files) > 0) {
            $this->setEncType(self::ENC_FORMDATA);
        }

        // If we have POST parameters or files, encode and add them to the body
        if (count($this->paramsPost) > 0 || count($this->files) > 0) {
            switch($this->enctype) {
                case self::ENC_FORMDATA:
                    // Encode body as multipart/form-data
                    $boundary = '---ASYNCHTTPCLIENT-' . md5(microtime());
                    $this->setHeader(self::CONTENT_TYPE, self::ENC_FORMDATA . "; boundary={$boundary}");

                    // Get POST parameters and encode them
                    $params = self::_flattenParametersArray($this->paramsPost);
                    foreach ($params as $pp) {
                        $body .= self::encodeFormData($boundary, $pp[0], $pp[1]);
                    }

                    // Encode files
                    foreach ($this->files as $file) {
                        $fhead = array(self::CONTENT_TYPE => $file['ctype']);
                        $body .= self::encodeFormData($boundary, $file['formname'], $file['data'], $file['filename'], $fhead);
                    }

                    $body .= "--{$boundary}--\r\n";
                    break;

                case self::ENC_URLENCODED:
                    // Encode body as application/x-www-form-urlencoded
                    $this->setHeader(self::CONTENT_TYPE, self::ENC_URLENCODED);
                    $body = http_build_query($this->paramsPost, '', '&');
                    break;

                default:
                    throw new InvalidArgumentException("Cannot handle content type '{$this->enctype}' automatically." .
                    break;
            }
        }

        // Set the Content-Length if we have a body or if request is POST/PUT
        if ($body || $this->method == self::POST || $this->method == self::PUT) {
            $this->setHeader(self::CONTENT_LENGTH, strlen($body));
        }

        if (isset($mbIntEnc)) {
            mb_internal_encoding($mbIntEnc);
        }

        return $body;
    }

    protected function _prepareHeaders() {
        $headers = array();
        $uriInfo = parse_url($this->uri);
        $uriInfo['port'] = isset($uriInfo['port']) ? 
            $uriInfo['port'] : ($uriInfo['scheme'] == 'https' ? 443 : 80);
        $host = $uriInfo['host'];

        // If the port is not default, add it
        if (!(($uriInfo['scheme'] == 'http' && $uriInfo['port'] == 80) ||
              ($uriInfo['scheme'] == 'https' && $uriInfo['port'] == 443))) {
            $host .= ':' . $uriInfo['port'];
        }
        $headers[] = "Host: {$host}";
        $headers[] = "Connection: close";
        $headers[] = function_exists('gzinflate') ? 
            'Accept-encoding: gzip, deflate' : 'Accept-encoding: identity';

        if ($this->method == self::POST && isset($this->enctype)) {
            $headers[] = self::CONTENT_TYPE . ': ' . $this->enctype;
        }

        foreach ($this->headers as $header) {
            list($name, $value) = $header;
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $headers[] = "$name: $value";
        }

        return $headers;
    }

    static protected function _flattenParametersArray($parray, $prefix = null) {
        if (! is_array($parray)) {
            return $parray;
        }

        $parameters = array();

        foreach($parray as $name => $value) {

            // Calculate array key
            if ($prefix) {
                if (is_int($name)) {
                    $key = $prefix . '[]';
                } else {
                    $key = $prefix . "[$name]";
                }
            } else {
                $key = $name;
            }

            if (is_array($value)) {
                $parameters = array_merge($parameters, self::_flattenParametersArray($value, $key));

            } else {
                $parameters[] = array($key, $value);
            }
        }

        return $parameters;
    }

    public static function encodeFormData($boundary, $name, $value, $filename = null, $headers = array()) {
        $ret = "--{$boundary}\r\n" .
            'Content-Disposition: form-data; name="' . $name .'"';

        if ($filename) {
            $ret .= '; filename="' . $filename . '"';
        }
        $ret .= "\r\n";

        foreach ($headers as $hname => $hvalue) {
            $ret .= "{$hname}: {$hvalue}\r\n";
        }
        $ret .= "\r\n";

        $ret .= "{$value}\r\n";

        return $ret;
    }

}
