<?php
//use ILIAS/DI/Container;
require_once(__DIR__.'/class.ilsyncCron.php');

class ilsyncPlugin extends ilCronHookPlugin {

	const PLUGIN_ID = "sync";
	const PLUGIN_NAME = "sync";
	

	/** @var Container $dic */
    	private $dic;

    
	public function __construct() {
		global $DIC; 
		$this->db = $DIC->database();
		$this->dic = $DIC;
		parent::__construct($this->db, $DIC["component.repository"], self::PLUGIN_ID);
	}
	
	public static function getInstance(): ?ilsyncPlugin
	{
		return new ilsyncPlugin;
	}
	
	
	/**
	 * @inheritdoc
	 */
	public function getPluginName():string {
		return self::PLUGIN_NAME;
	}
	
	
	/**
	 * @inheritdoc
	 */
	public function getCronJobInstances():array {
		return [
			new ilsyncCron()
		];
	}
	
	
	/**
	 * @inheritdoc
	 */
	public function getCronJobInstance($a_job_id):ilCronJob
	{
		return new ilsyncCron();
	}
}
