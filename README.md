# IuCasAuthentication
A CAS authentication object that works with CAS @ IU. Using [CAS at IU](https://kb.iu.edu/d/atfc) isn't complicated, and UITS even provides some [sample code for PHP](https://kb.iu.edu/d/bfru). This helper class aims to make the process even easier.

## Installation

The preferred method of installation is with [Composer](https://getcomposer.org).

### Install with Composer
Add the following to your `composer.json` file:
```
"parsedown/twig": "~1.1"
```

Or, Install the Composer package:
```
composer require erusev/parsedown
```

### Install without Composer

Download the `IuCasAuthentication` class, and include or require it on your page(s).

## Using `IuCasAuthentication`

1. Create a new authentication object.

   * The first argument is required, and is the URL authentication should redirect to. Must be the same during authentication and validation steps.
   * An optional second argument must be one of the [CAS application codes at IU](https://kb.iu.edu/d/alqm). Defaults to 'IU'.
   * An optional third argument is a reference to a [PSR-3][psr3] logger (such as Monolog). Used to log validation errors, if available. Defaults to `null``.

2. Use the authentication object to get the CAS authentication URL. As applications and frameworks differ, you will need to handle the redirect to the authentcation URL yourself.

3. When your application's authentication redirect URL is loaded, re-create an authentication object and call its `validate()` method. This method will return the CAS username on success or `null` on failure.

You should only authenticate once per session, and store a successful authentication. Do not authenticate with every page refresh.

[psr3]: https://www.php-fig.org/psr/psr-3/

### Simple web application

```php
// File: login.php

$appUrl = 'http://localhost/login-validate';
$casHelper = new \IuCas\IuCasAuthentication($appUrl);

header('Location: ' . $casHelper, true, 303);
exit;
```

```php
// File: login-validate.php

// Remember, your applicaiton URL in validation must match the one passed in the authentication step
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

This example also uses a [PSR-3][psr3] logger.

```php
$app->get('/login', function (Request $request, Response $response) {
    $appUrl = 'http://localhost/login-validate';
    $casHelper = new \IuCas\IuCasAuthentication($appUrl);
    
    return $response->withRedirect($casHelper->getLoginUrl(), 303);
});

$app->get('/login-validate', function (Request $request, Response $response) {
    
    // Remember, your applicaiton URL in validation must match the one passed in the authentication step
    $appUrl = 'http://localhost/login-validate';
    $casHelper = new \IuCas\IuCasAuthentication($appUrl);
    $casHelper->setLogger($this->get('logger'));
    
    $result = $casHelper->validate();
    
    if ($result) {
        $_SESSION['username'] = $result;
        return $response->withRedirect('/', 303); // Success, logged in
    }
    
    // Failure; trigger logout to clear session
    return $response->withRedirect('/logout', 303);
});

```

## Logging

You may pass an optional PSR-3 logger as the third parameter of the constructor. If the validation step fails, details are sent to the logger, if available.

## Default CAS URLs

The three necessary URLs (login, validation, logout) as described by https://kb.iu.edu/d/atfc are included by default
but may be overridden through the use of the following environment variables.

* `CAS_LOGIN_URL` defaults to `'https://cas.iu.edu/cas/login'`
* `CAS_VALIDATION_URL` defaults to `'https://cas.iu.edu/cas/validate'`
* `CAS_LOGOUT_URL` defaults to `'https://cas.iu.edu/cas/logout'`
