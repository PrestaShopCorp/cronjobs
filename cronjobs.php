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

if (!defined('_PS_VERSION_'))
	exit;

class CronJobs extends PaymentModule
{
	public function __construct()
	{
		$this->name = 'cronjobs';
		$this->tab = 'admninistration';
		$this->version = '1.0';
		$this->module_key = '';

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		$this->author = 'PrestaShop';
		
		$this->bootstrap = true;
		$this->display = 'view';

		parent::__construct();

		$this->displayName = $this->l('Cron jobs - By PrestaShop');
		$this->description = $this->l('Manage all of your cron jobs at once.');

		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
	}
	
	public function install()
	{
		Configuration::updateValue('CRONJOBS_MODE', 'webservice');
		
		$token = Tools::encrypt(_PS_ADMIN_DIR_.time());
		Configuration::updateValue('CRONJOBS_EXECUTION_TOKEN', $token);
		
		return $this->installDb() && parent::install() && $this->registerHook('backOfficeHeader');
	}
	
	public function uninstall()
	{
		Configuration::deleteByName('CRONJOBS_MODE');
		
		return $this->uninstallDb() && parent::uninstall();
	}

	public function installDb()
	{
		return Db::getInstance()->execute('
			CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.$this->name.' (
			`id_cronjob` INTEGER(10) NOT NULL AUTO_INCREMENT, 
			`task` VARCHAR(254) NOT NULL,
			`hour` INTEGER NOT NULL,
			`day` INTEGER NOT NULL,
			`month` INTEGER NOT NULL,
			`day_of_week` INTEGER NOT NULL,
			`last_execution` VARCHAR(32) DEFAULT \'0\',
			PRIMARY KEY(`id_cronjob`))
			ENGINE='._MYSQL_ENGINE_.' default CHARSET=utf8'
		);
	}

	public function uninstallDb()
	{
		return Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.$this->name);
	}
	
	public function hookBackOfficeHeader()
	{
		$this->context->controller->addCSS($this->_path.'css/configure.css');
	}
	
	public function getContent()
	{
		if (Tools::isSubmit('submitCronJobs'))
			$this->_postProcessConfiguration();
		elseif (Tools::isSubmit('submitNewCronJob'))
			$this->_postProcessNewJob();

		if (Tools::isSubmit('newcronjobs'))
		{
			$this->context->smarty->assign(array(
				'module_dir' => $this->_path,
				'module_local_dir' => $this->local_path,
			));
			
			$back_url = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
				.'&token='.Tools::getAdminTokenLite('AdminModules');
			
			$output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/create_task.tpl').'<hr />';
			$output = $output.$this->renderForm($this->getNewJobForm(), $this->getNewJobFormValues(), 'submitNewCronJob', true, $back_url).'<hr />';
			$output = $output.$this->renderList().'<hr />';
		}
		elseif (Tools::isSubmit('deletecronjobs') && Tools::isSubmit('id_cronjob'))
		{
			$this->deleteCronJob((int)Tools::getValue('id_cronjob'));
		}
		else
		{
			$this->context->smarty->assign(array(
				'module_dir' => $this->_path,
				'module_local_dir' => $this->local_path,
			));
			
			$output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl').'<hr />';
			$output = $output.$this->renderForm($this->getForm(), $this->getFormValues(), 'submitCronJobs').'<hr />';
			$output = $output.$this->renderList().'<hr />';
		}
		
		return $output;
	}
	
	protected function renderForm($form, $form_values, $action, $cancel = false, $back_url = false)
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
	 
		$helper->identifier = $this->identifier;
		$helper->submit_action = $action;
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $form_values,
			'id_language' => $this->context->language->id,
			'languages' => $this->context->controller->getLanguages(),
			'back_url' => $back_url,
			'show_cancel_button' => $cancel,
		);

		return $helper->generateForm($form);
	}
	
	protected function renderList()
	{
		$helper = new HelperList();
		
		$helper->title = $this->l('Cron jobs list');
		$helper->table = $this->name;
		$helper->no_link = true;
		$helper->shopLinkType = '';
		$helper->identifier = 'id_cronjob';
		$helper->actions = array('delete');
		
		$helper->toolbar_btn['new'] = array(
			'href' => $this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
		   .'&newcronjobs=1&token='.Tools::getAdminTokenLite('AdminModules'),
			'desc' => $this->l('Add new task')
		);
		
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		
		return $helper->generateList($this->getListValues(), $this->getList());
	}

	protected function _postProcessConfiguration()
	{
		$values = array('advanced', 'automatic', 'webservice');
		
		if (Tools::isSubmit('cron_mode') == true)
		{
			$cron_mode = Tools::getValue('cron_mode');
			
			if (in_array($cron_mode, $values) == true)
			{
				Configuration::updateValue('CRONJOBS_MODE', $cron_mode);
				
				switch ($cron_mode)
				{
					case 'advanced':
						return $this->unregisterHook('header');
					case 'automatic':
						return $this->registerHook('header');
					case 'webservice':
						$this->unregisterHook('header');
						$this->registerOnWebservice();
						return;
					default:
						break;
				}
			}
		}
	}

	protected function _postProcessNewJob()
	{
		if ($this->isNewJobValid() == true)
		{
			$task = urlencode(Tools::getValue('task'));
			$hour = (int)Tools::getValue('hour');
			$day = (int)Tools::getValue('day');
			$month = (int)Tools::getValue('month');
			$day_of_week = (int)Tools::getValue('day_of_week');
			
			$query = 'INSERT INTO '._DB_PREFIX_.$this->name.'
				(`task`, `hour`, `day`, `month`, `day_of_week`, `last_execution`)
				VALUES (\''.$task.'\', \''.$hour.'\', \''.$day.'\', \''.$month.'\', \''.$day_of_week.'\', \''.null.'\')';
			
			return Db::getInstance()->execute($query);
		}
		
		return false;
	}
	
	protected function isNewJobValid()
	{
		if ((Tools::isSubmit('task') == true) &&
			(Tools::isSubmit('hour') == true) &&
			(Tools::isSubmit('day') == true) &&
			(Tools::isSubmit('month') == true) &&
			(Tools::isSubmit('day_of_week') == true))
		{
			$task = urlencode(Tools::getValue('task'));
			
			if (strpos($task, urlencode(Tools::getShopDomain(true, true).__PS_BASE_URI__)) !== 0)
				return false;
			
			$hour = Tools::getValue('hour');
			$day = Tools::getValue('day');
			$month = Tools::getValue('month');
			$day_of_week = Tools::getValue('day_of_week');
			
			if ((($hour >= -1) && ($hour < 24)) &&
				(($day >= -1) && ($day < 24)) &&
				(($month >= -1) && ($month <= 31)) &&
				(($day_of_week >= -1) && ($day_of_week < 7)))
			{
				return true;
			}
		}
		
		return false;
	}
	
	protected function registerOnWebservice()
	{
		d('registerOnWebservice');
	}
	
	protected function deleteCronJob($id_cronjob)
	{
		Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.$this->name.'
			WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');

		return Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
			.'&token='.Tools::getAdminTokenLite('AdminModules'));
	}
	
	protected function getForm()
	{
		$form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Settings'),
					'icon' => 'icon-cog',
				),
				'input' => array(
					array(
						'type' => 'radio',
						'name' => 'cron_mode',
						'label' => $this->l('Cron mode'),
						'desc' => $this->l('Choose the mode you want your cron jobs to work with'),
						'values' => array(
							array(
								'id' => 'advanced',
								'value' => 'advanced',
								'label' => $this->l('Advanced')
							),
							array(
								'id' => 'automatic',
								'value' => 'automatic',
								'label' => $this->l('Automatic')
							),
							array(
								'id' => 'webservice',
								'value' => 'webservice',
								'label' => $this->l('Webservice')
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
					'type' => 'submit',
					'class' => 'btn btn-default pull-right'
				),
			),
		);
		
		if (Configuration::get('CRONJOBS_MODE') == 'advanced')
		{
			$form['form']['input'][] = array(
				'type' => 'free',
				'name' => 'advanced_help',
				'col' => 12,
				'offset' => 0,
			);
		}
		
		return array($form);
	}
	
	protected function getFormValues()
	{
		$token = Configuration::get('CRONJOBS_EXECUTION_TOKEN');
		$client_path = $this->local_path.'classes/client.php token='.$token;

		return array(
			'cron_mode' => Configuration::get('CRONJOBS_MODE'),
			'advanced_help' =>
				'<div class="alert alert-info">
					<p>'.$this->l('To execute your cron jobs please insert the following command in your crontab manager').':</p>
					<br /><code>0 * * * * php '.$client_path.'</code>
				</div>'
		);
	}
	
	protected function getNewJobForm()
	{
		return array(
			array(
				'form' => array(
					'legend' => array(
						'title' => $this->l('New cron job'),
						'icon' => 'icon-plus',
					),
					'input' => array(
						array(
							'type' => 'text',
							'name' => 'task',
							'label' => $this->l('Task'),
							'desc' => $this->l('Define the link of your cron task'),
							'placeholder' => Tools::getShopDomain(true, true).__PS_BASE_URI__.basename(_PS_ADMIN_DIR_).'/cron_currency_rates.php?secure_key='.md5(_COOKIE_KEY_.Configuration::get('PS_SHOP_NAME')),
						),
						array(
							'type' => 'select',
							'name' => 'hour',
							'label' => $this->l('Frequency'),
							'options' => array(
								'query' => $this->getHoursFormOptions(),
								'id' => 'id', 'name' => 'name'
							),
						),
						array(
							'type' => 'select',
							'name' => 'day',
							'options' => array(
								'query' => $this->getDaysFormOptions(),
								'id' => 'id', 'name' => 'name'
							),
						),
						array(
							'type' => 'select',
							'name' => 'month',
							'options' => array(
								'query' => $this->getMonthsFormOptions(),
								'id' => 'id', 'name' => 'name'
							),
						),
						array(
							'type' => 'select',
							'name' => 'day_of_week',
							'options' => array(
								'query' => $this->getDaysofWeekFormOptions(),
								'id' => 'id', 'name' => 'name'
							),
						),
					),
					'submit' => array(
						'title' => $this->l('Save'),
						'type' => 'submit',
						'class' => 'btn btn-default pull-right'
					),
				),
			),
		);
	}
	
	protected function getNewJobFormValues()
	{
		return array(
			'task' => null,
			'hour' => -1,
			'day' => -1,
			'month' => -1,
			'day_of_week' => -1,
		);
	}
	
	protected function getHoursFormOptions()
	{
		$data = array(
			array('id' => '-1', 'name' => $this->l('Every hours'))
		);
		
		for ($hour = 0; $hour < 24; $hour += 1)
			$data[] = array('id' => $hour, 'name' => date('H:i', mktime($hour, 0, 0, 0, 1)));
		
		return  $data;
	}
	
	protected function getDaysFormOptions()
	{
		$data = array(
			array('id' => '-1', 'name' => $this->l('Every days'))
		);
		
		for ($day = 1; $day <= 31; $day += 1)
			$data[] = array('id' => $day, 'name' => $day);
		
		return  $data;
	}
	
	protected function getMonthsFormOptions()
	{
		$data = array(
			array('id' => '-1', 'name' => $this->l('Every months'))
		);
		
		for ($month = 1; $month <= 12; $month += 1)
			$data[] = array('id' => $month, 'name' => $this->l(date('F', mktime(0, 0, 0, $month, 1))));

		return  $data;
	}
	
	protected function getDaysofWeekFormOptions()
	{
		$data = array(
			array('id' => '-1', 'name' => $this->l('Every week days'))
		);
		
		for ($day = 1; $day <= 7; $day += 1)
			$data[] = array('id' => $day, 'name' => $this->l(date('l', mktime(0, 0, 0, 0, $day))));
		
		return  $data;
	}
	
	protected function getList()
	{
		$fields_list = array(
			'id_cronjob' => array(
				'title' => $this->l('#'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
			'task' => array(
				'title' => $this->l('Task'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
			'hour' => array(
				'title' => $this->l('Hour'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
			'day' => array(
				'title' => $this->l('Day'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
			'month' => array(
				'title' => $this->l('Month'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
			'day_of_week' => array(
				'title' => $this->l('Day of week'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
			'last_execution' => array(
				'title' => $this->l('Last execution'),
				'type' => 'text', 'search' => false, 'orderby' => false,
			),
		);
		
		return $fields_list;
	}

	protected function getListValues()
	{
		$tasks = Db::getInstance()->executeS('SELECT c.* FROM `'._DB_PREFIX_.$this->name.'` c');
		
		foreach ($tasks as &$task)
		{
			$task['task'] = Tools::safeOutput(urldecode($task['task']));
			$task['hour'] = ($task['hour'] == -1) ? $this->l('Every hours') : date('H:i', mktime((int)$task['hour'], 0, 0, 0, 1));
			$task['day'] = ($task['day'] == -1) ? $this->l('Every days') : (int)$task['day'];
			$task['month'] = ($task['month'] == -1) ? $this->l('Every months') : $this->l(date('F', mktime(0, 0, 0, (int)$task['month'], 1)));
			$task['day_of_week'] = ($task['day_of_week'] == -1) ? $this->l('Every week days') : $this->l(date('F', mktime(0, 0, 0, 0, (int)$task['day_of_week'])));
			$task['last_execution'] = ($task['last_execution'] == 0) ? $this->l('Never') : $this->l(date('c', $task['last_execution']));
		}
		
		return $tasks;
	}
}
