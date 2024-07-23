<?php
//use ILIAS/DI/Container;
require_once(__DIR__.'/class.ilsyncCron.php');

class ilsyncPlugin extends ilCronHookPlugin {

	const PLUGIN_ID = "sync";
	const PLUGIN_NAME = "sync";
	

	/** @var Container $dic */
    	private $dic;

    
	public function __construct() {
		parent::__construct();
		global $DIC; /** @var Container $DIC */

		$this->dic = $DIC;
		
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
