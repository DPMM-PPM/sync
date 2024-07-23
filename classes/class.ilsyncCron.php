<?php

require_once('./Services/Cron/classes/class.ilCronManager.php');
declare(strict_types=1);

class ilsyncCron extends ilCronJob
  {
    public const JOB_ID = "sync";
    private ilLanguage $lng;
    private ilLogger $logger;
    private ilCronManager $cronManager;

    private int $counter = 0;
    private ilSetting $settings; 
    private int $period;
    private const DEFAULT_PERIOD = 3;
    
    public function __construct()
      {
        global $DIC;

        $this->logger = $DIC->logger()->auth();
        $this->cronManager = $DIC->cron()->manager();
        //$this->lng = $DIC->language();
        //$this->lng->loadLanguageModule('ldap');
      }

    public function getId(): string
    {
        return self::JOB_ID;
    }
    
    
    public function getTitle(): string
    {
        return ilsyncPlugin::getInstance()->txt('ldap_plg_sync_cron_title');
    }
    
     public function getDescription(): string
    {
        return ilsyncPlugin::getInstance()->txt('ldap_plg_sync_cron_info');
    }

    public function getDefaultScheduleType(): int
    {
        return self::SCHEDULE_TYPE_DAILY;
    }

    public function getDefaultScheduleValue(): string
    {
        return 'jour';
    }

    public function hasAutoActivation(): bool
    {
        return false;
    }

    public function hasFlexibleSchedule(): bool
    {
        return false;
    }
    
    public function hasCustomSettings(): bool
    {
    	return true;
    }
    public function addCustomSettingsToForm(ilPropertyFormGUI $a_form): void
    {
    	global $DIC;
    	$ilSetting = $DIC['ilSetting'];
    	
      	/* Ajout des boutons radio de sélection du jour d'éxecution */
      	$sub_mlist = new ilRadioGroupInputGUI(ilsyncPlugin::getInstance()->txt('ldap_plg_sync_day_of_run'),'grpWeekDay');
    	$jours = array(ilsyncPlugin::getInstance()->txt('ldap_plg_sync_sun'),
    			ilsyncPlugin::getInstance()->txt('ldap_plg_sync_mon'),
    			ilsyncPlugin::getInstance()->txt('ldap_plg_sync_tue'),
    			ilsyncPlugin::getInstance()->txt('ldap_plg_sync_wed'),
    			ilsyncPlugin::getInstance()->txt('ldap_plg_sync_thu'),
    			ilsyncPlugin::getInstance()->txt('ldap_plg_sync_fri'),
    			ilsyncPlugin::getInstance()->txt('ldap_plg_sync_sat'),
    			ilSyncPlugin::getInstance()->txt('ldap_plg_sync_all')
    			);
    	foreach ($jours as $key=>$jour){
    		$sub_mlist->addOption(new ilRadioOption($jour,$key,''));
    		}
    	$sub_mlist->setValue($ilSetting->get('runOnDay'));
    	$a_form->addItem($sub_mlist);
    	
    	/* Ajout de la case à cocher synchro mindefConnect */
    	$chkBox = new ilCheckboxInputGUI(
            ilsyncPlugin::getInstance()->txt('ldap_plg_sync_mindefConnect'),'sync_mindefConnect');
        $chkBox->setInfo(ilsyncPlugin::getInstance()->txt('ldap_plg_sync_mindefConnect_info'));
        $chkBox->setChecked((bool) $ilSetting->get('sync_mindefConnect'),'0');
        $a_form->addItem($chkBox);
        
        /* Ajout des boutons radio choix du format d'uid */
        $choixUid = new ilRadioGroupInputGUI(ilsyncPlugin::getInstance()->txt('ldap_plg_sync_uid_choice'),'uidChoice');
        $choixUid->setInfo(ilsyncPlugin::getInstance()->txt('ldap_plg_sync_uid_choice_info'));
        $choixUid->addOption(new ilRadioOption('Pagination gérée par le LDAP',0,ilsyncPlugin::getInstance()->txt('ldap_plg_sync_uid_choice_pagination_info')));
        $choixUid->addOption(new ilRadioOption('Alphabétique',1,ilsyncPlugin::getInstance()->txt('ldap_plg_sync_uid_choice_default_info')));
        $choixUid->addOption(new ilRadioOption('Annudef',2,ilsyncPlugin::getInstance()->txt('ldap_plg_sync_uid_choice_annudef_info')));
        $choixUid->addOption(new ilRadioOption('dr-cpt',3,ilsyncPlugin::getInstance()->txt('ldap_plg_sync_uid_choice_drcpt_info')));
        $choixUid->setValue(0);
        $choixUid->setValue($ilSetting->get('formatUid'));
        $a_form->addItem($choixUid);
    }
	  
    public function saveCustomSettings(ilPropertyFormGUI $a_form): bool
    {
    	global $DIC;
    	$ilSetting = $DIC['ilSetting'];
    	$ilSetting->set('runOnDay',$_POST['grpWeekDay']);
    	$ilSetting->set('sync_mindefConnect',$_POST['sync_mindefConnect']);
    	$ilSetting->set('formatUid',$_POST['uidChoice']);
    	return true; 
    }
    
    public function run(): ilCronJobResult
    {
    require_once('class.ilsyncQuery.php');
        global $DIC;
        //$ilLog = $DIC['ilLog'];
        $ilSetting = $DIC['ilSetting'];
	    
        $currentDate = getDate();
        $currentDay = $currentDate['wday'];
        if ($currentDay == $ilSetting->get('runOnDay') or $ilSetting->get('runOnDay')==7){
        	$this->logger->info("jour OK : Synchro lancée");
        
        $status = ilCronJobResult::STATUS_NO_ACTION;

        $messages = array();
                
        foreach (ilLDAPServer::_getCronServerIds() as $server_id) {
            try {
                $current_server = new ilLDAPServer($server_id);
                $current_server->doConnectionCheck();
                $this->logger->info("LDAP: starting user synchronization for " . $current_server->getName());
		$ldap_query = new ilsyncQuery($current_server);
                $ldap_query->bind();

                if (is_array($users = $ldap_query->fetchUsers())) {
                    // Deactivate ldap users that are not in the list
                    $this->deactivateUsers($current_server, $users);
                }

                if (count($users)) {
                    ilUserCreationContext::getInstance()->addContext(ilUserCreationContext::CONTEXT_LDAP);

                    $offset = 0;
                    $limit = 500;
                    while ($user_sliced = array_slice($users, $offset, $limit, true)) {
                        $this->logger->info("LDAP: Starting update/creation of users ...");
                        $this->logger->info("LDAP: Offset: " . $offset);
                        $ldap_to_ilias = new ilLDAPAttributeToUser($current_server);
                        if ($ilSetting->get('sync_mindefConnect','0')==1){
                        	$this->logger->debug("LDAP: starting synchronization MINDEFCONNECT");
                        	$ldap_to_ilias->setNewUserAuthMode('oidc');
                        }
                        else{
                        	$ldap_to_ilias->setNewUserAuthMode($current_server->getAuthenticationMappingKey());
                        	$this->logger->debug("Connexion :".$current_server->getAuthenticationMappingKey());
                        }
                        
                        $ldap_to_ilias->setUserData($user_sliced);
                        $ldap_to_ilias->refresh();
                        $this->logger->info("LDAP: Finished update/creation");

                        $offset += $limit;

                        $this->cronManager->ping($this->getId());
                    }
                    $this->counter++;
                } else {
                    $this->logger->info("LDAP: No users for update/create. Aborting.");
                }
            } catch (ilLDAPQueryException $exc) {
                $mess = $exc->getMessage();
                $this->logger->info($mess);

                $messages[] = $mess;
            }
        }


        if ($this->counter) {
            $status = ilCronJobResult::STATUS_OK;
        }
        $result = new ilCronJobResult();
        if (count($messages)) {
            $result->setMessage(implode("\n", $messages));
            }
        
        }
        else
        {
        $result = new ilCronJobResult();
        $this->logger->info("Pas de synchronisation LDAP planifiée aujourd'hui");
        $result->setMessage(ilsyncPlugin::getInstance()->txt('ldap_plg_sync_not_good_day'));
        $status = ilCronJobResult::STATUS_NO_ACTION;
        }
               
        $result->setStatus($status);
        return $result;
    }

    /**
     * Deactivate users that are disabled in LDAP
     */
    private function deactivateUsers(ilLDAPServer $server, array $a_ldap_users): void
    {
        $inactive = [];

        foreach (ilObjUser::_getExternalAccountsByAuthMode($server->getAuthenticationMappingKey(), true) as $usr_id => $external_account) {
            if (!array_key_exists($external_account, $a_ldap_users)) {
                $inactive[] = $usr_id;
            }
        }
        if (count($inactive)) {
            ilObjUser::_toggleActiveStatusOfUsers($inactive, false);
            $this->logger->info('LDAP: Found ' . count($inactive) . ' inactive users.');

            $this->counter++;
        } else {
            $this->logger->info('LDAP: No inactive users found');
        }
    }
/*
    public function addToExternalSettingsForm(int $a_form_id, array &$a_fields, bool $a_is_active): void
    {
        if ($a_form_id === ilAdministrationSettingsFormHandler::FORM_LDAP) {
            $a_fields["ldap_user_sync_cron"] = [$a_is_active ?
                $this->lng->txt("enabled") :
                $this->lng->txt("disabled"),
                ilAdministrationSettingsFormHandler::VALUE_BOOL];
        }
    }*/
  }
