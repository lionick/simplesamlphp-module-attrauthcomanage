<?php

/**
 * Authproc filter for retrieving attributes from COmanage and adding them to
 * the list of attributes received from the identity provider.
 *
 * This authproc filter uses the COmanage REST API for retrieving the user's
 * attributes.
 * See https://spaces.internet2.edu/display/COmanage/REST+API
 *
 * Example configuration:
 *
 *    authproc = array(
 *       ...
 *       '60' => array(
 *            'class' => 'attrauthcomanage:COmanageRestClient',
 *            'apiBaseURL' => 'https://comanage.example.org/registry',
 *            'username' => 'bob',
 *            'password' => 'secret',
 *            'userIdAttribute => 'eduPersonUniqueId', 
 *            'urnNamespace' => 'urn:mace:example.org',
 *       ),
 *
 * @author Nicolas Liampotis <nliam@grnet.gr>
 */
class sspmod_attrauthcomanage_Auth_Process_COmanageRestClient extends SimpleSAML_Auth_ProcessingFilter
{
    private $_apiBaseURL;

    private $_username;

    private $_password;

    private $_userIdAttribute = "eduPersonPrincipalName";

    private $_verifyPeer = true;

    private $_urnNamespace = "urn:mace:example.org";

    public function __construct($config, $reserved)
    {
        parent::__construct($config, $reserved);
        assert('is_array($config)');

        if (!array_key_exists('apiBaseURL', $config)) {
            SimpleSAML_Logger::error(
                "[attrauthcomanage] Configuration error: 'apiBaseURL' not specified");
            throw new SimpleSAML_Error_Exception(
                "attrauthcomanage configuration error: 'apiBaseURL' not specified"); 
        }
        if (!is_string($config['apiBaseURL'])) {
            SimpleSAML_Logger::error(
                "[attrauthcomanage] Configuration error: 'apiBaseURL' not a string literal");
            throw new SimpleSAML_Error_Exception(
                "attrauthcomanage configuration error: 'apiBaseURL' not a string literal");
        }
        $this->_apiBaseURL = $config['apiBaseURL']; 

        if (!array_key_exists('username', $config)) {
            SimpleSAML_Logger::error(
                "[attrauthcomanage] Configuration error: 'username' not specified");
            throw new SimpleSAML_Error_Exception(
                "attrauthcomanage configuration error: 'username' not specified"); 
        }
        if (!is_string($config['username'])) {
            SimpleSAML_Logger::error(
                "[attrauthcomanage] Configuration error: 'username' not a string literal");
            throw new SimpleSAML_Error_Exception(
                "attrauthcomanage configuration error: 'username' not a string literal");
        }
        $this->_username = $config['username'];

        if (!array_key_exists('password', $config)) {
            SimpleSAML_Logger::error(
                "[attrauthcomanage] Configuration error: 'password' not specified");
            throw new SimpleSAML_Error_Exception(
                "attrauthcomanage configuration error: 'password' not specified"); 
        }
        if (!is_string($config['password'])) {
            SimpleSAML_Logger::error(
                "[attrauthcomanage] Configuration error: 'password' not a string literal");
            throw new SimpleSAML_Error_Exception(
                "attrauthcomanage configuration error: 'password' not a string literal");
        }
        $this->_password = $config['password']; 

        if (array_key_exists('userIdAttribute', $config)) {
            if (!is_string($config['userIdAttribute'])) {
                SimpleSAML_Logger::error(
                    "[attrauthcomanage] Configuration error: 'userIdAttribute' not a string literal");
                throw new SimpleSAML_Error_Exception(
                    "attrauthcomanage configuration error: 'userIdAttribute' not a string literal");
            }
            $this->_userIdAttribute = $config['userIdAttribute']; 
        }

        if (array_key_exists('verifyPeer', $config)) {
            if (!is_bool($config['verifyPeer'])) {
                SimpleSAML_Logger::error(
                    "[attrauthcomanage] Configuration error: 'verifyPeer' not a boolean");
                throw new SimpleSAML_Error_Exception(
                    "attrauthcomanage configuration error: 'verifyPeer' not a boolean");
            }
            $this->_verifyPeer = $config['verifyPeer']; 
        }

        if (array_key_exists('urnNamespace', $config)) {
            if (!is_string($config['urnNamespace'])) {
                SimpleSAML_Logger::error(
                    "[attrauthcomanage] Configuration error: 'urnNamespace' not a string literal");
                throw new SimpleSAML_Error_Exception(
                    "attrauthcomanage configuration error: 'urnNamespace' not a string literal");
            }
            $this->_urnNamespace = $config['urnNamespace']; 
        }
    }

    public function process(&$state)
    {
        try {
            assert('is_array($state)');
            if (empty($state['Attributes'][$this->_userIdAttribute])) {
                SimpleSAML_Logger::error(
                    "[attrauthcomanage] Configuration error: 'userIdAttribute' not available");
                throw new SimpleSAML_Error_Exception(
                    "attrauthcomanage configuration error: 'userIdAttribute' not available");
            }
            $identifier = $state['Attributes'][$this->_userIdAttribute][0];
            $orgIdentities = $this->_getOrgIdentities($identifier);
            SimpleSAML_Logger::debug(
                "[attrauthcomanage] process: orgIdentities=" 
                . var_export($orgIdentities, true));
            if (empty($orgIdentities)) {
                return; 
            }
            $coOrgIdentityLinks = array();
            foreach ($orgIdentities as $orgIdentity) {
                if (!$orgIdentity->{'Deleted'}) {
                    $coOrgIdentityLinks = array_merge($coOrgIdentityLinks, 
                        $this->_getCoOrgIdentityLinks($orgIdentity->{'Id'}));  
                } 
            }
            SimpleSAML_Logger::debug(
                "[attrauthcomanage] process: coOrgIdentityLinks=" 
                . var_export($coOrgIdentityLinks, true));
            if (empty($coOrgIdentityLinks)) {
                return;
            }
            $coGroups = array();
            foreach ($coOrgIdentityLinks as $coOrgIdentityLink) {
                if (!$coOrgIdentityLink->{'Deleted'}) {
                    $coGroups = array_merge($coGroups,
                        $this->_getCoGroups($coOrgIdentityLink->{'CoPersonId'}));  
                } 
            }
            SimpleSAML_Logger::debug(
                "[attrauthcomanage] process: coGroups=" 
                . var_export($coGroups, true));
            if (empty($coGroups)) {
                return;
            }
            $coGroupMemberships = array();
            foreach ($coGroups as $coGroup) {
                if ($coGroup->{'Status'} === 'Active' && !$coGroup->{'Deleted'}) {
                    $co = $this->_getCo($coGroup->{'CoId'});
                    // CO name should always be available.
                    // However, if for some reason this is not the case, we 
                    // currently resort to using the CO numeric ID.
                    // TODO Consider throwing exception?
                    if (empty($co)) {
                        $coName = $coGroup->{'CoId'};
                    } else {
                        $coName = $co->{'Name'};
                    }
                    $coGroupMemberships[] = array(
                        'groupName' => $coGroup->{'Name'},  
                        'coName' => $coName,  
                    );
                } 
            }
            SimpleSAML_Logger::debug(
                "[attrauthcomanage] process: coGroupMemberships=" 
                . var_export($coGroupMemberships, true));
            if (empty($coGroupMemberships)) {
                return;
            }
            if (!array_key_exists('eduPersonEntitlement', $state['Attributes'])) {
                $state['Attributes']['eduPersonEntitlement'] = array();
            }
            foreach ($coGroupMemberships as $coGroupMembership) {
                $state['Attributes']['eduPersonEntitlement'][] = 
                    $this->_urnNamespace . ":"
                    . $coGroupMembership['groupName'] . ":" . "member"
                    . "@" . $coGroupMembership['coName'];
            }
        } catch (\Exception $e) {
            $this->_showException($e);
        }
    }

    private function _getOrgIdentities($identifier)
    {
        SimpleSAML_Logger::debug("[attrauthcomanage] _getOrgIdentities: identifier="
            . var_export($identifier, true));

        // Construct COmanage REST API URL
        $url = $this->_apiBaseURL . "/org_identities.json?"
            // TODO Limit search to specific CO
            //. "coid=" . $this->_coId . "&"
            . "search.identifier=" . urlencode($identifier);
        $data = $this->_http('GET', $url);
        assert('strncmp($data->{"ResponseType"}, "OrgIdentities", 13)===0'); 
        if (empty($data->{'OrgIdentities'})) {
            return array();
        }
        return $data->{'OrgIdentities'};
    }

    private function _getCoOrgIdentityLinks($orgIdentityId)
    {
        SimpleSAML_Logger::debug("[attrauthcomanage] _getCoOrgIdentityLinks: orgIdentityId="
            . var_export($orgIdentityId, true));

        // Construct COmanage REST API URL
        $url = $this->_apiBaseURL . "/co_org_identity_links.json?orgidentityid="
            . urlencode($orgIdentityId);
        $data = $this->_http('GET', $url);
        assert('strncmp($data->{"ResponseType"}, "CoOrgIdentityLinks", 18)===0'); 
        if (empty($data->{'CoOrgIdentityLinks'})) {
            return array();
        }
        return $data->{'CoOrgIdentityLinks'};
    }

    private function _getCoGroups($coPersonId)
    {
        SimpleSAML_Logger::debug("[attrauthcomanage] _getCoGroups: coPersonId="
            . var_export($coPersonId, true));

        // Construct COmanage REST API URL
        $url = $this->_apiBaseURL . "/co_groups.json?"
            . "copersonid=" . urlencode($coPersonId);
        $data = $this->_http('GET', $url);
        assert('strncmp($data->{"ResponseType"}, "CoGroups", 8)===0'); 
        if (empty($data->{'CoGroups'})) {
            return array();
        }
        return $data->{'CoGroups'};
    }

    private function _getCo($coId)
    {
        SimpleSAML_Logger::debug("[attrauthcomanage] _getCo: coId="
            . var_export($coId, true));

        // Construct COmanage REST API URL
        $url = $this->_apiBaseURL . "/cos/"
            . urlencode($coId) . ".json";
        $data = $this->_http('GET', $url);
        assert('strncmp($data->{"ResponseType"}, "Cos", 3)===0'); 
        if (empty($data->{'Cos'})) {
            return null;
        }
        return $data->{'Cos'}[0];
    }

    private function _http($method, $url)
    {
        SimpleSAML_Logger::debug("[attrauthcomanage] http: method=" 
            . var_export($method, true) . ", url=" . var_export($url, true));
        $ch = curl_init($url);
        curl_setopt_array(
            $ch,
            array(
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $this->_username . ":" . $this->_password,
                CURLOPT_SSL_VERIFYPEER => $this->_verifyPeer,
            )
        );

        // Send the request
        $response = curl_exec($ch);
        $http_response = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Check for error; not even redirects are allowed here
        if ($http_response !== 200) {
            SimpleSAML_Logger::error("[attrauthcomanage] API query failed: HTTP response code: "
                . $http_response . ", error message: '" . curl_error($ch)) . "'";
            throw new SimpleSAML_Error_Exception("Failed to communicate with COmanage Registry");
        }
        $data = json_decode($response);
        SimpleSAML_Logger::debug("[attrauthcomanage] http: data="
            . var_export($data, true));
        assert('json_last_error()===JSON_ERROR_NONE'); 
        return $data;
    }

    private function _showException($e)
    {
        $globalConfig = SimpleSAML_Configuration::getInstance();
        $t = new SimpleSAML_XHTML_Template($globalConfig, 'attrauthcomanage:exception.tpl.php');
        $t->data['e'] = $e->getMessage();
        $t->show();
        exit();
    }
}
