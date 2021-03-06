<?php

namespace Oseintow\Shopify;

use GuzzleHttp\Client;
use function GuzzleHttp\Psr7\parse_header;
use Oseintow\Shopify\Exceptions\ShopifyApiException;
use Oseintow\Shopify\Exceptions\ShopifyApiResourceNotFoundException;

class Shopify
{
    protected $key;
    protected $secret;
    protected $shopDomain;
    protected $accessToken;
    protected $requestHeaders = [];
    protected $responseHeaders = [];
    protected $responseHeaderLinks = [];
    protected $client;
    protected $responseStatusCode;
    protected $reasonPhrase;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->key = config('shopify.key');
        $this->secret = config('shopify.secret');
    }

    public function setShopUrl($shopUrl)
    {
        $url = parse_url($shopUrl);
        $this->shopDomain = isset($url['host']) ? $url['host'] : $this->removeProtocol($shopUrl);

        return $this;
    }

    private function baseUrl()
    {
        return "https://{$this->shopDomain}/";
    }

    // Get the URL required to request authorization
    public function getAuthorizeUrl($scope = [] || '', $redirect_url='',$nonce='')
    {
        if (is_array($scope)) $scope = implode(",", $scope);

        $url = "https://{$this->shopDomain}/admin/oauth/authorize?client_id={$this->key}&scope=" . urlencode($scope);

        if ($redirect_url != '') $url .= "&redirect_uri=" . urlencode($redirect_url);

        if ($nonce!='') $url .= "&state=" . urlencode($nonce);

        return $url;
    }

    public function getAccessToken($code)
    {
        $uri = "admin/oauth/access_token";
        $payload = ["client_id" => $this->key, 'client_secret' => $this->secret, 'code' => $code];
        $response = $this->makeRequest('POST', $uri, $payload);

        return $response ?? '';
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function setKey($key)
    {
        $this->key = $key;

        return $this;
    }

    public function setSecret($secret)
    {
        $this->secret = $secret;

        return $this;
    }

    private function setXShopifyAccessToken()
    {
        return ['X-Shopify-Access-Token' => $this->accessToken];
    }

    public function addHeader($key, $value)
    {
        $this->requestHeaders = array_merge($this->requestHeaders, [$key => $value]);

        return $this;
    }

    public function removeHeaders()
    {
        $this->requestHeaders = [];

        return $this;
    }

    /*
     *  $args[0] is for route uri and $args[1] is either request body or query strings
     */
    public function __call($method, $args)
    {
        list($uri, $params) = [ltrim($args[0],"/"), $args[1] ?? []];

        if(substr($uri,0, 4) !== 'http'){
            $uri = $this->baseUrl() . $uri;
        }

        $response = $this->makeRequest($method, $uri, $params, $this->setXShopifyAccessToken());

        return (is_array($response)) ? $this->convertResponseToCollection($response) : $response;
    }

    private function convertResponseToCollection($response)
    {
        return collect(json_decode(json_encode($response)));
    }

    private function makeRequest($method, $uri, $params = [], $headers = [])
    {
        $query = in_array($method, ['get','delete']) ? "query" : "json";

        if($this->shouldRateLimit()) {
            sleep(5);
        }

        do{
            $response = $this->client->request(strtoupper($method), $uri, [
                'headers' => array_merge($headers, $this->requestHeaders),
                $query => $params,
                'timeout' => 120.0,
                'connect_timeout' => 120.0,
                'http_errors' => false,
                "verify" => false
            ]);

            $this->parseResponse($response);
            $responseBody = $this->responseBody($response);

        } while ($this->wasThrottled($response));

        if (isset($responseBody['errors']) || $response->getStatusCode() >= 400){
            $errors = is_array($responseBody['errors'])
                ? json_encode($responseBody['errors'])
                : $responseBody['errors'];

            if($response->getStatusCode()  == 404) {
                throw new ShopifyApiResourceNotFoundException(
                    $errors ?? $response->getReasonPhrase(),
                    $response->getStatusCode()
                );
            }

            throw new ShopifyApiException(
                $errors ?? $response->getReasonPhrase(),
                $response->getStatusCode()
            );
        }

        return (is_array($responseBody) && (count($responseBody) > 0))
            ? array_shift($responseBody)
            : $responseBody;
    }

    private function parseResponse($response)
    {
        $this->parseHeaders($response->getHeaders());
        $this->setStatusCode($response->getStatusCode());
        $this->setReasonPhrase($response->getReasonPhrase());

    }

    private function shouldRateLimit() : bool
    {
        if($this->hasHeader('HTTP_X_SHOPIFY_SHOP_API_CALL_LIMIT')){
            $rateLimit = $this->getHeader('HTTP_X_SHOPIFY_SHOP_API_CALL_LIMIT');
            $limit = explode('/', $rateLimit);
            $callsRemaining = $limit[1] - $limit[0];
            if($callsRemaining < 5) return true;
        }
        return false;
    }

    private function wasThrottled($response) : bool
    {
        if($response->getStatusCode() === 429){
            $seconds = (int) ($response->hasHeader('RETRY_AFTER') ? $response->getHeader('RETRY_AFTER') : 10);
            sleep($seconds);
            return true;
        }
        return false;
    }

    public function verifyRequest($queryParams)
    {
        if (is_string($queryParams)) {
            $data = [];

            $queryParams = explode('&', $queryParams);
            foreach($queryParams as $queryParam)
            {
                list($key, $value) = explode('=', $queryParam);
                $data[$key] = urldecode($value);
            }

            $queryParams = $data;
        }

        $hmac = $queryParams['hmac'] ?? '';

        unset($queryParams['signature'], $queryParams['hmac']);

        ksort($queryParams);

        $params = collect($queryParams)->map(function($value, $key){
            $key   = strtr($key, ['&' => '%26', '%' => '%25', '=' => '%3D']);
            $value = strtr($value, ['&' => '%26', '%' => '%25']);

            return $key . '=' . $value;
        })->implode("&");

        $calculatedHmac = hash_hmac('sha256', $params, $this->secret);

        return hash_equals($hmac, $calculatedHmac);
    }

    public function verifyWebHook($data, $hmacHeader)
    {
        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $this->secret, true));

        return ($hmacHeader == $calculatedHmac);
    }

    private function setStatusCode($code)
    {
        $this->responseStatusCode = $code;
    }

    public function getStatusCode()
    {
        return $this->responseStatusCode;
    }

    private function setReasonPhrase($message)
    {
        $this->reasonPhrase = $message;
    }

    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }

    private function parseHeaders($headers)
    {
        foreach ($headers as $name => $values) {
            $this->responseHeaders = array_merge($this->responseHeaders, [$name => implode(', ', $values)]);
        }

        $this->parseHeaderLinks();
    }

    public function getHeaders()
    {
        return $this->responseHeaders;
    }

    public function getHeader($header)
    {
        return $this->hasHeader($header) ? $this->responseHeaders[$header] : '';
    }

    public function hasHeader($header)
    {
        return array_key_exists($header, $this->responseHeaders);
    }

    public function parseHeaderLinks()
    {
        if($this->hasHeader('Link')){
            $this->responseHeaderLinks =  parse_header($this->getHeader('Link'));
        }
    }

    public function getNextPage()
    {
        return $this->makeLinkRequest('next');
    }

    public function getPreviousPage()
    {
        return $this->makeLinkRequest('previous');
    }

    private function makeLinkRequest(string $rel = next)
    {
        if($link = $this->searchLink($rel)){
            $link = substr($link[0], 1, -1);

            $parts = parse_url($link);
            parse_str($parts['query'], $params);

            $response = $this->makeRequest(
                'get',
                $link,
                $params,
                $this->setXShopifyAccessToken()
            );

            return (is_array($response))
                ? $this->convertResponseToCollection($response)
                : $response;
        }

        return false;
    }

    private function searchLink(string $relType)
    {
        $index = array_search(
            $relType,
            array_column($this->responseHeaderLinks, 'rel'));

        if($index !== false){
            return $this->responseHeaderLinks[$index];
        }

        return false;
    }

    private function responseBody($response)
    {
        return json_decode($response->getBody(), true);
    }

    public function removeProtocol($url)
    {
        $disallowed = ['http://', 'https://','http//','ftp://','ftps://'];
        foreach ($disallowed as $d) {
            if (strpos($url, $d) === 0) {
                return str_replace($d, '', $url);
            }
        }

        return $url;
    }

}
