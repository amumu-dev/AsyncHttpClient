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
            $this->setMethod($method);
        }

        $uri = clone $this->uri;
        if (! empty($this->paramsGet)) {
            $query = $uri->getQuery();
               if (! empty($query)) {
                   $query .= '&';
               }
            $query .= http_build_query($this->paramsGet, null, '&');

            $uri->setQuery($query);
        }

        $body = $this->_prepareBody();
        $headers = $this->_prepareHeaders();

        $port = isset($tmp['port']) ? $tmp['port'] : 80;
        $host = $uri->getHost();
        $port = $uri->getPort();
        $socket = stream_socket_client("$host:$port", $errno, $errstr, 
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

}
