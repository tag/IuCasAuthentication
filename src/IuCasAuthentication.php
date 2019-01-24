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
 * * `CAS_LOGIN_URL` defaults to `'https://cas.iu.edu/cas/login'`
 * * `CAS_VALIDATION_URL` defaults to `'https://cas.iu.edu/cas/validate'`
 * * `CAS_LOGOUT_URL` defaults to `'https://cas.iu.edu/cas/logout'`
 */

namespace IuCas;

class IuCasAuthentication
{
    protected $service;
    protected $redirectUrl;
    
    protected $logger;
    
    protected $userName;
    
    /**
     * An overridable callable, so exit on failed auth/redirect can be tested.
     */
    protected $exitCallable;
    
    /**
     * @param string $redirect URL for your application that should be redirected to for validation after
     *        authentication. Must be the same during authentication and validation steps.
     * @param string $service (optional) Must be one of the [CAS application codes at IU](https://kb.iu.edu/d/alqm).
     *        Defaults to 'IU'
     * @param LoggerInterface $logger (optional) A reference to a PSR-3 logger (such as Monolog).
     *        Used to log validation errors, if available.
     */
    public function __construct($redirect = '', $service = "IU", $logger = null)
    {
        
        $this->service = $service;
        $this->redirectUrl = $redirect ? $redirect : $this->getCurrentUrl();
        
        $this->setLogger($logger ? $logger : null);
        
        $this->setExit(function () {
            exit;
        }); // Default. May be overridden easily
    }
    
    /**
     * @param string $redirect URL that authentication should redirect to after authentication.
     *                         Must be the same during authentication and validation steps.
     *
     */
    public function getService()
    {
        return $this->service;
    }
    
    public function getRedirectUrl()
    {
        return $this->redirectUrl;
    }
    
    public function setRedirectUrl($url)
    {
        $this->redirectUrl = $url;
    }
    
    public function getCasLoginUrl()
    {
        $url = getenv('CAS_LOGIN_URL');
        $url = $url ? $url : 'https://cas.iu.edu/cas/login';
        
        return $url
            . "?cassvc={$this->service}&casurl="
            . rawurlencode($this->redirectUrl);
    }
    
    public function getCasTicket()
    {
        return isset($_GET['casticket']) ? $_GET['casticket'] : '';
    }
    
    public function getCasValidationUrl()
    {
        // Validation **will** fail if $_GET['casticket'] is empty.
        // A CAS ticket may be used only once.
        $base = getenv('CAS_VALIDATION_URL');
        $base = $base ? $base : 'https://cas.iu.edu/cas/validate';
        
        return $base
            . "?cassvc={$this->service}&casurl="
            . rawurlencode($this->redirectUrl)
            . "&casticket=" . $this->getCasTicket();
    }
    
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * Validates a CAS login. Validation may not be repeated, as a validation ticket is good only one time.
     * A ticket issued by CAS is valid for only two seconds.
     *
     * @param int $timeout (optional) Number of seconds to wait before the validation check times out.
     *                     Set to zero for no timeout
     * @return string|null Returns CAS username, or null if validation failed.
     */
    public function validate($timeout = 5)
    {
        // CAS sends response on 2 lines. First line contains "yes" or "no".
        // If "yes", second line contains username (otherwise, it is empty).
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->getCasValidationUrl());
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
        ob_start();
        curl_exec($curl);

        if ($errNum = curl_errno($curl)) {
            if ($this->logger) {
                $this->logger->critical(__CLASS__ . ': ' . curl_err($curl));
                
                $this->logger->critical(__CLASS__ . ": Validation request to {$this->casValidationUrl} timed out.");
            }
        }

        curl_close($curl);
        $casAnswer = ob_get_contents();
        ob_end_clean();

        // CAS answer on first line, CAS username (if any) on second line
        $result = explode("\n", $casAnswer, 2);
        
        // CAS sends extra whitespace, so must be trimmed
        if (count($result) < 2) {
            return null;
        }
        return trim($result[0]) === "yes" ? trim($result[1]) : null;
    }
    
    public function getLogoutUrl()
    {
        $url = getenv('CAS_LOGOUT_URL');
        return $url ? $url : 'https://cas.iu.edu/cas/logout';
    }
    
    public function getCurrentUrl()
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
    
    public function getSessionVar()
    {
        $var = getenv('CAS_SESSION_VAR');
        return $var ? $var : 'CAS_USER';
    }
    
    public function getUserName()
    {
        if ($this->userName) {
            return $this->userName;
        }
        
        $var = $this->getSessionVar();
        $this->userName = isset($_SESSION[$var]) ? $_SESSION[$var] : null;
        return $this->userName;
    }
    
    public function setUserName($name)
    {
        $this->userName = $name;
        
        $var = $this->getSessionVar();
        $_SESSION[$var] = $name;
    }
    
    /**
     *
     * @param callable|string|bool $onFailure Any of a) a URL to redirect to and exit on failure,
     *        b) a callable on failure (call passes current URL), c) a `bool` value. If a boolean is passed,
     *        sets a "401 Unauthorized" header and exits if `true`. (Defaults to `true`.)
     * @param callable|string|bool $onSuccess Any of a) a URL to redirect to and exit on success,
     *        b) a callable on success (call passes user name and current URL), c) a `bool` value.
     *        If a boolean is passed, sets a `$_SESSION` variable if `true`, and takes no action if `false`.
     *        (Defaults to `true`.)
     * @throws InvalidArgumentException
     * @return mixed
     */
    public function authenticate($onFailure = true, $onSuccess = true)
    {
        $success = false;
        if ($this->getUserName()) {
            $success = true;
        } elseif ($this->getCasTicket()) { // Have been to CAS, have ticket
            $success = $this->validate();
        } else { // Must go to CAS to authenticate
            header('Location: ' . $this->getCasLoginUrl(), true, 303);
            $this->doExit();
            return; // In case doExit() has been overridden
        }
        
        if ($success) {
            //$this->userName = $success;
            if (is_bool($onSuccess)) {
                if ($onSuccess) {
                    $this->setUserName($success); // Also sets session variable
                }
                return;
            } elseif (is_callable($onSuccess)) {
                return call_user_func($onSuccess, $this->userName, $this->getCurrentUrl());
            } elseif (is_string($onSuccess)) {
                header('Location: '.$onSuccess, true, 303);
                $this->doExit();
                return; // In case doExit() has been overridden
            }
            throw new InvalidArgumentException(__CLASS__.'#authenticate() received a malformed onSuccess parameter');
        }
        
        // i.e., not $success
        if (is_bool($onFailure)) {
            header("HTTP/1.1 401 Unauthorized");
            if ($onFailure) {
                $this->doExit();
            }
            return;
        } elseif (is_callable($onFailure)) {
            return call_user_func($onFailure, $this->getCurrentUrl());
        } elseif (is_string($onFailure)) {
            header("HTTP/1.1 401 Unauthorized");
            header('Location: '.$onFailure, true, 303);
            $this->doExit();
            return; // In case doExit() has been overridden
        }
        throw new InvalidArgumentException(__CLASS__.'#authenticate() received a malformed onFailure parameter');
    }
    
    public function logout()
    {
        $this->setUserName(null);
    }
    
    public function doExit() // In php5.6 may not be named `exit()`?!
    {
        call_user_func($this->exitCallable);
    }
    
    public function setExit(callable $callable)
    {
        $this->exitCallable = $callable;
    }
}
