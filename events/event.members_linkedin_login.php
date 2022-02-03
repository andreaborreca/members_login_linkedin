<?php

require_once(TOOLKIT . '/class.cryptography.php');
require_once(TOOLKIT . '/class.event.php');
require_once(EXTENSIONS . '/members_login_linkedin/extension.driver.php');


class eventmembers_linkedin_login extends SectionEvent
{
    public static function about()
    {
        return array(
            'name' => 'Members: Linkedin Login',
            'author' => array(
                'name' => 'andrea borreca',
                'website' => 'https://humanbit.com',
                'email' => 'info@humanbit.com'),
            'version' => 'Symphony 2.7.10',
            'release-date' => '2021-08-04T09:33:04+00:00',
            'trigger-condition' => 'action[members-linkedin-login]'
        );
    }

    public function priority()
    {
        return self::kHIGH;
    }

    public static function getSource()
    {
        return extension_members_login_linkedin::EXT_NAME;
    }

    public static function allowEditorToParse()
    {
        return false;
    }

    public function load()
    {
        try {
            $this->trigger();
        } catch (Exception $ex) {
            if (Symphony::Log()) {
                Symphony::Log()->pushExceptionToLog($ex, true);
            }
        }
    }

    public function trigger()
    {
        $LINKEDIN_CLIENT_ID = Symphony::Configuration()->get('client-id', 'members_linkedin_login');
        $LINKEDIN_SECRET = Symphony::Configuration()->get('secret', 'members_linkedin_login');
        $LINKEDIN_REDIRECT_URL = Symphony::Configuration()->get('client-redirect-url', 'members_linkedin_login');
        $MEMBERS_SECTION_ID = Symphony::Configuration()->get('members-section-id', 'members_linkedin_login');
        
        if (isset($_POST['action']['members-linkedin-login'])) {
            $_SESSION['OAUTH_SERVICE'] = 'linkedin';
            $_SESSION['OAUTH_START_URL'] = $_REQUEST['redirect'];
            $_SESSION['OAUTH_CALLBACK_URL'] = $LINKEDIN_REDIRECT_URL;
            $_SESSION['OAUTH_MEMBERS_SECTION_ID'] = $MEMBERS_SECTION_ID;
            $_SESSION['OAUTH_TOKEN'] = null;

            $url = "https://www.linkedin.com/oauth/v2/authorization?response_type=code&client_id=$LINKEDIN_CLIENT_ID&redirect_uri=$LINKEDIN_REDIRECT_URL&state=foobar&scope=r_basicprofile%20r_emailaddress%20w_member_social";

            redirect($url);

        } elseif (isset($_POST['code'])) {
            $g = new Gateway();
            
            $url = "https://www.linkedin.com/oauth/v2/accessToken?grant_type=authorization_code&code=" .$_REQUEST['code'] ."&redirect_uri=$LINKEDIN_REDIRECT_URL&client_id=$LINKEDIN_CLIENT_ID&client_secret=$LINKEDIN_SECRET";
            $g->init($url);
            $response = @$g->exec();
            if ($response !== false) {
                $response = @json_decode($response);
            }
            if (!$response) {
                throw new Exception('Failed to get the access token');
            }
            
            if (is_object($response) && isset($response->access_token)) {
                $_SESSION['ACCESS_TOKEN'] = $response->access_token;
                $url = "https://api.linkedin.com/v2/me?projection=(id,firstName,lastName,profilePicture,vanityName(displayImage~digitalmediaAsset:playableStreams))";
                $url_e = "https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))";
                $ch = curl_init($url);
                $ch_e = curl_init($url_e);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_e, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer ' .$_SESSION['ACCESS_TOKEN']
                ));
                curl_setopt($ch_e, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer ' .$_SESSION['ACCESS_TOKEN']
                ));
                $response = curl_exec($ch);
                $response_e= curl_exec($ch_e);
                curl_close($ch);
                curl_close($ch_e);
                if ($response !== false) {
                    $response = json_decode($response);

                    if ($response_e !== false){
                        $response_e = json_decode($response_e);
                    }
                }

                if ((!$response) || (!$response_e)) {
                    throw new Exception('Failed to get the access token url');
                }
                
                if ((is_object($response) && isset($response->firstName))) {

                    $_SESSION['OAUTH_TIMESTAMP'] = time();
                    $_SESSION['OAUTH_SERVICE'] = 'linkedin';
                    $_SESSION['ACCESS_TOKEN_SECRET'] = null;
                    $_SESSION['OAUTH_USER_ID'] = $response->id;
                    $FIRSTNAME = null;
                    $LASTNAME = null;
                    $username_id = substr($response->id, 0, 2);
                    $password_hash = Cryptography::hash($response->id);
                    foreach($response->firstName->localized as $val){
                        $FIRSTNAME = $val;
                    }
                    foreach($response->lastName->localized as $val){
                        $LASTNAME = $val;
                    }
                    $VANITYNAME = $response->vanityName;
                    $_SESSION['OAUTH_USER_FIRSTNAME'] = $FIRSTNAME;
                    $_SESSION['OAUTH_USER_LASTNAME'] = $LASTNAME;
                    $_SESSION['OAUTH_USER_IMG'] = $response->profilePicture->displayImage;
                    $_SESSION['OAUTH_USER_EMAIL'] = null;

                    if (empty($response_e->elements[0]->{"handle~"}->emailAddress)) {
                        throw new Exception('User did not gave email permission');
                    }

                    $edriver = Symphony::ExtensionManager()->create('members');
                    $edriver->setMembersSection($_SESSION['OAUTH_MEMBERS_SECTION_ID']);
                    $femail = $edriver->getField('email');
                    $mdriver = $edriver->getMemberDriver();
                    $email = $response_e->elements[0]->{"handle~"}->emailAddress;
                    $m = $femail->fetchMemberIDBy($email);

                    if (!$m) {
                        $m = new Entry();
                        $m->set('section_id', $_SESSION['OAUTH_MEMBERS_SECTION_ID']);
                        $m->setData($femail->get('id'), array('value' => $email));
                        $firstName = Symphony::Configuration()->get('member-firstname-field', 'members_linkedin_login');
                        if ($firstName) {
                            $m->setData(General::intval($firstName), array(
                                'value' => $FIRSTNAME,
                            ));
                        }
                        $lastName = Symphony::Configuration()->get('member-lastname-field', 'members_linkedin_login');
                        if ($lastName) {
                            $m->setData(General::intval($lastName), array(
                                'value' => $LASTNAME,
                            ));
                        }
                        $username = Symphony::Configuration()->get('member-username-field', 'members_linkedin_login');
                        if ($username) {
                            $m->setData(General::intval($username), array(
                                'value' => strtolower($VANITYNAME . $username_id),
                            ));
                        }
                        $password = Symphony::Configuration()->get('member-password-field', 'members_linkedin_login');
                        if ($password) {
                            $m->setData(General::intval($password), array(
                                'password' => $password_hash,
                            ));
                        }
                        $memberSince = Symphony::Configuration()->get('member-registered-since', 'members_linkedin_login');
                        if ($memberSince) {
                            $today = $this->_env['param']['today'];
                            $time = $this->_env['param']['current-time'];
                            $m->setData(General::intval($memberSince), array(
                                'activated' => 'yes',
                                'timestamp' => $today . ' ' . $time,
                            ));
                        }
                        $memberThumb = Symphony::Configuration()->get('member-profile-thumbnail', 'members_linkedin_login');
                        if ($memberThumb) {
                            $m->setData(General::intval($memberThumb), array(
                                'value' => $response->profilePicture->{'displayImage~'}->elements[0]->identifiers[0]->identifier,
                            ));
                        }
                        $memberLoginType = Symphony::Configuration()->get('member-login-type', 'members_linkedin_login');
                        if ($memberLoginType) {
                            $m->setData(General::intval($memberLoginType), array(
                                'value' => 'linkedin',
                            ));
                        }
                        $m->commit();
                        $m = $m->get('id');
                    }
                    $_SESSION['OAUTH_MEMBER_ID'] = $m;
                    $login = $mdriver->login(array(
                        'email' => $email,
                        'password' => $response->id,
                    ));
                    if ($login) {
                        redirect($_SESSION['OAUTH_START_URL']);
                    } else {
                        throw new Exception('Twitter login failed');
                    }
                    
                } else {
                    $_SESSION['OAUTH_SERVICE'] = null;
                    $_SESSION['ACCESS_TOKEN'] = null;
                    $_SESSION['OAUTH_TIMESTAMP'] = 0;
                    session_destroy();
                }
            } else {
                $_SESSION['OAUTH_SERVICE'] = null;
                $_SESSION['OAUTH_START_URL'] = null;
                $_SESSION['OAUTH_MEMBERS_SECTION_ID'] = null;
                $_SESSION['OAUTH_TOKEN'] = null;
                session_destroy();
            }
        } elseif (is_array($_POST['member-facebook-action']) && isset($_POST['member-facebook-action']['logout']) ||
                  is_array($_POST['member-action']) && isset($_POST['member-action']['logout'])) {
            $_SESSION['OAUTH_SERVICE'] = null;
            $_SESSION['OAUTH_START_URL'] = null;
            $_SESSION['OAUTH_MEMBERS_SECTION_ID'] = null;
            $_SESSION['OAUTH_TOKEN'] = null;
            session_destroy();
            
        }
    }

}
