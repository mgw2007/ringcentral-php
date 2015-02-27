<?php

namespace RC\platform;

use Exception;
use RC\http\Request;
use RC\http\Response;
use stdClass;

class Platform
{

    const ACCESS_TOKEN_TTL = 600; // 10 minutes
    const REFRESH_TOKEN_TTL = 36000; // 10 hours
    const REFRESH_TOKEN_TTL_REMEMBER = 604800; // 1 week
    const ACCOUNT_PREFIX = '/account/';
    const ACCOUNT_ID = '~';
    const TOKEN_ENDPOINT = '/restapi/oauth/token';
    const REVOKE_ENDPOINT = '/restapi/oauth/revoke';
    const API_VERSION = 'v1.0';
    const URL_PREFIX = '/restapi';

    protected $server = '';
    protected $appKey = '';
    protected $appSecret = '';
    protected $account = self::ACCOUNT_ID;

    /** @var Auth */
    protected $auth = null;

    public function __construct($appKey, $appSecret, $server = '')
    {

        $this->auth = new Auth();

        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->server = $server;

    }

    /**
     * @param stdClass $authData
     * @return $this
     */
    public function setAuthData(stdClass $authData = null)
    {
        $this->auth->setData($authData);
        return $this;
    }

    /**
     * @return stdClass
     */
    public function getAuthData()
    {
        return $this->auth->getData();
    }

    public function isAuthorized($refresh = true)
    {

        if (!$this->auth->isAccessTokenValid()) {
            if ($refresh) {
                print 'Refresh is required' . PHP_EOL;
                $this->refresh();
            }
        }

        if (!$this->auth->isAccessTokenValid()) {
            throw new Exception('Access token is not valid after refresh timeout');
        }

        return $this;

    }

    protected function getApiKey()
    {
        return base64_encode($this->appKey . ':' . $this->appSecret);
    }

    protected function getAuthHeader()
    {
        return $this->auth->getTokenType() . ' ' . $this->auth->getAccessToken();
    }

    /**
     * @param string $url
     * @param array  $options
     * @return string
     */
    public function apiUrl($url = '', $options = [])
    {

        $builtUrl = '';

        if ($options['addServer'] && !stristr($url, 'http://') && !stristr($url, 'https://')) {
            $builtUrl .= $this->server;
        }

        if (!stristr($url, self::URL_PREFIX)) {
            $builtUrl .= self::URL_PREFIX . '/' . self::API_VERSION;
        }

        if (stristr($url, self::ACCOUNT_PREFIX)) {
            $builtUrl = str_replace(self::ACCOUNT_PREFIX . self::ACCOUNT_ID, self::ACCOUNT_PREFIX . $this->account,
                $builtUrl);
        }

        $builtUrl .= $url;

        if (!empty($options['addMethod']) || !empty($options['addToken'])) {
            $builtUrl .= (stristr($url, '?') ? '&' : '?');
        }

        if (!empty($options['addMethod'])) {
            $builtUrl .= '_method=' . $options['addMethod'];
        }
        if (!empty($options['addToken'])) {
            $builtUrl .= ($options['addMethod'] ? '&' : '') . 'access_token=' . $this->auth->getAccessToken();
        }

        return $builtUrl;

    }

    /**
     * @param string $username
     * @param string $extension
     * @param string $password
     * @param bool   $remember
     * @return Response
     * @throws Exception
     */
    public function authorize($username = '', $extension = '', $password = '', $remember = false)
    {

        $response = $this->authCall(new Request(Request::POST, self::TOKEN_ENDPOINT, null, [
            'grant_type'        => 'password',
            'username'          => $username,
            'extension'         => $extension ? $extension : null,
            'password'          => $password,
            'access_token_ttl'  => self::ACCESS_TOKEN_TTL,
            'refresh_token_ttl' => $remember ? self::REFRESH_TOKEN_TTL_REMEMBER : self::REFRESH_TOKEN_TTL
        ]));

        $this->auth
            ->setData($response->getData())
            ->resume()
            ->setRemember($remember);

        return $response;

    }

    /**
     * @return Response
     * @throws Exception
     */
    public function refresh()
    {

        if (!$this->auth->isPaused()) {

            print 'Refresh will be performed' . PHP_EOL;

            $this->auth->pause();

            if (!$this->auth->isRefreshTokenValid()) {
                throw new Exception('Refresh token has expired');
            }

            $response = $this->authCall(new Request(Request::POST, self::TOKEN_ENDPOINT, null, [
                "grant_type"        => "refresh_token",
                "refresh_token"     => $this->auth->getRefreshToken(),
                "access_token_ttl"  => self::ACCESS_TOKEN_TTL,
                "refresh_token_ttl" => $this->auth->isRemember() ? self::REFRESH_TOKEN_TTL_REMEMBER : self::REFRESH_TOKEN_TTL
            ]));

            $this->auth
                ->setData($response->getData())
                ->resume();

            return $response;

        } else {

            while ($this->auth->isPaused()) {
                print 'Waiting for refresh' . PHP_EOL;
                sleep(1);
            }

            $this->isAuthorized(false); // will throw Exception if not authorized

            return null; //TODO Recover last successful refresh

        }

    }

    /**
     * @return Response
     * @throws Exception
     */
    public function logout()
    {

        $response = $this->authCall(new Request(Request::POST, self::REVOKE_ENDPOINT, [
            'token' => $this->auth->getAccessToken()
        ]));

        $this->auth->reset();

        return $response;

    }

    /**
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    protected function apiCall(Request $request)
    {

        $this->isAuthorized();

        return $request
            ->setHeader(Request::AUTHORIZATION, $this->getAuthHeader())
            ->setUrl($this->apiUrl($request->getUrl(), ['addServer' => true]))
            ->send();

    }

    /**
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    protected function authCall(Request $request)
    {

        return $request
            ->setHeader(Request::AUTHORIZATION, 'Basic ' . $this->getApiKey())
            ->setHeader(Request::CONTENT_TYPE, Request::URL_ENCODED_CONTENT_TYPE)
            ->setUrl($this->apiUrl($request->getUrl(), ['addServer' => true]))
            ->setMethod(Request::POST)
            ->send();

    }

    /**
     * @param string $url
     * @param array  $queryParameters
     * @param array  $headers
     * @return Response
     */
    public function get($url = '', array $queryParameters = null, array $headers = null)
    {
        return $this
            ->apiCall(new Request(Request::GET, $url, $queryParameters, null, $headers));
    }

    /**
     * @param string $url
     * @param array  $queryParameters
     * @param array  $body
     * @param array  $headers
     * @return Response
     */
    public function post($url = '', array $queryParameters = null, $body = null, array $headers = null)
    {
        return $this->apiCall(new Request(Request::POST, $url, $queryParameters, $body, $headers));
    }

    /**
     * @param string $url
     * @param array  $queryParameters
     * @param array  $body
     * @param array  $headers
     * @return Response
     */
    public function put($url = '', array $queryParameters = null, $body = null, array $headers = null)
    {
        return $this->apiCall(new Request(Request::PUT, $url, $queryParameters, $body, $headers));
    }

    /**
     * @param string $url
     * @param array  $queryParameters
     * @param array  $body
     * @param array  $headers
     * @return Response
     */
    public function delete($url = '', array $queryParameters = null, $body = null, array $headers = null)
    {
        return $this->apiCall(new Request(Request::DELETE, $url, $queryParameters, $body, $headers));
    }

}