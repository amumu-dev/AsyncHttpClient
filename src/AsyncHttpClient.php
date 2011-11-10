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
    protected $uriInfo = array();
    protected $headers = array();
    protected $method = self::GET;
    protected $paramsGet = array();
    protected $paramsPost = array();
    protected $enctype = null;
    protected $timeout = 3;
    protected $finishWrite = false;

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
    public function setConfig($config = array())
    {
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
        $this->uriInfo = parse_url($this->uri);
        return $this;
    }

    public function request() {
        $tmp = $this->uriInfo;
        $port = isset($tmp['port']) ? $tmp['port'] : 80;
        print_r($tmp);print_r("$tmp[host]:$port\n");
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
    }

    public function onAccept($socket, $event, $args) {
        $tmp = $this->uriInfo;
        $url = isset($tmp['query']) ? "$tmp[path]?$tmp[query]" : $tmp['path'];
        $out = "$this->method $url HTTP/1.1\r\n";
        $out .= "Host: $tmp[host]\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($socket, $out);
    }

    public function onRead($socket, $event, $args) {
        while($chunk = fread($socket, 4096)) {
            echo $chunk;
        }

        if(feof($socket)) {
            fclose($socket);
        }
    }

}
