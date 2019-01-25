<?php

/**
 * @author  Tom Gregory <tomgreg@iu.edu>
 * @license MIT-style
 *
 * Designed to provide a simple set of CAS helper functions for the specific implementation of
 * CAS at Indiana University.
 *
 * The three necessary URLs (login, validation, logout) as described by https://kb.iu.edu/d/atfc are
 * included by default but may be overridden through the use of the following optional environment variables.
 *
 * * `CAS_LOGIN_URL` defaults to ``
 * * `CAS_VALIDATION_URL` defaults to `'https://cas.iu.edu/cas/validate'`
 * * `CAS_LOGOUT_URL` defaults to `'https://cas.iu.edu/cas/logout'`
 *
 * Two additional environment variables are used:
 *
 * * `CAS_SESSION_VAR` defaults to `'CAS_USER'`, therefore the user name of the active user is available at
 *   `$_SESSION[getenv('CAS_SESSION_VAR')]`. Current value is returned by `#getSessionVar()`. The session variable is
 *    used by `#getUserName()` and `#setUserName()`, which in turn are used by `#logout()` and the default success
 *    handler for `#authenticate()` to store the user name response from CAS in `$_SESSION`.
 * * `CAS_TIMEOUT` Sets the timeout of service requests to CAS validation and logout services. Defaults to `5`.
 */

namespace IuCas;

class IuCasAuthentication
{
    public const CAS_DEFAULT_TIMEOUT = 5; // Preference given to CAS_TIMEOUT environment var
    
    public const CAS_LOGIN_URL = 'https://cas.iu.edu/cas/login'; // Preference given to environment var
    public const CAS_VALIDATION_URL = 'https://cas.iu.edu/cas/validate'; // Preference given to environment var
    public const CAS_LOGOUT_URL = 'https://cas.iu.edu/cas/logout'; // Preference given to environment var
    
    public const CAS_SESSION_VAR = 'CAS_USER';
    
    protected $timeout;
    
    /**
     * @var string Immutable after creation
     */
    protected $service = 'IU';
    
    /**
     * @var string
     */
    protected $redirectUrl;
    
    /**
     * @var null|\Psr\Log\LoggerInterface Reference to a PSR-4 Logger
     */
    protected $logger = null;
    
    /**
     * @var null|string
     */
    protected $userName = null;
    
    /**
     * @param string $redirect URL for your application that should be redirected to for validation after
     *        authentication. Must be the same during authentication and validation steps.
     * @param string $service (optional) Must be one of the [CAS application codes at IU](https://kb.iu.edu/d/alqm).
     *        Defaults to 'IU'
     * @param \Psr\Log\LoggerInterface $logger (optional) A reference to a PSR-3 logger (such as Monolog).
     *        Used to log validation errors, if available.
     */
    public function __construct(?string $redirect = null, string $service = 'IU', $logger = null)
    {
        $this->service = $service;
        $this->redirectUrl = $redirect ?? $this->getCurrentUrl();
        
        $this->setLogger($logger);
    }
    
    /**
     * Validates a CAS login. Validation may not be repeated, as a validation ticket is good only one time.
     * A ticket issued by CAS is valid for only two seconds.
     *
     * @param int $timeout (optional) Number of seconds to wait before the validation check times out.
     *                     Set to zero for no timeout
     * @return string|null Returns CAS username, or null if validation failed.
     */
    public function validate(?int $timeout = null) : ?string
    {
        
        // CAS sends response on 2 lines. First line contains "yes" or "no".
        // If "yes", second line contains username (otherwise, it is empty).
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->getCasValidationUrl());
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout ?? $this->getTimeout());
        ob_start();
        curl_exec($curl);

        if ($errNum = curl_errno($curl)) {
            if ($this->logger) {
                $this->logger->critical(__CLASS__ . ': ' . curl_error($curl));
                
                $this->logger->critical(__CLASS__ . ": Validation request {$this->getCasValidationUrl()} timed out.");
            }
        }

        curl_close($curl);
        $casAnswer = (string) ob_get_contents();
        ob_end_clean();

        // CAS answer on first line, CAS username (if any) on second line
        $result = explode("\n", $casAnswer, 2);
        
        // CAS sends extra whitespace, so must be trimmed
        if (count($result) < 2) {
            return null;
        }
        return trim($result[0]) === "yes" ? trim($result[1]) : null;
    }
    
    /**
     *
     * @param ?callable $onFailure On failure callback (passes current URL)
     * @param ?callable $onSuccess On success callback (passes user name and current URL)
     * @return mixed
     */
    public function authenticate(callable $onFailure = null, callable $onSuccess = null, callable $onLogin = null)
    {
        $onFailure = $onFailure ?? [$this, 'defaultOnFailure'];
        $onSuccess = $onSuccess ?? [$this, 'defaultOnSuccess'];
        $onLogin = $onLogin ?? [$this, 'defaultOnLogin'];
        
        $user = $this->getUserName();
        if ($user === null && $this->getCasTicket()) { // Have been to CAS, have ticket
            $user = $this->validate();
        } else {
            return call_user_func($onLogin); // Must redirect to CAS to authenticate; overridable for testing purposes
        }
        
        if ($user) {
            return call_user_func($onSuccess, $user, $this->getCurrentUrl());
        }
        
        return call_user_func($onFailure, $this->getCurrentUrl());
    }
    
    /**
     * Clears the session variable set by this class, and sends a request to CAS to logout.
     *
     * @return bool Whether the CAS remote call was successful.
     */
    public function logout(?int $timeout = null) : bool
    {
        $this->setUserName(null);

        $curl = curl_init();
        $url = $this->getLogoutUrl();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout ?? $this->getTimeout());
        ob_start();
        $success = curl_exec($curl);

        if ($errNum = curl_errno($curl)) {
            if ($this->logger) {
                $this->logger->critical(__CLASS__ . ': ' . curl_error($curl));
                $this->logger->critical(__CLASS__ . ": Logout request to {$url} timed out.");
            }
        }

        curl_close($curl);
        ob_end_clean();
        
        return (bool) $success;
    }
    
    // ---------------------
    //   Default handlers
    // ---------------------
    
    /**
     * @codeCoverageIgnore
     */
    protected function defaultOnFailure(string $url) : void
    {
        header("HTTP/1.1 401 Unauthorized");
        exit;
    }
    
    /**
     * @codeCoverageIgnore
     */
    protected function defaultOnSuccess(string $userName, string $currentUrl) : void
    {
        $this->setUserName($userName);
    }
    
    /**
     * Redirects to CAS login
     *
     * @codeCoverageIgnore
     */
    protected function defaultOnLogin() : void
    {
        header('Location: ' . $this->getCasLoginUrl(), true, 303);
        exit;
    }
    
    // ---------------------
    //   Getters and Setters
    // ---------------------
    
    public function getCasLoginUrl() : string
    {
        $env = getenv('CAS_LOGIN_URL');
        $url = $env ? $env : self::CAS_LOGIN_URL;
        
        return $url
            . "?cassvc={$this->service}&casurl="
            . rawurlencode($this->redirectUrl);
    }
    
    public function getCasTicket() : string
    {
        return $_GET['casticket'] ?? '';
    }
    
    public function getCasValidationUrl() : string
    {
        // Validation **will** fail if $_GET['casticket'] is empty.
        // A CAS ticket may be used only once.
        $base = getenv('CAS_VALIDATION_URL');
        $base = $base ? $base : self::CAS_VALIDATION_URL ;
        
        return $base
            . "?cassvc={$this->service}&casurl="
            . rawurlencode($this->redirectUrl)
            . "&casticket=" . $this->getCasTicket();
    }
    
    public function getCurrentUrl() : string
    {
        $url = 'http';
        $isHttps = empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off' ? false : true;
        
        $url .= ($isHttps ? 's' : '') .'://' . $_SERVER["HTTP_HOST"];
        
        if (($isHttps && $_SERVER["SERVER_PORT"] != '443')
           || (!$isHttps && $_SERVER["SERVER_PORT"] != '80')) {
            $url .= ":".$_SERVER["SERVER_PORT"];
        }
        $url .= $_SERVER["REQUEST_URI"];

        return $url;
    }
    
    public function setLogger($logger) : void
    {
        $this->logger = $logger;
    }
    
    public function getLogoutUrl() : string
    {
        $env = getenv('CAS_LOGOUT_URL');
        return  $env ? $env : self::CAS_LOGOUT_URL;
    }
    
    /**
     * @return string $redirect URL that authentication should redirect to after authentication.
     *        Must be the same during authentication and validation steps.
     *
     */
    public function getRedirectUrl() : string
    {
        return $this->redirectUrl;
    }
    
    /**
     * @param string $url URL that authentication should redirect to after authentication.
     *        Must be the same during authentication and validation steps.
     *
     */
    public function setRedirectUrl(string $url) : void
    {
        $this->redirectUrl = $url;
    }
    
    public function getService() : string
    {
        return $this->service;
    }
    
    public function getSessionVar() : string
    {
        $env = getenv('CAS_SESSION_VAR');
        return $env ? $env : self::CAS_SESSION_VAR;
    }
    
    public function getTimeout() : int
    {
        $env = getenv('CAS_TIMEOUT');
        return $env ? (int) $env : self::CAS_DEFAULT_TIMEOUT;
    }
    
    public function getUserName() : ?string
    {
        return $this->userName ?? $_SESSION[ $this->getSessionVar() ] ?? null;
    }
    
    public function setUserName(?string $name) : void
    {
        $this->userName = $name;
        if ($name === null) {
            unset($_SESSION[ $this->getSessionVar() ]);
        } else {
            $_SESSION[ $this->getSessionVar() ] = $name;
        }
    }
}
