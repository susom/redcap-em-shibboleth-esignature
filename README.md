# REDCap E-Signature for Shibboleth

When you use shibboleth as your authentication method, REDCap can't really do much 
in terms of verifying identity since it is handled by the server.

This module works after the server administrator creates a second endpoint that forces 
more strict authentication requirements.  In our case, this means a 60 second session
timeout and forced two-factor authentication each time.

When a user tries to e-sign a form, this module will open a pop-up that requires the
enhanced authentication requirements.  Upon completion, a unique hash is stored in the
log files with a 60 second timeout.  The user can then press the 'sign' button on the
REDCap data entry form and this module verifies that the user pressing sign is the same
user who just verified their identity using the more strict endpoint.

## How to Configure your Server

This module requires server alterations in order to work and is somewhat complex.  Please read carefully and first implement on a development server.  Please post any questions as issues to the github repository here https://github.com/susom/redcap-em-shibboleth-esignature/issues

The first step to configuring is to create a new ApplicatoinID in Shibboleth that has reduced user session timeouts.  This will force the signing user to re-authenticate.

In order to create two different session timeout settings with one Shibboleth/SAML, I added an application override entity to my Shibboleth2.xml file, registered the entity with my SPDB, and configured a new endpoint to use this entity.  The endpoint is the url on the server that will be protected with this more stringent timeout.

In my case, if a user goes to /esig/ they will have a 60 second timeout.
If they goto /redcap_v1.2.3/home.php they will have an 8 hour timeout.

### Adding a new Entity to your Shibboleth configuration
Assuming you have a working shibboleth configuration, you have a Shibboleth2.xml file (maybe in `/etc/shibboleth/Shibboleth2.xml`)

```xml
  <Application>
    <!-- All of your normal, existing configuration -->
    
    <!-- START OF NEW SECTION -->
      <!-- The id is what you will use in your apache.conf to specify where this applies -->
      <!-- The entityID can be any string but many use a url -->
    <ApplicationOverride
      id="esig"      
      entityID="https://your-redcap-server/esig">

        <!-- If you include a cookie path (/esig; below), make sure it matches your apache path, otherwise leave the path as / (e.g. path=/; ) -->
      <Sessions
        checkAddress="false"
        relayState="ss:mem"
        lifetime="60"
        handlerURL="/esig/Shibboleth.sso"
        timeout="60"
        cookieProps="; path=/esig; secure; HttpOnly"
        handlerSSL="true"
      >
          <!-- the sso entityID should be YOUR IDP address -->
          <!-- the authnContextClassRef is a special tag that forces TWO FACTOR AUTHENTICATION for us at Stanford... leave out or ask for help from your institution -->
        <SSO 
          entityID="https://idp.yourinstitution.edu/"           
          authnContextClassRef="https://saml.stanford.edu/profile/mfa/forced"
        >SAML2</SSO>
      </Sessions>
    </ApplicationOverride>
    <!-- END OF NEW SECTION -->
  </ApplicationDefaults>
```

### Registering Metadata with your Service Provider Database
Okay - now that I modified my Shibboleth2.xml, I had to restart my apache/shib services on the server and then obtain the new MetaData for this entity.  This was obtained by visiting `https://myserver/esig/Shibboleth.sso/Metadata`.  This prompted me to download the metadata file.

Next, I registered this Metadata file with our Service Provider Database (SPDB at Stanford).  Make sure the entityID matches the entityID you specify above.  We have to wait about 15 minutes for our IDP to reload before I could test that this change took effect.

### Update your Apache configuration to apply this entity to a directory
Next, I created a new directory on my web server of of the web root, something like `/var/www/html/esig/`.

In the apache configuration I bound this new directory to the shibboleth entity on the server:
```
<Location ~ "^/esig/">
  AuthType shibboleth
  ShibRequestSetting applicationId esig
  ShibRequestSetting requireSession true
  Require shib-session
</Location>
```

So, any visitor to the /esig/ directory on our server (or any child directories) will be forced to authenticate through the esig applicationId which was configured with the shorter session above.

#### Testing

To see if my sessions were indeed renewing every 60 seconds as configured, I temporarily created a file inside the `/esig/` folder called test.php that contained something like this:
```php
<?php
/** ESIGNATURE TEST PAGE **/
?>
<h3>E-Signature Test Page</h3>
<b>SERVER</b>
<pre><?php echo print_r($_SERVER,true) ?></pre>
```
From here, I can see the shibboleth data in the $_SERVER session.  Every 60 seconds the Shibboleth session changes and I am forced to two-factor again.


### Enabling Custom Module Verification File
So, in order for this to work, you have to place a custom file inside the new esignature directory on your server.  In my case, I simply called it index.php and the contents are:
```php
<?php
/** @var \Stanford\Esignature\Esignature $module */

// Shibboleth does the authentication - we don't need REDCap to do anything here
define('NOAUTH',true);

// If you put your esignature folder somewhere other than the REDCap application root you may have to edit the line below
// to find the redcap_connect,php file!
include_once "../redcap_connect.php";

try {
    $module = \ExternalModules\ExternalModules::getModuleInstance('shibboleth_esignature');
} catch(Exception $e) {
    die("Error 1: Shibboleth Esignature is not configured correctly on this server.  Please notify an administrator (" . $e->getMessage() . ")");
}

$path = $module->getModulePath() . "verify.php";
if (! file_exists($path)) {
    die("Error 2: Shibboleth Esignature is not configured correctly on this server.  Please notify an administrator (unable to find $path)");
}
require($path);

```
A copy of this file is also included inside the EM as `resources/index.php`.
 

### Enable the EM
So, with all of this complete, you should then enable this external module on your server and make sure to check the box to 'enable for all projects'.  This way, if any project later uses e-signature this will be in effect.

You must also specify the web path (relative url) on your server to the file above.  In my case, it was `/esign/` or could be `/esign/index.php`.  

Optionally, if you have emLogger installed, you can enable the debug logging.
