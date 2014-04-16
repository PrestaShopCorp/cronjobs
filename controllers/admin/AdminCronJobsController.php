<?php
/*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2011 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

class AdminCronJobsController extends ModuleAdminController
{
	public function __construct()
	{
		if (Tools::getValue('token') != Configuration::get('CRONJOBS_EXECUTION_TOKEN'))
			die;
			
		parent::__construct();
		
		$this->postProcess();
		
		die;
	}
	
	public function postProcess()
	{
		$this->module->sendCallback();
		
		ob_start();
		
		$this->runModulesCrons();
		$this->runTasksCrons();
		
		ob_end_clean();
	}
	
	protected function runModulesCrons()
	{
		$query = 'SELECT * FROM '._DB_PREFIX_.$this->module->name.' WHERE `active` = 1 AND `id_module` IS NOT NULL';
		$crons = Db::getInstance()->executeS($query);
		
		if (is_array($crons) && (count($crons) > 0))
			foreach ($crons as &$cron)
				if ($this->shouldBeExecuted($cron) == true)
					Hook::exec('actionCronJob', array(), $cron['id_module']);
	}
	
	protected function runTasksCrons()
	{
		$query = 'SELECT * FROM '._DB_PREFIX_.$this->module->name.' WHERE `active` = 1 AND `id_module` IS NULL';
		$crons = Db::getInstance()->executeS($query);
		
		if (is_array($crons) && (count($crons) > 0))
			foreach ($crons as &$cron)
				if ($this->shouldBeExecuted($cron) == true)
					$result = Tools::file_get_contents(urldecode($cron['task']), false);
	}
	
	protected function shouldBeExecuted($cron)
	{
		extract($cron);
		
		$date = $orig = new DateTime();
		$date->modify(date('F', strtotime('January +'.((($month == -1) ? date('m') : $month) - 1).' months')));
		$date->setDate($date->format('Y'), $date->format('m'), ($day == -1) ? date('d') : $day);
		
		if ($day_of_week != -1)
			$date->modify(date('l', strtotime('Sunday +'.$day_of_week.' days')));
		else
			$day_of_week = date('l');
		
		$date->setTime(($hour == -1) ? date('H') : $hour, date('i'), date('s'));
		
		$interval = $orig->diff($date);
		if ($interval->format('%R') == '-')
			$date->modify('+1 year');

		return (bool)$this->validateDate($day_of_week.' '.$date->format('Y-m-d H'));
	}
	
	protected function validateDate($date, $format = 'l Y-m-d H')
	{
		$temp = DateTime::createFromFormat($format, $date);
		return $temp && $temp->format($format) == $date;
	}
	
}
