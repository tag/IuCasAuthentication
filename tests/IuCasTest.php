<?php
/**
 *
 * @author Tom Gregory <tomgreg@iu.edu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
 
namespace IuCas\IuCasAuthentication\Tests;

use PHPUnit\Framework\TestCase;
use IuCas\IuCasAuthentication;

// use GuzzleHttp\Client;
// use GuzzleHttp\Psr7\Response;
// use GuzzleHttp\Tests\Server;

class IuCasTest extends TestCase
{
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
            
            foreach ($pids as $pid) {
                $pid = (int)$pid;
                if (posix_getpgid($pid)) { // Check if pid is still active
                    shell_exec('kill -9 ' . $pid);
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
        $this->env = array_intersect_key (PHP_MAJOR_VERSION < 7 ? $_ENV : getenv(), [
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
        
    public function testBaseConfiguration()
    {
        
        $this->assertSame('CAS_USER', $this->cas->getSessionVar());
        $this->assertSame('https://cas.iu.edu/cas/logout', $this->cas->getLogoutUrl());
        
        $url = $this->cas->getCasLoginUrl();
          // https://cas.iu.edu/cas/login?cassvc=IU&casurl=https%3A%2F%2Flocalhost%3A8123%2Ftest
        
        $this->assertSame('https://cas.iu.edu/cas/login', strstr($url, '?', true));

        $url = parse_url($url);
        $vars = [];
        parse_str($url['query'], $vars);
        $this->assertSame('IU', $vars['cassvc']);
        
        //$this->assertSame($this->localUrl, rawurldecode($vars['casurl']));


        $url = $this->cas->getCasValidationUrl();
          // https://cas.iu.edu/cas/validate?cassvc=IU&casurl=http%3A%2F%2Flocalhost%3A8123%2Ftest&casticket=
        
        $this->assertSame('https://cas.iu.edu/cas/validate', strstr($url, '?', true));
        
        // putenv('CAS_USER', 'u');
        // $this->assertSame('u', $cas->getSessionVar());
        //
        //
        // $this->assertSame('CAS_LOGOUT_URL', $cas->getLogoutUrl());
        // $this->assertSame('CAS_LOGIN_URL', $cas->getCasLogintUrl());
        // $this->assertSame('CAS_VALIDATION_URL', $cas->getCasValidationUrl());
    }
    
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
        
    }
    
    /**
     * @runInSeparateProcess
     */
    public function testAuthenticateWithNoTokenCausesRedirect()
    {
        // $arr = headers_list(); // Will always be empty, as PHPUnit runs from CLI
        // $this->assertEmpty($arr);
        $this->assertEmpty($this->cas->getCasTicket());
        
        $this->cas->setExit(function() {throw new \UnexpectedValueException('', http_response_code());});
        
        try {
            $this->cas->authenticate(); // Should redirect (to CAS) and exit
            $this->assertTrue(false);
        }
        catch (\UnexpectedValueException $uve) {
            $this->assertEquals("303", $uve->getCode());
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

        $this->cas->setExit(function() {throw new \UnexpectedValueException('', http_response_code());});

        // If exception is thrown, test should fail
        $this->cas->authenticate(); // Success expected, should set $_SESSION['CAS_USER']
        
        $this->assertSame($expectedUser, $this->cas->getUserName());

        // Test #authenticate() with callbacks
        $this->cas->authenticate(
            function() {  // Failure callback
                $this->fail('Authentication was supposed to be successful.');
            },
            function() {  // Success callback
                $this->assertTrue(true);
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

        $this->cas->setExit(function() {throw new \UnexpectedValueException('', http_response_code());});

        try {
            $this->cas->authenticate();
            $this->fail('Authentication was supposed to fail.');
        } catch(\UnexpectedValueException $uve) {
            $this->assertEquals("401", $uve->getCode());
        }

        // Test #authenticate() with callbacks
        $this->cas->authenticate(
            function() {  // Failure callback
                $this->assertTrue(true);
            },
            function() {  // Success callback
                $this->fail('Authentication was supposed to fail.');
            }
        );
        
        // Cleanup
        putenv("CAS_VALIDATION_URL");
    }
}
