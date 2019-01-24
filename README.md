![Travis-CI build status](https://travis-ci.com/tag/IuCasAuthentication.svg?branch=master)

# IuCasAuthentication
A CAS authentication object that works with CAS @ IU. Using [CAS at IU](https://kb.iu.edu/d/atfc) isn't complicated, and UITS even provides some [sample code for PHP](https://kb.iu.edu/d/bfru). This helper class aims to make the process even easier.

Follows PSR-4 and PSR-2 standards. Tested on PHP v5.6 and PHP v7.1.

## Installation

The preferred method of installation is with [Composer](https://getcomposer.org).

### Install with Composer
Add the following to your `composer.json` file:
```
"tag/iu-cas": ">=0.3"
```

Or, install the Composer package:
```
composer require tag/iu-cas
```

### Install without Composer

Download the `IuCasAuthentication` class, and include or require it on your page(s).

## Using `IuCasAuthentication`

1. Create a new authentication object.

   * The first is the URL authentication should redirect to. Must be the same during authentication and validation steps. Defaults to the current URL.
   * An optional second argument must be one of the [CAS application codes at IU](https://kb.iu.edu/d/alqm). Defaults to 'IU'.
   * An optional third argument is a reference to a [PSR-3][psr3] logger (such as Monolog). Used to log validation errors, if available. Defaults to `null``.

2. Use the authentication object to get the CAS authentication URL. As applications and frameworks differ, you will need to handle the redirect to the authentcation URL yourself.

3. When your application's authentication redirect URL is loaded, re-create an authentication object and call its `validate()` method. This method will return the CAS username on success or `null` on failure.

You should only authenticate once per session, and store a successful authentication. Do not authenticate with every page refresh.

[psr3]: https://www.php-fig.org/psr/psr-3/

### Simplest method
Let the `IuCasAuthentication` helper do all of the work for you. Requires `$_SESSION` to currently be writable. Stores the CAS username in `$_SESSION['CAS_USER]`.

This code would need to be called on every page you wish protected.

```php
<?php
// File: any.php

$casHelper = new \IuCas\IuCasAuthentication();
$casHelper->authenticate(); // Default behavior is 401 and die on failure.
                            // Pass a URL to redirect on failure instead; see documentation for other options

// Continue processing file as normal

// After authentication, user name is available
$userName = $casHelper->getUserName(); // Stored in $_SESSION, retrieved from $_SESSION by `IuCasAuthentication`
```

The `authenticate()` method accepts zero to two parameters.

1. On Failure: Any of a) a URL to redirect to and exit on failure, b) a callable on failure, c) a `bool` value. If a boolean is passed, sets a "401 Unauthorized" header and exits if `true`. (Defaults to `true`.)
2. On Success: Any of a) a URL to redirect to and exit on success, b) a callable on success, c) a `bool` value. If a boolean is passed, sets a `$_SESSION['CAS_USER']` if `true`, and takes no action if `false`. (Defaults to `true`.)

If a [callable](http://php.net/manual/en/language.types.callable.php) is passed, the results of the callable are returned.

### Recommended method

Rather than relying on the built-in behavior, pass explicit callback functions for authentication failure or success. Apply this code to each page you wish protected.

```php
$casHelper = new \IuCas\IuCasAuthentication();

$casHelper->authenticate(
        function () {
            http_response_code(401);     // Unauthorized
            header('Location: /public'); // Failure, go to page that doesn't require authentication
            exit;
        },
        function ($username) {
            // Success, store the user
            $casHelper->setUserName($success); // Sets to $_SESSION['CAS_USER']; key may be overridden via environment
        }
    );
```

### Manage login and validation manually
Although the `#authenticate()` function does all of the work for you, it's possible to manage the authentication process manually if you wish.

```php
$casHelper = new \IuCas\IuCasAuthentication();

if (!$casHelper->getUserName()) {

    if (!$casHelper->getCasTicket()) {
        header('Location: ' . $casHelper->getCasLoginUrl(), true, 303);
        exit;
    } else {
        $result = $casHelper->validate();
        if ($result) { // Success, logged in
            $_SESSION['username'] = $result;
        } else { // Failure; Return HTTP status code 401 Unauthorized
            header("HTTP/1.1 401 Unauthorized");
            // Add optional redirect here
            exit;
        }
    }
}

// Continue processing file as normal
```

### Example with login and validation on different pages in your app

You might use this example code to only implement CAS authentication at a specific URL. Instead of calling `authenticate()`, you might do the following:

```php
<?php
// File: login.php

$appUrl = 'http://localhost/login-validate';
$casHelper = new \IuCas\IuCasAuthentication($appUrl);

header('Location: ' . $casHelper->getCasLoginUrl(), true, 303);
exit;
```

```php
<?php
// File: login-validate.php

// Remember, your application URL in validation must match the one passed in the authentication step
$appUrl = 'http://localhost/login-validate';
$casHelper = new \IuCas\IuCasAuthentication($appUrl);

$result = $casHelper->validate();

if ($result) {
    // Success, logged in
    $_SESSION['username'] = $result;
    header('Location: /', true, 303);
} else {
    // Failure; Redirect to logout to clear session
    header('Location: /logout', true, 303);
}

exit;
```


### Slim Framework example

The following code would work with the [Slim Framework](http://www.slimframework.com). This example also uses a [PSR-3][psr3] logger and anonymous callback functions. The login functionality would be better implemented as Middleware, but this example highlights some features of the `IuCasAuthentication` helper.

```php
$app->get('/login', function (Request $request, Response $response) {
    $appUrl = 'https://localhost/login-validate';
    $casHelper = new \IuCas\IuCasAuthentication($appUrl);
    
    return $response->withRedirect($casHelper->getCasLoginUrl(), 303);
});

$app->get('/login-validate', function (Request $request, Response $response) {
    // Remember, your application URL in validation must match the one passed in the authentication step
    $appUrl = 'https://localhost/login-validate';
    $casHelper = new \IuCas\IuCasAuthentication($appUrl);
    $casHelper->setLogger($this->get('logger'));
    
    return $casHelper->authenticate(
        function () use (&$response) {
            return $response->withRedirect('/logout', 401); // Failure, go to page that doesn't require authentication
        },
        function ($username) use (&$response) {
            // Do other session-setting stuff here, if desired
            return $response->withRedirect('/home', 303); // Success, logged in
        }
    );
});

```

## Logging

You may pass an optional PSR-3 logger as the third parameter of the constructor. If the validation step fails, details are sent to the logger, if available.

## Environment variables
[Best practice][env-how-to] in PHP is to put important configuration options in environment variables, perhaps in your virtual host configuration (e.g., `httpd.conf` or `.htacess`) or in a `.env` file. [Several][env1] [libraries][env2] are available via composer to assit in loading `.env` files.

[env-how-to]: https://jolicode.com/blog/what-you-need-to-know-about-environment-variables-with-php
[env1]: https://packagist.org/packages/symfony/dotenv
[env2]: https://packagist.org/packages/josegonzalez/dotenv

In most cases, it is not necessary to create environment variables to modify the default behavior of this library for use with CAS at IU.

The three necessary URLs (login, validation, logout) as described by https://kb.iu.edu/d/atfc are included by default but may be overridden through the use of the following environment variables.

* `CAS_LOGIN_URL` defaults to `'https://cas.iu.edu/cas/login'`
* `CAS_VALIDATION_URL` defaults to `'https://cas.iu.edu/cas/validate'`
* `CAS_LOGOUT_URL` defaults to `'https://cas.iu.edu/cas/logout'`

One additional environment variable is used by `#getSessionVar()` and `#authenticate()` to identify the session key used to store the user name response from CAS.

* `CAS_SESSION_VAR` defaults to `'CAS_USER'`, therefore the user name of the active user is available at `$_SESSION[getenv('CAS_SESSION_VAR')]`.

If storing authentication status somewhere other than the session variable, it's probably best to use the callback variation of `#authenticate()`.

# Release history

* v0.3
  - Stable
  - Last full release that supports PHP 5.6+
  
### Roadmap (TODO)

- Make PHP 7.1+ only, apply type-hints
- Make get/set username overridable (can be done with a subclass?)
- auth fail/success as callbacks only

  