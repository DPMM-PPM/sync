<?php
declare(strict_types=1);
define('IL_LDAP_BIND_DEFAULT', 0);
define('IL_LDAP_BIND_ADMIN', 1);
define('IL_LDAP_BIND_TEST', 2);
define('IL_LDAP_BIND_AUTH', 10);

/**
*
* @author Stefan Meyer <meyer@leifos.com> Jean-Paul Manigand <jean-paul.manigand@intradef.gouv.fr>
* @version $Id$
*
*/
class ilsyncQuery
{

    public const LDAP_BIND_DEFAULT = 0;
    public const LDAP_BIND_ADMIN = 1;
    public const LDAP_BIND_TEST = 2;
    public const LDAP_BIND_AUTH = 10;

    /**
     * @var string
     * @deprecated with PHP 7.3 (LDAP_CONTROL_PAGEDRESULTS)
     */
//    const IL_LDAP_CONTROL_PAGEDRESULTS = '1.2.840.113556.1.4.319';
    
    const IL_LDAP_SUPPORTED_CONTROL = 'supportedControl';
    const PAGINATION_SIZE = 100;

    private string $ldap_server_url;
    private ilLDAPServer $settings;
    
    private ilLogger $logger;
    private ilLDAPAttributeMapping $mapping;
    private array $user_fields = [];

    /**
     * LDAP Handle
     * @var resource
     */
    private $lh;
    
    /**
     * Constructur
     *
     * @access private
     * @param object ilLDAPServer or subclass
     * @throws ilLDAPQueryException
     *
     */
     
    public function __construct(ilLDAPServer $a_server, string $a_url = '')
    {
	global $DIC;
	$this->logger = $DIC->logger()->auth();
        $this->settings = $a_server;
        
        if ($a_url !=='') {
            $this->ldap_server_url = $a_url;
        } else {
            $this->ldap_server_url = $this->settings->getUrl();
        }
        
        $this->mapping = ilLDAPAttributeMapping::_getInstanceByServerId($this->settings->getServerId());
                
        $this->fetchUserProfileFields();
        $this->connect();
    }
    
    /**
     * Get server
     * @return ilLDAPServer
     */
    public function getServer(): ilLDAPServer
    {
        return $this->settings;
    }
     
    /**
     * Get one user by login name
     *
     * @access public
     * @param string login name
     * @return array of user data
     */
    public function fetchUser($a_name): array
    {
        if (!$this->readUserData($a_name)) {
            return [];
        } else {
            return $this->users;
        }
    }
    
    /**
     * Fetch all users
     *
     * @access public
     * @return array array of user data
     */
    public function fetchUsers()
    {
        // First of all check if a group restriction is enabled
        // YES: => fetch all group members
        // No:  => fetch all users
        if ($this->settings->getGroupName() !== '') {
            $this->logger->debug('Searching for group members.');

            $groups = $this->settings->getGroupNames();
            if (count($groups) <= 1) {
                $this->fetchGroupMembers();
            } else {
                foreach ($groups as $group) {
                    $this->fetchGroupMembers($group);
                }
            }
        }
        if ($this->settings->getGroupName() === '' || $this->settings->isMembershipOptional()) {
            $this->logger->info('Start reading all users...');
            $this->readAllUsers();
            #throw new ilLDAPQueryException('LDAP: Called import of users without specifying group restrictions. NOT IMPLEMENTED YET!');
        }
        
        return $this->users;
    }
    /**
     * Perform a query
     *
     * @access public
     * @param string search base
     * @param string filter
     * @param int scope
     * @param array attributes
     * @return object ilLDAPResult
     * @throws ilLDAPQueryException
     */
    public function query($a_search_base, $a_filter, $a_scope, $a_attributes)
    {
        $res = $this->queryByScope($a_scope, $a_search_base, $a_filter, $a_attributes);
        if ($res === false) {
            throw new ilLDAPQueryException(__METHOD__ . ' ' . ldap_error($this->lh) . ' ' .
                sprintf(
                    'DN: %s, Filter: %s, Scope: %s',
                    $a_search_base,
                    $a_filter,
                    $a_scope
                ));
        }
        return (new ilLDAPResult($this->lh, $res))->run();
    }
    
    /**
     * Add value to an existing attribute
     *
     * @access public
     * @throws ilLDAPQueryException
     */
    public function modAdd($a_dn, $a_attribute)
    {
        if (@ldap_mod_add($this->lh, $a_dn, $a_attribute)) {
            return true;
        }
        throw new ilLDAPQueryException(__METHOD__ . ' ' . ldap_error($this->lh));
    }
    
    /**
     * Delete value from an existing attribute
     *
     * @access public
     * @throws ilLDAPQueryException
     */
    public function modDelete($a_dn, $a_attribute)
    {
        if (@ldap_mod_del($this->lh, $a_dn, $a_attribute)) {
            return true;
        }
        throw new ilLDAPQueryException(__METHOD__ . ' ' . ldap_error($this->lh));
    }
    
    /**
     * Fetch all users
     * This function splits the query to filters like e.g (uid=a*) (uid=b*)...
     * This avoids AD page_size_limit
     *
     * @access public
     *
     */
    private function readAllUsers()
    {
    	global $DIC;
        $ilSetting = $DIC['ilSetting'];
    
        // Build search base
        if (($dn = $this->settings->getSearchBase()) && substr($dn, -1) != ',') {
            $dn .= ',';
        }
        $dn .= $this->settings->getBaseDN();
        $tmp_result = null;

        if ($this->checkPaginationEnabled() and $ilSetting->get('formatUid')==0) {
            try {
                $tmp_result = $this->runReadAllUsersPaged($dn);
            } catch (ilLDAPPagingException $e) {
                $this->log->warning('Using LDAP with paging failed. Trying to use fallback.');
                $tmp_result = $this->runReadAllUsersPartial($dn);
            }
        } else {
            $tmp_result = $this->runReadAllUsersPartial($dn);
        }

        if (!$tmp_result->numRows()) {
            $this->log->notice('No users found. Aborting.');
        }
        $this->log->info('Found ' . $tmp_result->numRows() . ' users.');
        $attribute = strtolower($this->settings->getUserAttribute());
        foreach ($tmp_result->getRows() as $data) {
            if (isset($data[$attribute])) {
                $this->readUserData($data[$attribute], false, false);
            } else {
                $this->log->warning('Unknown error. No user attribute found.');
            }
        }
        unset($tmp_result);

        return true;
    }

    /**
     * read all users with ldap paging
     *
     * @param string $dn
     * @return ilLDAPResult
     * @throws ilLDAPPagingException
     */
    private function runReadAllUsersPaged($dn)
    {
        $filter = '(&' . $this->settings->getFilter();
        $filter .= ('(' . $this->settings->getUserAttribute() . '='.$letter.'*))');
        $this->log->info('Searching with ldap search and filter ' . $filter . ' in ' . $dn);

        $tmp_result = new ilLDAPResult($this->lh);
        $cookie = '';
        $estimated_results = 0;
        do {
            try {
                $res = ldap_control_paged_result($this->lh, self::PAGINATION_SIZE, true, $cookie);
                if ($res === false) {
                    throw new ilLDAPPagingException('Result pagination failed.');
                }

            } catch (Exception $e) {
                $this->log->warning('Result pagination failed with message: ' . $e->getMessage());
                throw new ilLDAPPagingException($e->getMessage());
            }

            $res = $this->queryByScope(
                $this->settings->getUserScope(),
                $dn,
                $filter,
                array($this->settings->getUserAttribute())
            );
            $tmp_result->setResult($res);
            $tmp_result->run();
            try {
                ldap_control_paged_result_response($this->lh, $res, $cookie, $estimated_results);
                $this->log->debug('Estimated number of results: ' . $estimated_results.'  | nb_reels '.$tmp_result->numRows());
            } catch (Exception $e) {
                $this->log->warning('Result pagination failed with message: ' . $e->getMessage());
                throw new ilLDAPPagingException($e->getMessage());
            }
        } while ($cookie !== null && $cookie != '');

        // finally reset cookie
        ldap_control_paged_result($this->lh, 10000, false, $cookie);
        return $tmp_result;
    }

    /**
     * read all users partial by alphabet
     *
     * @param string $dn
     * @return ilLDAPResult
     */
    private function runReadAllUsersPartial($dn)
    {
    	global $DIC;
        $ilSetting = $DIC['ilSetting'];
        $filter = $this->settings->getFilter();
        $page_filter = array('a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z');
        $chars = array('a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z');
        $tmp_result = new ilLDAPResult($this->lh);
	switch ($ilSetting->get('formatUid')){
	case 0:
	/* en cas de non prise encharge de la pagination par le LDAP (cas général) */
		array_push($chars,'-');
		foreach ($page_filter as $letter) {
            		$new_filter = '(&';
            		$new_filter .= $filter;

            		if ($letter === '-') {
                		$new_filter .= ('(!(|');
                		foreach ($chars as $char) {
                    		$new_filter .= ('(' . $this->settings->getUserAttribute() . '=' . $char . '*)');
                		}
                		$new_filter .= ')))';
            		} else {
                		$new_filter .= ('(' . $this->settings->getUserAttribute() . '=' . $letter . '*))');
            	}
            	$this->logger->info('Searching with ldap search and filter ' . $new_filter . ' in ' . $dn);
            	$res = $this->queryByScope(
                	$this->settings->getUserScope(),
                	$dn,
                	$new_filter,
                	array($this->settings->getUserAttribute())
            	);
            	$tmp_result->setResult($res);
            	$tmp_result->run();
        }
	break;
	case 1:
	/* recherche des users par odre alphabéthique a*,b*,c*... */
	    	foreach ($page_filter as $letter) {
            		$new_filter = '(&';
            		$new_filter .= $filter;
	    		$new_filter .= ('(' . $this->settings->getUserAttribute() . '=' . $letter . '*))');
            		$this->log->info('Searching with ldap search and filter ' . $new_filter . ' in ' . $dn);
            		$res = $this->queryByScope(
            			$this->settings->getUserScope(),
                		$dn,
                		$new_filter,
                		array($this->settings->getUserAttribute())
            		);
            	$tmp_result->setResult($res);
            	$tmp_result->run();
            	$this->log->debug('Trouvé '.$tmp_result->numRows().' users');	       
            	}
	break;
        case 2:
        /* recherche des users par odre alphabéthique *.aa*,*.ab*,*.ac*...,puis *.ba*,*.bb*, ... */
            	foreach ($page_filter as $letter){
            		foreach($chars as $char){
            			$letter_enCours = '*.'.$letter;
            			$letter_enCours .= $char;
            			$new_filter = '(&';
            			$new_filter .= $filter;
            			$new_filter .= ('(' . $this->settings->getUserAttribute() . '=' . $letter_enCours . '*))');
                 	   	$this->log->info('Searching with ldap search and filter ' . $new_filter . ' in ' . $dn);
            			$res = $this->queryByScope(
                			$this->settings->getUserScope(),
                			$dn,
                			$new_filter,
                		array($this->settings->getUserAttribute())
            			);
            			$tmp_result->setResult($res);
            			$tmp_result->run();
            			$this->log->debug('Trouvé '.$tmp_result->numRows().' users');	
            		}
            	}
          break;
            
            case 3:
            /* recherche des users par odre alphabéthique a.a*,a.b*,a.c*...,puis b.a*,b.b*, ... */
            	foreach ($page_filter as $letter){
            		foreach($chars as $char){
            			$letter_enCours = $letter;
            			$letter_enCours .= '.'.$char;
            			$new_filter = '(&';
            			$new_filter .= $filter;
            			$new_filter .= ('(' . $this->settings->getUserAttribute() . '=' . $letter_enCours . '*))');
                	    	$this->log->info('Searching with ldap search and filter ' . $new_filter . ' in ' . $dn);
            			$res = $this->queryByScope(
                			$this->settings->getUserScope(),
                			$dn,
                			$new_filter,
                			array($this->settings->getUserAttribute())
            				);
            			$tmp_result->setResult($res);
            			$tmp_result->run();
            			$this->log->debug('Trouvé '.$tmp_result->numRows().' users');		 		
            		}
          	  }
            break;	
	}
        return $tmp_result;
    }

    /**
     * check group membership
     * @param string login name
     * @param array user data
     * @return bool
     */
    public function checkGroupMembership($a_ldap_user_name, $ldap_user_data)
    {
        $group_names = $this->getServer()->getGroupNames();
        
        if (!count($group_names)) {
            $this->getLogger()->debug('No LDAP group restrictions found');
            return true;
        }
        
        $group_dn = $this->getServer()->getGroupDN();
        if (
            $group_dn &&
            (substr($group_dn, -1) != ',')
        ) {
            $group_dn .= ',';
        }
        $group_dn .= $this->getServer()->getBaseDN();
        
        foreach ($group_names as $group) {
            $user = $a_ldap_user_name;
            if ($this->getServer()->enabledGroupMemberIsDN()) {
                if ($this->getServer()->enabledEscapeDN()) {
                    $user = ldap_escape($ldap_user_data['dn'], "", LDAP_ESCAPE_FILTER);
                } else {
                $user = $ldap_user_data['dn'];
            }
            }
            
            $filter = sprintf(
                '(&(%s=%s)(%s=%s)%s)',
                $this->getServer()->getGroupAttribute(),
                $group,
                $this->getServer()->getGroupMember(),
                $user,
                $this->getServer()->getGroupFilter()
            );
            $this->getLogger()->debug('Current group search base: ' . $group_dn);
            $this->getLogger()->debug('Current group filter: ' . $filter);
            
            $res = $this->queryByScope(
                $this->getServer()->getGroupScope(),
                $group_dn,
                $filter,
                [$this->getServer()->getGroupMember()]
            );
            
            $this->getLogger()->dump($res);
            
            $tmp_result = new ilLDAPResult($this->lh, $res);
            $tmp_result->run();
            $group_result = $tmp_result->getRows();
            
            $this->getLogger()->debug('Group query returned: ');
            $this->getLogger()->dump($group_result, ilLogLevel::DEBUG);
            
            if (count($group_result)) {
                return true;
            }
        }
        
        // group restrictions failed check optional membership
        if ($this->getServer()->isMembershipOptional()) {
            $this->getLogger()->debug('Group restrictions failed, checking user filter.');
            if ($this->readUserData($a_ldap_user_name, true, true)) {
                $this->getLogger()->debug('User filter matches.');
                return true;
            }
        }
        $this->getLogger()->debug('Group restrictions failed.');
        return false;
    }
    

    /**
     * Fetch group member ids
     *
     * @access public
     *
     */
    private function fetchGroupMembers($a_name = '')
    {
        $group_name = strlen($a_name) ? $a_name : $this->settings->getGroupName();
        
        // Build filter
        $filter = sprintf(
            '(&(%s=%s)%s)',
            $this->settings->getGroupAttribute(),
            $group_name,
            $this->settings->getGroupFilter()
        );
        
        
        // Build search base
        if (($gdn = $this->settings->getGroupDN()) && substr($gdn, -1) != ',') {
            $gdn .= ',';
        }
        $gdn .= $this->settings->getBaseDN();
        
        $this->log->debug('Using filter ' . $filter);
        $this->log->debug('Using DN ' . $gdn);
        $res = $this->queryByScope(
            $this->settings->getGroupScope(),
            $gdn,
            $filter,
            array($this->settings->getGroupMember())
        );
            
        $tmp_result = new ilLDAPResult($this->lh, $res);
        $tmp_result->run();
        $group_data = $tmp_result->getRows();
        
        
        if (!$tmp_result->numRows()) {
            $this->log->info('No group found.');
            return false;
        }
                
        $attribute_name = strtolower($this->settings->getGroupMember());
        
        // All groups
        foreach ($group_data as $data) {
            if (is_array($data[$attribute_name])) {
	            $this->log->debug('Found ' . count($data[$attribute_name]) . ' group members for group ' . $data['dn']);
                foreach ($data[$attribute_name] as $name) {
                    $this->readUserData($name, true, true);
                }
            } else {
                $this->readUserData($data[$attribute_name], true, true);
            }
        }
        unset($tmp_result);
        return;
    }
    
    /**
     * Read user data
     * @param bool check dn
     * @param bool use group filter
     * @access private
     */
    private function readUserData($a_name, $a_check_dn = false, $a_try_group_user_filter = false)
    {
    	global $DIC;
        $ilSetting = $DIC['ilSetting'];
    
        $filter = $this->settings->getFilter();
        if ($a_try_group_user_filter) {
            if ($this->settings->isMembershipOptional()) {
                $filter = $this->settings->getGroupUserFilter();
            }
        }
        
        // Build filter
        if ($this->settings->enabledGroupMemberIsDN() and $a_check_dn) {
            $dn = $a_name;
            #$res = $this->queryByScope(IL_LDAP_SCOPE_BASE,$dn,$filter,$this->user_fields);

            $fields = array_merge($this->user_fields, array('useraccountcontrol'));
            $res = $this->queryByScope(IL_LDAP_SCOPE_BASE, strtolower($dn), $filter, $fields);
        } else {
            $filter = sprintf(
                '(&(%s=%s)%s)',
                $this->settings->getUserAttribute(),
                $a_name,
                $filter
            );

            // Build search base
            if (($dn = $this->settings->getSearchBase()) && substr($dn, -1) != ',') {
                $dn .= ',';
            }
            $dn .= $this->settings->getBaseDN();
            $fields = array_merge($this->user_fields, array('useraccountcontrol'));
            $res = $this->queryByScope($this->settings->getUserScope(), strtolower($dn), $filter, $fields);
        }
        
        
        $tmp_result = new ilLDAPResult($this->lh, $res);
        $tmp_result->run();
        if (!$tmp_result->numRows()) {
            $this->log->info('LDAP: No user data found for: ' . $a_name);
            unset($tmp_result);
            return false;
        }
        
        if ($user_data = $tmp_result->get()) {
            if (isset($user_data['useraccountcontrol'])) {
                if (($user_data['useraccountcontrol'] & 0x02)) {
                    $this->log->notice('LDAP: ' . $a_name . ' account disabled.');
                    return;
                }
            }
            
            $account = $user_data[strtolower($this->settings->getUserAttribute())];
            if (is_array($account)) {
                $user_ext = strtolower(array_shift($account));
            } else {
                $user_ext = strtolower($account);
            }
            
            // auth mode depends on ldap server settings
            if ($ilSetting->get('sync_mindefConnect')==0){
            	$auth_mode = $this->settings->getAuthenticationMappingKey();
            }
            else{
            	$auth_mode = 'oidc';
            }
            
            $user_data['ilInternalAccount'] = ilObjUser::_checkExternalAuthAccount($auth_mode, $user_ext);
            $this->users[$user_ext] = $user_data;
        }
        return true;
    }

    /**
     * Parse authentication mode
     * @return string auth mode
     */
    private function parseAuthMode()
    {
        return $this->settings->getAuthenticationMappingKey();
    }
    
    /**
     * Query by scope
     * IL_SCOPE_SUB => ldap_search
     * IL_SCOPE_ONE => ldap_list
     *
     * @access private
     * @param
     *
     */
    private function queryByScope($a_scope, $a_base_dn, $a_filter, $a_attributes)
    {
        $a_filter = $a_filter ? $a_filter : "(objectclass=*)";

        switch ($a_scope) {
            case IL_LDAP_SCOPE_SUB:
                $res = @ldap_search($this->lh, $a_base_dn, $a_filter, $a_attributes);
                break;
                
            case IL_LDAP_SCOPE_ONE:
                $res = @ldap_list($this->lh, $a_base_dn, $a_filter, $a_attributes);
                break;
            
            case IL_LDAP_SCOPE_BASE:

                $res = @ldap_read($this->lh, $a_base_dn, $a_filter, $a_attributes);
                break;

            default:
                $this->log->warning("LDAP: LDAPQuery: Unknown search scope");
        }
        
        $error = ldap_error($this->lh);
        if (strcmp('Success', $error) !== 0) {
            $this->getLogger()->warning($error);
            $this->getLogger()->warning('Base DN:' . $a_base_dn);
            $this->getLogger()->warning('Filter: ' . $a_filter);
        }
        
        return $res;
    }
    
    /**
     * Connect to LDAP server
     *
     * @access private
     * @throws ilLDAPQueryException
     *
     */
    private function connect()
    {
        $this->lh = @ldap_connect($this->ldap_server_url);
        
        // LDAP Connect
        if (!$this->lh) {
            throw new ilLDAPQueryException("LDAP: Cannot connect to LDAP Server: " . $this->settings->getUrl());
        }
        // LDAP Version
        if (!ldap_set_option($this->lh, LDAP_OPT_PROTOCOL_VERSION, $this->settings->getVersion())) {
            throw new ilLDAPQueryException("LDAP: Cannot set version to: " . $this->settings->getVersion());
        }
        // Switch on referrals
        if ($this->settings->isActiveReferrer()) {
            if (!ldap_set_option($this->lh, LDAP_OPT_REFERRALS, true)) {
                throw new ilLDAPQueryException("LDAP: Cannot switch on LDAP referrals");
            }
            #@ldap_set_rebind_proc($this->lh,'referralRebind');
        } else {
            ldap_set_option($this->lh, LDAP_OPT_REFERRALS, false);
            $this->log->debug('Switching referrals to false.');
        }
        // Start TLS
        if ($this->settings->isActiveTLS()) {
            if (!ldap_start_tls($this->lh)) {
                throw new ilLDAPQueryException("LDAP: Cannot start LDAP TLS");
            }
        }
    }
    
    /**
     * Bind to LDAP server
     *
     * @access public
     * @param int binding_type IL_LDAP_BIND_DEFAULT || IL_LDAP_BIND_ADMIN
     * @throws ilLDAPQueryException on connection failure.
     *
     */
    public function bind($a_binding_type = IL_LDAP_BIND_DEFAULT, $a_user_dn = '', $a_password = '')
    {
        switch ($a_binding_type) {
            case IL_LDAP_BIND_TEST:
                ldap_set_option($this->lh, LDAP_OPT_NETWORK_TIMEOUT, ilLDAPServer::DEFAULT_NETWORK_TIMEOUT);
                // fall through
                // no break
            case IL_LDAP_BIND_DEFAULT:
                // Now bind anonymously or as user
                if (
                    IL_LDAP_BIND_USER == $this->settings->getBindingType() &&
                    strlen($this->settings->getBindUser())
                ) {
                    $user = $this->settings->getBindUser();
                    $pass = $this->settings->getBindPassword();

                    define('IL_LDAP_REBIND_USER', $user);
                    define('IL_LDAP_REBIND_PASS', $pass);
                    $this->log->debug('Bind as ' . $user);
                } else {
                    $user = $pass = '';
                    $this->log->debug('Bind anonymous');
                }
                break;
                
            case IL_LDAP_BIND_ADMIN:
                $user = $this->settings->getRoleBindDN();
                $pass = $this->settings->getRoleBindPassword();
                
                if (!strlen($user) or !strlen($pass)) {
                    $user = $this->settings->getBindUser();
                    $pass = $this->settings->getBindPassword();
                }

                define('IL_LDAP_REBIND_USER', $user);
                define('IL_LDAP_REBIND_PASS', $pass);
                break;
                
            case IL_LDAP_BIND_AUTH:
                $this->log->debug('Trying to bind as: ' . $a_user_dn);
                $user = $a_user_dn;
                $pass = $a_password;
                break;
                
                
            default:
                throw new ilLDAPQueryException('LDAP: unknown binding type in: ' . __METHOD__);
        }
        
        if (!@ldap_bind($this->lh, $user, $pass)) {
            throw new ilLDAPQueryException('LDAP: Cannot bind as ' . $user . ' with message: ' . ldap_err2str(ldap_errno($this->lh)) . ' Trying fallback...', ldap_errno($this->lh));
        } else {
            $this->log->debug('Bind successful.');
        }
    }
    
    /**
     * fetch required fields of user profile data
     *
     * @access private
     * @param
     *
     */
    private function fetchUserProfileFields()
    {
        include_once('Services/LDAP/classes/class.ilLDAPRoleAssignmentRules.php');
        
        $this->user_fields = array_merge(
            array($this->settings->getUserAttribute()),
            array('dn'),
            $this->mapping->getFields(),
            ilLDAPRoleAssignmentRules::getAttributeNames($this->getServer()->getServerId())
        );
    }
    
    
    /**
     * Unbind
     *
     * @access private
     * @param
     *
     */
    private function unbind()
    {
        if ($this->lh) {
            @ldap_unbind($this->lh);
        }
    }
    
    
    /**
     * Destructor unbind from ldap server
     *
     * @access private
     * @param
     *
     */
    public function __destruct()
    {
        if ($this->lh) {
            @ldap_unbind($this->lh);
        }
    }

    /**
     * Check if pagination is enabled (rfc: 2696)
     * @return bool
     */
    public function checkPaginationEnabled() : bool
    {
        if ($this->getServer()->getVersion() != 3) {
            $this->log->info('Pagination control unavailable for ldap v' . $this->getServer()->getVersion());
            return false;
        }

        $result = ldap_read($this->lh, '', '(objectClass=*)', [self::IL_LDAP_SUPPORTED_CONTROL]);
        if ($result === false) {
            $this->log->warning('Failed to query for pagination control');
            return false;
        }
        $entries = (array) (ldap_get_entries($this->lh, $result)[0] ?? []);
        if (
            array_key_exists(strtolower(self::IL_LDAP_SUPPORTED_CONTROL), $entries) &&
            is_array($entries[strtolower(self::IL_LDAP_SUPPORTED_CONTROL)]) &&
            in_array(self::IL_LDAP_CONTROL_PAGEDRESULTS, $entries[strtolower(self::IL_LDAP_SUPPORTED_CONTROL)])
        ) {
            $this->log->info('Using paged control');
            return true;
        }
        $this->log->info('Paged control disabled');
        return false;
    }


}
