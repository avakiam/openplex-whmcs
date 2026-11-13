<?php

namespace OpenPlex\Whmcs;

/**
 * An error answer from the OpenPlex API: its HTTP status, stable code and
 * message.
 */
class ApiError extends \RuntimeException
{
    public $status;
    public $errorCode;

    public function __construct($status, $errorCode, $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

/**
 * A small client for the OpenPlex REST API (/api/v1), authenticated with an
 * API key.
 */
class Client
{
    private $base;
    private $key;
    private $log;
    private $timeout;

    /**
     * @param callable|null $log called with the request (method, path, body)
     *                           and the raw answer; never with the key
     */
    public function __construct($host, $port, $secure, $key, $log = null, $timeout = 60)
    {
        $scheme = $secure ? 'https' : 'http';
        $default = $secure ? 443 : 80;
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']'; // IPv6 address
        }
        $this->base = $scheme . '://' . $host . ((int) $port === $default ? '' : ':' . (int) $port);
        $this->key = $key;
        $this->log = $log;
        $this->timeout = $timeout;
    }

    /** The panel's address, without a trailing slash. */
    public function panelUrl()
    {
        return $this->base;
    }

    public function get($path)
    {
        return $this->request('GET', $path);
    }

    public function post($path, $body = null)
    {
        return $this->request('POST', $path, $body);
    }

    public function patch($path, $body)
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete($path)
    {
        return $this->request('DELETE', $path);
    }

    public function request($method, $path, $body = null)
    {
        $ch = curl_init($this->base . '/api/v1' . $path);
        $headers = ['Authorization: Bearer ' . $this->key, 'Accept: application/json'];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($this->log) {
            call_user_func($this->log, ['method' => $method, 'path' => $path, 'body' => $this->redact($body)], $raw === false ? $curlError : $this->redactAnswer($raw));
        }
        if ($raw === false) {
            throw new ApiError(0, 'unreachable', 'Could not reach OpenPlex at ' . $this->base . ': ' . $curlError);
        }
        $data = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 400 || $status === 0) {
            $code = is_array($data) && isset($data['error']) ? $data['error'] : 'http_' . $status;
            $message = is_array($data) && isset($data['message']) ? $data['message'] : 'HTTP ' . $status;
            if ($status === 401) {
                $message = 'OpenPlex refused the API key (' . $message . ')';
            }
            throw new ApiError($status, $code, $message);
        }
        return $data;
    }

    private function redact($body)
    {
        if (is_array($body) && isset($body['password'])) {
            $body['password'] = '********';
        }
        return $body;
    }

    /** Sign-in links in answers are credentials too: keep them out of the log. */
    private function redactAnswer($raw)
    {
        return preg_replace('/(link=)[A-Za-z0-9_\-]+/', '$1********', $raw);
    }
}
