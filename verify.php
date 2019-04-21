<?php
/** @var \Stanford\Esignature\Esignature $module */
/** @var string $shibboleth_username_field */

// Lets record that this user successfully logged into REDCap in a way that doesn't pass the secret through to the client

// Get the shibboleth identity who has just verified into this page
$verify_id = getenv($shibboleth_username_field);
if (empty($verify_id)) {
    // Not operating in shibboleth mode
    die("This page is designed for shibboleth authentication");
}


// Generate a random hash
$hash = generateRandomHash(32);

// Log our hash and user id - this is used for server-side verification later
$result = $module->log(
    $module->PREFIX,
    array(
        "hash"      => $hash,
        "verify_id" => $verify_id
    )
);


// This page relies on being opened from the data entry form via esignature.js
// Display something to the user
?>
<html>
<head>
	<title>Authentication Successful</title>
	<script type="text/javascript">
		function success() {
			try {
				if( opener
                    && typeof opener.document != 'undefined'
                    && typeof opener.ShibEsig != 'undefined'
                ) {
					// calling window exists - send back the signature
                    opener.ShibEsig.verifyResponse(<?php echo json_encode($verify_id) ?>, <?php echo json_encode($hash) ?>);
                    window.close();
				} else {
                    // the calling window is no longer open
                    let x = document.getElementById("message");
                    x.innerHTML = "<p>The application that called this window doesn't appear to be around anymore.</p>" +
                        "<p>This shouldn't happen.  Please close this tab.</p>";
                }
			} catch (err) {
                let x = document.getElementById("message");
                x.innerHTML = "<p>An error has occurred.  Please check your console logs and report this to an administrator.</p>" +
                    "<p>This shouldn't happen.  Please close this tab.</p>";
				console.log('Error',err);
			}
		}
	</script>
    <style type="text/css">
        body { 
            background-color: #666;
            /*position: relative;*/
        }
        #message {
            position: absolute;
            top: 30%;
            height: 200px;
            text-align:center;
            width:580px;
            padding-top: 50px;
            margin-left:auto;
            margin-right:auto;
            font-weight: normal;
            background-color: white;
            border: 2px solid black;
        }

    </style>
</head>
<body onload="success();">
	<div id="message">
        <p>Authentication verified as <?php echo $verify_id ?>.</p>
        <p>This window should automatically close.</p>
    </div>
</body>
</html>
