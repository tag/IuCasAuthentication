<?php

/**
 * @author  Tom Gregory <tomgreg@iu.edu>
 * @license MIT-style
 *
 * Designed to provide a simple set of CAS helper functions for the specific implementation of CAS at Indiana University.
 *
 * The three necessary URLs (login, validation, logout) as described by https://kb.iu.edu/d/atfc are included by default
 * but may be overridden through the use of the following optional environment variables.
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
    
    protected $casLoginUrl;
    protected $casValidationUrl;
    
    protected $logger;
    
    /**
     * @param string $redirect URL for your application that should be redirected to for validation after authentication.
     *                         Must be the same during authentication and validation steps.
     * @param string $service (optional) Must be one of the [CAS application codes at IU](https://kb.iu.edu/d/alqm). Defaults to 'IU'
     * @param LoggerInterface $logger (optional) A reference to a PSR-3 logger (such as Monolog). Used to log validation errors, if available.
     *
     */
    public function __construct($redirect, $service="IU", $logger=null) {
        $url = getenv('CAS_LOGIN_URL');
        $this->casLoginUrl = $url ? $url : 'https://cas.iu.edu/cas/login';
        
        $url = getenv('CAS_VALIDATION_URL');
        $this->casValidationUrl = $url ? $url : 'https://cas.iu.edu/cas/validate';
        
        $this->service = $service;
        $this->redirectUrl = $redirect;
        
        $this->setLogger($logger ? $logger : null);
    }
    
    /**
     * @param string $redirect URL that authentication should redirect to after authentication.
     *                         Must be the same during authentication and validation steps.
     *
     */
    public function getService() {
        return $this->service;
    }
    
    public function getRedirectUrl() {
        return $this->redirectUrl;
    }
    
    public function setRedirectUrl($url) {
        $this->redirectUrl = $url;
    }
    
    public function getCasLoginUrl() {
        return $this->casLoginUrl
            . "?cassvc={$this->service}&casurl="
            . rawurlencode($this->redirectUrl);
    }
    
    public function getCasTicket() {
        return isset($_GET['casticket']}) ? $_GET['casticket']} : '';
    }
    
    public function getCasValidationUrl() {
        // Validation **will** fail if $_GET['casticket'] is empty.
        // A CAS ticket may be used only once.
        return $this->casValidationUrl
            . "?cassvc={$this->service}&casurl="
            . rawurlencode($this->redirectUrl)
            . "&casticket=" . $this->getCasTicket();
    }
    
    public function setLogger($logger) {
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
    public function validate($timeout = 5) {
        // CAS sends response on 2 lines. First line contains "yes" or "no".
        // If "yes", second line contains username (otherwise, it is empty).
        $curl = curl_init();
        curl_setopt ($curl, CURLOPT_URL, $this->getCasValidationUrl());
        curl_setopt ($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
        ob_start();
        curl_exec($curl);

        if ($errNum = curl_errno($curl)) {
            if ($this->logger) {
                $this->logger-critical(__CLASS__ . ': ' . curl_err($curl));
                
                $this->logger-critical(__CLASS__ . ": Validation request to {$this->casValidationUrl} timed out.");
            }
        }

        curl_close($curl);
        $casAnswer = ob_get_contents();
        ob_end_clean();
        
        // CAS answer on first line, CAS username (if any) on second line
        $result = explode("\n", $casAnswer, 2);
        
        // CAS sends extra whitespace, so must be trimmed
        return count($result) === 2 && trim($result[0]) === "yes" : trim($result[1]) : null;
    }
    
    public function getLogoutUrl() {
        $url = getenv('CAS_LOGOUT_URL');
        return $url ? $url : 'https://cas.iu.edu/cas/logout';
    }
}

