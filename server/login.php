<?
/*****************************************************************************
 **
 ** grr >:(
 ** https://github.com/melllvar/grr
 ** Copyright (C) 2013 Akop Karapetyan
 **
 ** This program is free software; you can redistribute it and/or modify
 ** it under the terms of the GNU General Public License as published by
 ** the Free Software Foundation; either version 2 of the License, or
 ** (at your option) any later version.
 **
 ** This program is distributed in the hope that it will be useful,
 ** but WITHOUT ANY WARRANTY; without even the implied warranty of
 ** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 ** GNU General Public License for more details.
 **
 ** You should have received a copy of the GNU General Public License
 ** along with this program; if not, write to the Free Software
 ** Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 **
 ******************************************************************************
 */

require("include/openid.php");
require("include/common.php");
require("classes/Core.php");

class LoginController extends Controller
{
  private $openId;

  function mustBeAuthorized()
  {
    return false;
  }

  function initRoutes()
  {
    $this->addGetRoute(array("logInWith"), "logInRoute");
    $this->addGetRoute(array("logout"), "logOutRoute");
    $this->addGetRoute(array("newAccount"), "newAccountRoute");
    $this->addGetRoute(array("newAdminAccount"), "newAdminAccountRoute");
    $this->addGetRoute(array("createToken"), "createTokenRoute");
  }

  function defaultRoute()
  {
    if ($this->isAuthenticated())
      $this->redirectTo("index.php");
    else
      $this->renderLogInPage();
  }

  function createTokenRoute($tokenHash)
  {
    if ($this->isAuthenticated())
      $this->redirectTo("index.php");
    else
      $this->renderLogInPage($tokenHash);
  }

  function logInRoute($provider)
  {
    $authUrl = null;

    if ($provider == "google")
      $authUrl = "https://www.google.com/accounts/o8/id";
    else if ($provider == "yahoo")
      $authUrl = "https://me.yahoo.com";

    if ($authUrl)
    {
      $this->openId->identity = $authUrl;
      $this->redirectTo($this->openId->authUrl());
    }
  }

  function logOutRoute()
  {
    setcookie(COOKIE_AUTH, "", time() - 3600);
    setcookie(COOKIE_VAUTH, "", time() - 3600);

    $this->unsetUser();
    $this->redirectTo("login.php");
  }

  function newAccountRoute()
  {
    $tokenHash = $_POST["createToken"];
    $username = $_POST["username"];
    $emailAddress = $_POST["emailAddress"];
    $openIdIdentity = $_POST["oid"];

    $receivedOpenIdHash = $_POST["v"];
    $computedOpenIdHash = $this->saltAndHashOpenId($openIdIdentity);

    if ($computedOpenIdHash != $receivedOpenIdHash)
    {
      $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("Could not log in - try again"));
      return;
    }
    else if (!preg_match('/^\\w+$/', $username))
    {
      $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("Enter a valid username"));
      return;
    }
    else if (strlen($openIdIdentity) >= 512)
    {
      $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("Cannot create account with this OpenId"));
      return;
    }
    else if (strlen($emailAddress) < 1)
    {
      // FIXME: validate using a regex
      $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("Enter a valid email address"));
      return;
    }

    $storage = Storage::getInstance();
    $tokenId = null;

    if (!CREATE_UNKNOWN_ACCOUNTS)
    {
      // Account creation is disabled - is there a token?

      if (!$tokenHash || ($token = $storage->getWelcomeToken($tokenHash)) === false)
      {
        // Missing or unknown token 

        $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("Account creation offer is expired or invalid"));
        return;
      }

      $tokenId = $token["id"];
    }

    $roleId = $storage->getRoleId(ROLE_USER);
    if ($roleId === false)
    {
      $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("Cannot create account. Try again later"));
      return;
    }

    if (($user = $storage->createUser($openIdIdentity, $username, $emailAddress, $tokenId, $roleId)) !== false)
      $this->authorizeUser($user, $openIdIdentity);
    else
      $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, l("That username is already taken. Try another one"));
  }

  function newAdminAccountRoute()
  {
    $tokenHash = $_POST["createToken"];
    $username = $_POST["username"];
    $emailAddress = $_POST["emailAddress"];
    $openIdIdentity = $_POST["oid"];

    $receivedOpenIdHash = $_POST["v"];
    $computedOpenIdHash = $this->saltAndHashOpenId($openIdIdentity);

    if ($computedOpenIdHash != $receivedOpenIdHash)
    {
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Could not log in - try again"));
      return;
    }
    else if (!preg_match('/^\\w+$/', $username))
    {
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Enter a valid username"));
      return;
    }
    else if (strlen($openIdIdentity) >= 512)
    {
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Cannot create account with this OpenId"));
      return;
    }
    else if (strlen($emailAddress) < 1)
    {
      // FIXME: validate using a regex
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Enter a valid email address"));
      return;
    }
    else if (ADMIN_SECRET === null || trim($tokenHash) !== ADMIN_SECRET)
    {
      $this->renderCreateAdminAccountPage($openIdIdentity, l("ADMIN_SECRET is incorrect. Try again"));
      return;
    }

    $storage = Storage::getInstance();

    $userCount = $storage->getUserCount();
    if ($userCount === false)
    {
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Cannot verify number of users"));
      return;
    }
    else if ($userCount > 0)
    {
      $this->redirectTo("login.php");
      return;
    }

    $roleId = $storage->getRoleId(ROLE_ADMIN);
    if ($roleId === false)
    {
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Cannot create account. Try again later"));
      return;
    }

    if (($user = $storage->createUser($openIdIdentity, $username, $emailAddress, null, $roleId)) !== false)
      $this->authorizeUser($user, $openIdIdentity);
    else
      $this->renderCreateAdminAccountPage($openIdIdentity, l("Cannot create account at the moment"));
  }

  function openIdModeRoute($mode)
  {
    if ($mode != "cancel" && $this->openId->validate())
    {
      // User has signed in via openID

      $storage = Storage::getInstance();
      $openIdIdentity = $this->openId->identity;

      if (($user = $storage->findUserWithOpenId($openIdIdentity)) === false)
      {
        // Unknown user. Is a welcome token available?

        $tokenHash = $_GET["createToken"];
        $emailAddress = null;

        $userCount = $storage->getUserCount();
        if ($userCount !== false && $userCount < 1)
        {
          // No users in the database yet. Create a temporary file with a random hash
          $this->renderCreateAdminAccountPage($openIdIdentity);
          return;
        }

        if (!CREATE_UNKNOWN_ACCOUNTS)
        {
          // Account creation is disabled - is there a token?

          if (!$tokenHash || ($token = $storage->getWelcomeToken($tokenHash)) === false)
          {
            // Missing or unknown token 

            $this->renderLogInPage(null, l("There are no users registered under that account"));
            return;
          }

          $emailAddress = $token["emailAddress"];
        }

        $this->renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress);
      }
      else
      {
        // Already a member
        $this->authorizeUser($user, $openIdIdentity);
      }
    }
    else
    {
      $this->redirectTo("login.php");
    }
  }

  function route()
  {
    $this->openId = new LightOpenID(HOSTNAME);

    if ($this->openId->mode)
      $this->openIdModeRoute($this->openId->mode);
    else
      parent::route();
  }

  function saltAndHashOpenId($openId)
  {
    return hash('sha256', hash('sha256', 
      sprintf("%s,%s", SALT_VOPENID, $openId)));
  }

  function authorizeUser($user, $secret)
  {
    $hash = hash('sha256', hash('sha256', sprintf("%s,%s,%s,%s,%s", 
      SALT_SESSION, $secret, $user->id, mt_rand(), time())));

    $storage = Storage::getInstance();
    $sessionId = $storage->createSession($user, $hash, $_SERVER["REMOTE_ADDR"]);

    if ($sessionId !== false)
    {
      // Verification hash
      $vhash = hash('md5', hash('md5', sprintf("%s,%s,%s",
        SALT_VHASH, $user->username, $sessionId)));

      setcookie(COOKIE_AUTH, $hash, time() + SESSION_DURATION);
      setcookie(COOKIE_VAUTH, $vhash, time() + SESSION_DURATION);

      header('Location: index.php');
    }

    return $sessionId;
  }

  function renderLogInPage($createToken = null, $errorMessage = null)
  {
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <link href="content/grr.css" type="text/css" rel="stylesheet"/>
    <title>Log In</title>
  </head>
  <body>
    <div id="header">
      <h1>grr <span class="grr">&gt;:(</span></h1>
    </div>
    <div id="content" class="login">
<?
    if ($errorMessage)
    {
?>
      <div class="error"><?= h($errorMessage) ?></div>
<?
    }
?>
      <span class="directions">Log in with an existing OpenId account:</span>
      <div class="openid-providers">
        <a href="?logInWith=google<?= $createToken ? "&createToken={$createToken}" : "" ?>" class="google large-button" title="Log in with Google"></a>
        <a href="?logInWith=yahoo<?= $createToken ? "&createToken={$createToken}" : "" ?>" class="yahoo large-button" title="Log in with Yahoo!"></a>
      </div>
    </div>
    <div id="footer">
      &copy; 2013 Akop Karapetyan | <a href="https://github.com/melllvar/grr">grr</a> is Open and Free Software licensed under GPL
    </div>
  </body>
</html>
<?
  }

  function renderNewAccountPage($openIdIdentity, $tokenHash, $emailAddress, $errorMessage = null)
  {
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <link href="content/grr.css" type="text/css" rel="stylesheet"/>
    <title>Create New Account</title>
  </head>
  <body>
    <div id="header">
      <h1>grr <span class="grr">&gt;:(</span></h1>
    </div>
    <div id="content" class="login">
<?
    if ($errorMessage)
    {
?>
      <div class="error"><?= h($errorMessage) ?></div>
<?
    }
?>
      <form action="login.php?newAccount=true" method="post">
<?
    if ($tokenHash)
    {
?>
        <input type="hidden" name="createToken" value="<?= h($tokenHash) ?>" />
<?
    }
?>
        <input type="hidden" name="oid" value="<?= h($openIdIdentity) ?>" />
        <input type="hidden" name="v" value="<?= h($this->saltAndHashOpenId($openIdIdentity)) ?>" />
        <span class="directions">Username:</span>
        <input type="text" name="username" value="<?= h($_POST["username"]) ?>" />
        <span class="directions">Email Address:</span>
        <input type="text" name="emailAddress" value="<?= h($emailAddress) ?>" />
        <input type="submit" value="Create new account" />
      </form>
    </div>
    <div id="footer">
      &copy; 2013 Akop Karapetyan | <a href="https://github.com/melllvar/grr">grr</a> is Open and Free Software licensed under GPL
    </div>
  </body>
</html>
<?
  }

  function renderCreateAdminAccountPage($openIdIdentity, $errorMessage = null)
  {
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <link href="content/grr.css" type="text/css" rel="stylesheet"/>
    <title>Create New Account</title>
  </head>
  <body>
    <div id="header">
      <h1>grr <span class="grr">&gt;:(</span></h1>
    </div>
    <div id="content" class="login">
<?
    if ($errorMessage)
    {
?>
      <div class="error"><?= h($errorMessage) ?></div>
<?
    }
?>
      <form action="login.php?newAdminAccount=true" method="post">
        <input type="hidden" name="oid" value="<?= h($openIdIdentity) ?>" />
        <input type="hidden" name="v" value="<?= h($this->saltAndHashOpenId($openIdIdentity)) ?>" />
        <span class="directions">Please check the configuration file ('config.php') on the server. Copy the 
          value of 'ADMIN_SECRET' (without quotes) and paste it in the field below:</span>
        <input type="text" name="createToken" value="<?= h($_POST["createToken"]) ?>" />
        <span class="directions">Username:</span>
        <input type="text" name="username" value="<?= h($_POST["username"]) ?>" />
        <span class="directions">Email Address:</span>
        <input type="text" name="emailAddress" value="<?= h($_POST["emailAddress"]) ?>" />
        <input type="submit" value="Create new account" />
      </form>
    </div>
    <div id="footer">
      &copy; 2013 Akop Karapetyan | <a href="https://github.com/melllvar/grr">grr</a> is Open and Free Software licensed under GPL
    </div>
  </body>
</html>
<?
  }
}

$ctrlr = new LoginController();
$ctrlr->execute();

?>