<?php

class AsyncHttpClient extends Zend_Http_Client {

    protected $config = array(
        'maxredirects'    => 5,
        'strictredirects' => false,
        'useragent'       => 'AsyncHttpClient',
        'timeout'         => 10,
        'httpversion'     => self::HTTP_1,
        'keepalive'       => false,
        'storeresponse'   => true,
        'strict'          => true,
        'output_stream'   => false,
        'encodecookies'   => true,
        'eventbase'       => null,
        'callback'        => null,
    );

    public function request($method = null) {
        if (! $this->uri instanceof Zend_Uri_Http) {
            /** @see Zend_Http_Client_Exception */
            require_once 'Zend/Http/Client/Exception.php';
            throw new Zend_Http_Client_Exception('No valid URI has been passed to the client');
        }

        if ($method) {
            error_log('aaa');
            $this->setMethod($method);
        }

        $uri = $this->uri;
        $port = isset($tmp['port']) ? $tmp['port'] : 80;
        $host = $uri->getHost();
        $port = $uri->getPort();
        $socket = stream_socket_client(
            "$host:$port", 
            $errno, 
            $errstr, 
            (int) $this->config['timeout'],
             STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
         ); 
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
        $uri = clone $this->uri;
        if (!empty($this->paramsGet)) {
            $query = $uri->getQuery();
            if (!empty($query)) {
                $query .= '&';
            }
            $query .= http_build_query($this->paramsGet, null, '&');

            $uri->setQuery($query);
        }

        $body = $this->_prepareBody();
        $headers = $this->_prepareHeaders();

        $host = $uri->getHost();
        $host = (strtolower($uri->getScheme()) == 'https' ? 
            $this->config['ssltransport'] : 'tcp') . '://' . $host;

        $httpVer = '1.1';
        $path = $uri->getPath();
        if ($uri->getQuery()) $path .= '?' . $uri->getQuery();
        $request = "{$this->method} {$path} HTTP/{$httpVer}\r\n";
        foreach ($headers as $k => $v) {
            if (is_string($k)) $v = ucfirst($k) . ": $v";
            $request .= "$v\r\n";
        }

        if(is_resource($body)) {
            $request .= "\r\n";
        } else {
            // Add the request body
            $request .= "\r\n" . $body;
        }

        if(!fwrite($socket, $request)) {
            require_once 'Zend/Http/Client/Adapter/Exception.php';
            throw new Zend_Http_Client_Adapter_Exception('Error writing request to server');
        }
    }

    public function onRead($socket, $event, $args) {
        while($chunk = fread($socket, 4096)) {
            $this->response.= $chunk;
        }

        if(feof($socket)) {
            fclose($socket);
            event_del($args[0]);
            if($this->config['callback']) {
                call_user_func($this->config['callback'], array(
                    'response' => $this->response,
                ));
            }
        }
    }

}
