/**
 * This function overrides the default esignature lock
 * @param lock_action
 * @param esign_action
 */
ShibEsig.saveLocking = function(lock_action,esign_action) {
    ShibEsig.log('saveLocking!');
    // We know esign_action = 'save' and lock_record = '2' since this is the only time we override the saveLocking function.

    // Determine action
    if      (lock_action == 2)  ShibEsig.lock_action = "";
    else if (lock_action == 1)  ShibEsig.lock_action = "lock";
    else if (lock_action == 0)  ShibEsig.lock_action = "unlock";

    // Remove the dialog if it is already existing
    if (ShibEsig.popup.hasClass('.ui-dialog-content')) ShibEsig.popup.dialog('destroy');

    // Create a new dialog
    ShibEsig.popup.dialog({
        bgiframe: true,
        modal: true,
        width: 450,
        zIndex: 3999,
        closeOnEscape: false,
        open: function(event, ui) {
            $(".ui-dialog-titlebar-close").hide();
        },
        buttons: {
            'Cancel': function() {
                // clean up auth window if it still open
                if (typeof(ShibEsig.window) != 'undefined') {
                    ShibEsig.log("Window gone - removing interval");
                    clearInterval(ShibEsig.interval_timer);
                    try{ ShibEsig.window.close() } catch(err){};
                }
                ShibEsig.popup.dialog('close');
            },
            'Sign': function() {
                // Set waiting
                ShibEsig.updateStatus("<div class='gray text-center'><img src='"+app_path_images+"progress_circle.gif' class='imgfix'> " +
                    "Processing... Please wait</div>");
                ShibEsig.sign();
                // Hide errors
                $('.error', ShibEsig.popup).hide(); //css('display','none'); //Default state
            }
        }
    });

    // Set errors to hidden on creation
    $('.error', ShibEsig.popup).hide(); //css('display','none'); //Default state

    // Disable the sign button
    $(":button:contains('Sign')").addClass('ui-state-disabled').attr('disabled','disabled');

    // Set status and launch e-signature window
    ShibEsig.updateStatus("<div class='gray text-center'><img src='"+app_path_images+"progress_circle.gif' class='imgfix'> " +
        "Waiting for the authentication window...</div>");
    ShibEsig.window = ShibEsig.openWindow(ShibEsig.esign_shib_url, "Shibboleth E-Signature");

    // Start interval that checks for window closing
    ShibEsig.interval_timer = setInterval (function(){
        if(typeof(ShibEsig.window)=='undefined' || ShibEsig.window.closed) {
            ShibEsig.updateStatus("<div class='red text-center'><img src='"+app_path_images+"exclamation.png' class='imgfix'> " +
                "Authentication failed.<br>Please cancel and try again.</div>");
            clearInterval(ShibEsig.interval_timer);
        }
    }, 250);
};


/**
 * callback function that is called by the esig_shibboleth_url upon authentication
 * ShibEsig.verifyResponse = function(result, auth_token) {
 *
 * @param verify_id
 * @param auth_token
 */
ShibEsig.verifyResponse = function(verify_id, auth_token) {

    ShibEsig.log("Verify Response: " + verify_id + " / " + auth_token);

    clearInterval(ShibEsig.interval_timer);	           //cancel window timer
    $('.error', ShibEsig.popup).css('display','none'); //Default state

    ShibEsig.auth_token = auth_token;

    // make sure the user_id used to esign is the same as the current user REDCap id
    // this is done for convenience and could be hacked, but doesn't compromise the final
    // identity check

    if (verify_id === ShibEsig.user_id) {
        // users match - enable submission
        ShibEsig.updateStatus("<img src='"+app_path_images+"tick_shield.png' class='imgfix'> Your identity has been verified.<br>" +
            "Please press <em>Sign</em> to complete this process.");
        $(":button:contains('Sign')").removeClass('ui-state-disabled').removeAttr('disabled').focus();
    } else {
        // different esignature id and redcap user id
        ShibEsig.updateStatus("<div class='red text-center'><img src='"+app_path_images+"exclamation.png' class='imgfix'> Your REDCap username does not match your e-signature ID!<br>" +
            "Please close your browser to clear your session.<br>" +  ShibEsig.user_id + " &ne; " + verify_id + "</div>");
        // $('.error_text', ShibEsig.popup).html("Your REDCap username does not match your e-signature ID!<br>" +
        //     "Please close your browser to clear your session.<br>" +  ShibEsig.user_id + " &ne; " + verify_id);
        // $('.error', ShibEsig.popup).toggle('blind',{},'normal');
    }
};


/**
 * Submit the form for processing - server-side hook will verify hash
 */
ShibEsig.sign = function() {
    const url =
    $.post(app_path_webroot+"Locking/single_form_action.php?pid="+pid,
    {
        auto:            getParameterByName('auto'),
        instance:        getParameterByName('instance'),
        esign_action:    "save", 	            // this must be true or we wouldn't be here
        event_id:        event_id,
        action:          ShibEsig.lock_action,  //
        username:        ShibEsig.user_id,      // if someone were to override this, it would get caught server-side and blocked
        record:          getParameterByName('id'),
        form_name:       getParameterByName('page'),
        shib_auth_token: ShibEsig.auth_token,   // this is the log id we will use to verify
        no_auth_key:     'q4deAr8s'     	    // this bypasses the standard auth mechanism
    }, function(data) {
        ShibEsig.popup.dialog('close');
        if (data != "") {
            // Response is now the record_id (as of REDCap 12.5.4) / commit 8cab9f081311b705267514bdce17b55232568485
            // ShibEsig.log("Result is " + data + " and record is " + getParameterByName('id'));
            // e-signature was saved
            ShibEsig.numLogins = 0;
            // Submit the form if saving e-signature
            if (ShibEsig.lock_action === 'lock' || ShibEsig.lock_action == '') {
                // Just in case we're using auto-numbering and current ID does not reflect saved ID (due to simultaneous users),
                // change the record value on the page in all places.
                if (auto_inc_set && getParameterByName('auto') == '1' && isinteger(data.replace('-',''))) {
                    $('#form :input[name="'+table_pk+'"], #form :input[name="__old_id__"]').val(data);
                }
                // Submit the form
                formSubmitDataEntry();
            } else {
                setUnlocked("save");
            }
        } else {
            // Login failed
            // See if we forced an error message back
            try {
                let msg = JSON.parse(data);
                if (msg.hasOwnProperty('error')) {
                    simpleDialog('<div class="red text-center">' + msg.error + '</div>', "E-signature Error");
                }
            } catch (e) {
                // return isn't json
                ShibEsig.log("Debug Response", data);
            }

            // Increment the number of failed logins
            if (typeof ShibEsig.numLogins !== 'undefined') {
                ShibEsig.numLogins++;
            } else {
                ShibEsig.numLogins = 1;
            }
            esignFail(ShibEsig.numLogins);
        }
    });
};


/**
 * Update the status of modal popup
 * @param html
 */
ShibEsig.updateStatus = function(html) {
    $('.status_container', ShibEsig.popup).html(html);
};


/**
 * Utility function to open pop-up window
 * @param url
 * @param title
 * @returns {Window}
 */
ShibEsig.openWindow = function(url, title) {
     var width  = 600;
     var height = 600;
     var left   = (screen.width  - width)/2;
     var top    = (screen.height - height)/2;
     var params = 'width='+width+', height='+height;
     params += ', top='+top+', left='+left;
     params += ', directories=no, location=no, menubar=no, resizable=no, scrollbars=no, status=no, toolbar=no';
     newwin=window.open(url, title, params);
     if (window.focus) {newwin.focus()}
     return newwin;
};


/**
 * A logging function for javascript
 */
ShibEsig.log = function() {
    if (!ShibEsig.jsLog) return false;

    // Make console logging more resilient to Redmond
    try {
        console.log.apply(this,arguments);
    } catch(err) {
        // Error trying to apply logs to console (problem with IE11)
        try {
            console.log(arguments);
        } catch (err2) {
            // Can't even do that!  Argh!  Damn you Redmond!  No logging
        }
    }
};


/**
 * THIS IS A PROXY FUNCTION TO OVERLOAD saveLocking FROM BASE.JS TO ENABLE SHIBBOLETH-BASED ESIGNATURE
 * http://stackoverflow.com/questions/296667/overriding-a-javascript-function-while-referencing-the-original
 * http://api.jquery.com/Types/#Proxy_Pattern
 */
(function () {
    // Based on the context, I use a custom saveLocking function or leave the default
    // Since this file is only included if stanford_esig_enabled is true, I don't need to check here...

    // save the default function
    var proxied = saveLocking;

    // replace it
    saveLocking = function () {
        var lock_action = arguments[0];
        var esign_action = arguments[1];

        // ShibEsig.log('lock_action: ' + lock_action, 'esign_action:', esign_action, 'lock_record',lock_record);

        // Only override the default saveLocking if we are saving with esignature
        if ( lock_record == 2 && $('#__ESIGNATURE__').prop('checked') && esign_action == 'save' )
        {
            ShibEsig.log("Overriding saveLocking for Shibboleth");
            return ShibEsig.saveLocking.apply(this,arguments);
        } else {
            return proxied.apply(this, arguments);
        }
    };
})();
