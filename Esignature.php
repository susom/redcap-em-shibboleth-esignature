<?php


namespace Stanford\Esignature;

require_once('emLoggerTrait.php');

use \Authentication;

class Esignature extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    const MAX_VERIFY_AGE            = 60;  // Maximum number of seconds between logging in and pressing the 'sign' button.
    const PURGE_HASHES_OLDER_THAN   = 600; // Maximum age to keep a hash in our database

    /**
     * To process a valid esignature, we need to hijack the single_form_action.php page
     * @throws \Exception
     */
    public function redcap_every_page_before_render() {
        /** @var string $auth_meth */
        global $auth_meth;

        // $this->emDebug($this->PREFIX, PAGE, $_POST['shib_auth_token'], $auth_meth);

        // We need to intercept the single_form_action for shib e-signature
        if (PAGE == 'Locking/single_form_action.php' && !empty($_POST['shib_auth_token']) && $auth_meth == "shibboleth")
        {
            // Get the log token:
            $hash = htmlspecialchars($_POST['shib_auth_token'], ENT_QUOTES);

            // Find the token in the log:
            // $this->emDebug($this->getQueryLogsSql("select timestamp, verify_id, hash where hash='" . db_real_escape_string($hash) . "'"));
            $q = $this->queryLogs("select timestamp, verify_id, hash where hash = ?", db_real_escape_string($hash) );

            if (db_num_rows($q) === 0) {
                // Not found!
                $this->emDebug("Did not find a log entry for $hash");
                return;
            }

            if ($row = db_fetch_assoc($q)) {
                // Found at least one entry
                $timestamp = strtotime( $row['timestamp'] );
                $verify_id = $row['verify_id'];

                // Make sure it isn't too old
                $age_in_sec = strtotime("now")-$timestamp;
                if ($age_in_sec <= self::MAX_VERIFY_AGE) {
                    // Delete the hash log entry
                    if ($this->removeHash($hash)) {
                        // Successfully removed

                        // SUCCESS
                        // This part is a little confusing.  A long time ago Rob inserted some custom code to the
                        // single_form_action.php file that permits shibboleth esig when the following is true:
                        // $_POST['shib_auth_token'] == Authentication::hashPassword(USERID,$shibboleth_esign_salt,USERID)
                        // Since we have verified that the esignature was valid, let's ensure this expression will be true
                        // after this hook finishes executing:
                        global $shibboleth_esign_salt;
                        $shibboleth_esign_salt    = $hash;
                        $_POST['shib_auth_token'] = \Authentication::hashPassword($verify_id, $shibboleth_esign_salt);
                        $this->emDebug("Setting shib_auth_token to succeed for $verify_id on " . PAGE);
                        // $this->emDebug(
                        //     "Setting shibboleth_esign_salt: " . $shibboleth_esign_salt,
                        //     "Setting shib_auth_token      : " . $_POST['shib_auth_token']
                        // );

                    } else {
                        // Unable to remove hash from logs
                        $msg = "Unable to remove hash $hash";
                        $this->emLog($msg);
                        $this->ajaxError($msg);
                    }
                } else {
                    // Too Old
                    $this->emLog("$age_in_sec have elapsed since $verify_id's verification - not counting as valid");
                    $msg = "$age_in_sec seconds have elapsed since you verified your identity.  This is more than the " .
                        "maximum setting of " . self::MAX_VERIFY_AGE . " seconds.  Please try again.";
                    $this->ajaxError($msg);
                }
            } else {
                // Unable to find hash
                $msg = "Unable to find a verification ID matching $hash";
                $this->emDebug($msg);
                $this->ajaxError($msg);
            }

            // Cleanup task to remove any old hashes
            $this->purgeOldHashes();

            return;
        } else {
            // $this->emDebug("Skipping page: " . PAGE);
        }
    }


    /**
     * Return a custom json object and exit (used for reporting errors during verification)
     * @param $msg
     */
    public function ajaxError($msg) {
        $arr = array("error" => $msg);
        echo json_encode($arr, JSON_FORCE_OBJECT);
        $this->exitAfterHook();
    }


    /**
     * Remove a hash from the verify logs
     * @param $hash
     * @return bool
     * @throws \Exception
     */
    public function removeHash($hash)
    {
        $q = $this->removeLogs("hash=?", [db_real_escape_string($hash)]);
        $rows = db_affected_rows();
        if ($rows == 0) {
            $this->emLog("Unable to remove hash $hash");
        }
        return !$rows == 0;
    }


    /**
     * As a cleanup, we will remove any old hashes every now and then
     * @throws \Exception
     */
    public function purgeOldHashes() {
        // Get all hashes older than PURGE_HASHES_OLDER_THAN seconds
        $q = $this->queryLogs("select timestamp, hash");
        $old_hashes = 0;
        $now = strtotime("now");
        while ($row = db_fetch_assoc($q)) {
            $age = $now - strtotime($row['timestamp']);
            if ($age > self::PURGE_HASHES_OLDER_THAN) {
                $hash = $row['hash'];
                $old_hashes++;
                $this->removeHash($hash);
            }
        }
        if ($old_hashes) $this->emDebug("Removed $old_hashes old hashes");
    }


    /**
     * When we are on a data-entry form with shib and esig, we want to insert our esignature overrides!
     */
    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
		global $auth_meth;
		if ($auth_meth == 'shibboleth') {
		    global $displayEsigOption;
		    if ($displayEsigOption) {
                $this->emDebug($this->PREFIX . " active on $instrument for record $record, event $event_id, #$repeat_instance");
                $this->applyOverrides();
            } else {
		        // $this->emDebug("Shibboleth mode but $instrument doesn't support e-signatures");
            }
		} else {
		    $this->emDebug("we are not in shibboleth auth mode for project $project_id");
        }
    }


    public function applyOverrides() {

        // INSERT A NEW MODAL FORM AND SOME JAVASCRIPT
        ?>
        <!-- E-signature: username/password -->
        <div id="shib_esign_popup" title="Shibboleth E-signature Verification">
            <div class="instructions">
                A new browser window should open to verify your institutional identity.  Once complete, return
                here to complete the e-signature process.
            </div>
            <div class="status_container"></div>
        </div>

        <script type="text/javascript">
            ShibEsig = {
                "jsLog"         : <?php echo json_encode($this->getSystemSetting('enable-system-debug-logging')) ?>,
                "esign_shib_url": <?php echo json_encode($this->getSystemSetting('protected-web-url') . "?pid=" . $this->getProjectId()) ?>,
                "user_id"       : <?php echo json_encode(USERID) ?>,
                "popup"         : $('#shib_esign_popup')
            };
        </script>
        <script src='<?php echo $this->getUrl('esignature.js'); ?>'></script>

        <style type="text/css">
            #shib_esign_popup                       { display: none; }
            #shib_esign_popup .instructions         { margin-bottom: 15px; }
            #shib_esign_popup .status_container     { display: block; margin-left: auto; margin-right: auto; text-align: center;
                                                      padding: 4px; }
        </style>

        <?php

    }

}