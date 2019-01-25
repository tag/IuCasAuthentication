<?php
/**
 *
 * @author Tom Gregory <tomgreg@iu.edu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
 
namespace IuCas\Test;

use PHPUnit\Framework\TestCase;
use IuCas\IuCasAuthentication;

class IuCasTest extends TestCase
{
    // https://stackoverflow.com/questions/9370927/how-to-preserve-session-through-all-tests-on-phpunit
    protected $backupGlobalsBlacklist = array('_SESSION');
    
    
    protected $cas = null;
    protected $env;

    protected static $testServer = '127.0.0.1:8812';
    protected static $pidFile = __DIR__ . '/server/.pidfile';
    
    /**
     * @runInSeparateProcess
     */
    public static function setUpBeforeClass()
    {
        chdir (__DIR__.'/server');
        
        $cmd = "php -S ".self::$testServer." index.php";

        shell_exec("{$cmd} > /dev/null 2>&1 & echo $! >> ".self::$pidFile);

        sleep(1);
    }
   
    public static function tearDownAfterClass()
    {
        if (file_exists(self::$pidFile)) {
            $pids = file(self::$pidFile);
            
            if ($pids) {
                foreach ($pids as $pid) {
                    $pid = (int)$pid;
                    if (posix_getpgid($pid)) { // Check if pid is still active
                        shell_exec('kill -9 ' . $pid);
                    }
                }
            }
            unlink(self::$pidFile);
        }
    }

    public function setUp()
    {
        // These reference the client URL that CAS should redirect to; there is no listener here
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '8123';
        $_SERVER['REQUEST_URI'] = '/test';
        
        $this->cas = new IuCasAuthentication();
        
        //Unset any environment variables, if present
        $this->env = array_intersect_key (getenv(), [
            'CAS_LOGIN_URL' => false,       // defaults to 'https://cas.iu.edu/cas/login'
            'CAS_VALIDATION_URL' => false, // defaults to 'https://cas.iu.edu/cas/validate'
            'CAS_LOGOUT_URL' => false,     // defaults to 'https://cas.iu.edu/cas/logout'
            'CAS_SESSION_VAR' => false     // defaults to 'CAS_USER'
        ]);

        foreach ($this->env as $key=>$val) {
            putenv($key); // unsets
        }
    }
    
    public function tearDown()
    {
        foreach ($this->env as $key=>$val) {
            putenv("{$key}={$val}"); // re-sets
        }
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testBaseConfiguration()
    {
        $this->assertSame('CAS_USER', $this->cas->getSessionVar());
        $this->assertSame(IuCasAuthentication::CAS_LOGOUT_URL, $this->cas->getLogoutUrl());
        
        $url = $this->cas->getCasLoginUrl();
        
        $this->assertSame(IuCasAuthentication::CAS_LOGIN_URL, strstr($url, '?', true));

        $url = parse_url($url);
        $vars = [];
        if (!is_array($url) || !isset($url['query'])) {
            $this->fail('Malformed url.');
        } else {
            parse_str($url['query'], $vars);
            $this->assertSame('IU', $vars['cassvc']);
        }

        $url = $this->cas->getCasValidationUrl();
        
        $this->assertSame(IuCasAuthentication::CAS_VALIDATION_URL, strstr($url, '?', true));
        
        $this->assertSame(IuCasAuthentication::CAS_SESSION_VAR, $this->cas->getSessionVar());
        
        $this->assertSame(IuCasAuthentication::CAS_DEFAULT_TIMEOUT, $this->cas->getTimeout());
        
        // putenv('CAS_USER', 'u');
        // $this->assertSame('u', $cas->getSessionVar());
        //
        //
        // $this->assertSame('CAS_LOGOUT_URL', $cas->getLogoutUrl());
        // $this->assertSame('CAS_LOGIN_URL', $cas->getCasLogintUrl());
        // $this->assertSame('CAS_VALIDATION_URL', $cas->getCasValidationUrl());
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testCustomConfiguration()
    {
        
        putenv('CAS_SESSION_VAR=TEST_CAS_USER');
        $this->assertSame('TEST_CAS_USER', $this->cas->getSessionVar());
        putenv('CAS_SESSION_VAR');
        
        // Logout
        $dummyUrl = "http://".self::$testServer."/logout";
        putenv("CAS_LOGOUT_URL={$dummyUrl}");
        $this->assertSame($dummyUrl, $this->cas->getLogoutUrl());
        putenv('CAS_LOGOUT_URL');
        
        // Login
        $dummyUrl = "http://".self::$testServer."/login";
        putenv("CAS_LOGIN_URL={$dummyUrl}");
           // https://cas.iu.edu/cas/login?cassvc=IU&casurl=https%3A%2F%2Flocalhost%3A8123%2Ftest
        $url = $this->cas->getCasLoginUrl();
        $this->assertSame($dummyUrl, strstr($url, '?', true));
        putenv('CAS_LOGIN_URL');
        
        $temp = 'usr';
        putenv("CAS_SESSION_VAR={$temp}");
        $this->assertSame($temp, $this->cas->getSessionVar());
        putenv("CAS_SESSION_VAR");
        
        $temp = '1';
        putenv("CAS_TIMEOUT={$temp}");
        $this->assertEquals($temp, $this->cas->getTimeout());
        
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testDetectCasTicket()
    {
        $this->assertSame('', $this->cas->getCasTicket());
        $_GET['casticket'] = 'foo';
        $this->assertSame('foo', $this->cas->getCasTicket());
        unset($_GET['casticket']);
        $this->assertSame('', $this->cas->getCasTicket());
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testDetectOrSetSessionUser()
    {
        $this->assertFalse(isset($_SESSION[ $this->cas->getSessionVar() ]));
        $this->assertSame(null, $this->cas->getUserName());
        
        $temp = "bob";
        $this->cas->setUserName($temp);
        $this->assertSame($temp, $_SESSION[ $this->cas->getSessionVar() ]);
        $this->assertSame($temp, $this->cas->getUserName());
        
        $this->cas->setUserName(null);
        $this->assertFalse(isset($_SESSION[ $this->cas->getSessionVar() ]));
        $this->assertSame(null, $this->cas->getUserName());
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testAuthenticateWithNoTokenCausesRedirect()
    {
        // $arr = headers_list(); // Will always be empty, as PHPUnit runs from CLI
        // $this->assertEmpty($arr);
        $this->assertEmpty($this->cas->getCasTicket());
        
        try {
            $this->cas->authenticate(
                function () { // onFailure
                    $this->fail('Authentication was not supposed to fail.');
                },
                function () { // onSuccess
                    $this->fail('Authentication was not supposed to succeed.');
                },
                function () { // onLogin
                    throw new \UnexpectedValueException('', (int) http_response_code());
                }
            );
        }
        catch (\UnexpectedValueException $uve) {
            $this->assertTrue(true);
        }
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testAuthenticationWithSuccessfulValidation()
    {
        $dummyTicket = 'phpunit';
        $expectedUser = 'test_user'; // should match what's sent by server/validate/yes (the test URL handled by index.php)

        $_GET['casticket'] = $dummyTicket;

        $this->assertSame($dummyTicket, $this->cas->getCasTicket());

        putenv("CAS_VALIDATION_URL=http://".self::$testServer."/validate/yes");

        // Test #authenticate() with callbacks
        $this->cas->authenticate(
            function() {  // Failure callback
                $this->fail('Authentication was supposed to be successful.');
            },
            function($user) use ($expectedUser) {  // Success callback
                $this->assertSame($expectedUser, $user);
                $this->assertTrue(true);
            },
            function() {  // Login callback
                $this->fail('Authentication was not supposed to redirect to CAS.');
            }
        );
        
        
        
        // Cleanup
        putenv("CAS_VALIDATION_URL");
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testAuthenticationWithFailedValidation()
    {
        $_GET['casticket'] = 'badticket';

        putenv("CAS_VALIDATION_URL=http://".self::$testServer."/validate/no");

        $this->cas->authenticate(
            function() {  // Failure callback
                $this->assertTrue(true);
            },
            function() {  // Success callback
                $this->fail('Authentication was supposed to fail.');
            },
            function() {  // Login callback
                $this->fail('Authentication was not supposed to redirect to CAS.');
            }
        );
        
        // Cleanup
        putenv("CAS_VALIDATION_URL");
    }
}
