<?php
/** @var \Stanford\Esignature\Esignature $module */

// A COPYT OF THIS FILE BELONGS IN YOUR ESIG DIRECTORY PER THE README INSTRUCTIONS

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
