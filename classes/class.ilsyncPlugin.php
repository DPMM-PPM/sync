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
	public function getPluginName() {
		return self::PLUGIN_NAME;
	}
	
	
	/**
	 * @inheritdoc
	 */
	public function getCronJobInstances() {
		return [
			new ilsyncCron()
		];
	}
	
	
	/**
	 * @inheritdoc
	 */
	public function getCronJobInstance($a_job_id)
	{
	return new ilsyncCron();
	/*
		switch ($a_job_id)
		{
			case ilsyncCron::JOB_ID:
				return new ilsyncCron();

			default:
				return null;
		}*/
	}


	/**
	 * @inheritdoc
	 */
	 /*
	protected function deleteData()
	{
		// Nothing to delete
	}
    
    
        /**
     * @param string $component
     * @param string $event
     * @param array  $parameters
     */
     /*
    public function handleEvent($component, $event, $parameters) {
		
       
    }

    protected function afterUninstall()
    {
       
    }*/
}
