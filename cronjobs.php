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

define('TASKS_PREFIX', 1);
define('MODULES_PREFIX', 2);

class CronJobs extends PaymentModule
{
	protected $_errors;
	protected $_successes;
	
	public $webservice_url = 'https://cron.prestashop.com/crons/';
	
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
		Configuration::updateValue('CRONJOBS_WEBSERVICE_ID', 0);
		Configuration::updateValue('CRONJOBS_MODE', 'webservice');
		Configuration::updateValue('CRONJOBS_EXECUTION_TOKEN', Tools::encrypt(_PS_ADMIN_DIR_.time()));
		
		return $this->installDb() && $this->installTab() && parent::install() && $this->registerHook('backOfficeHeader');
	}
	
	public function uninstall()
	{
		Configuration::deleteByName('CRONJOBS_MODE');
		
		return $this->uninstallDb() && $this->uninstallTab() && parent::uninstall();
	}

	public function installDb()
	{
		return Db::getInstance()->execute('
			CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.$this->name.' (
			`id_cronjob` INTEGER(10) NOT NULL AUTO_INCREMENT,
			`id_module` INTEGER(10) DEFAULT NULL, 
			`task` TEXT DEFAULT NULL,
			`hour` INTEGER DEFAULT \'-1\',
			`day` INTEGER DEFAULT \'-1\',
			`month` INTEGER DEFAULT \'-1\',
			`day_of_week` INTEGER DEFAULT \'-1\',
			`last_execution` VARCHAR(32) DEFAULT NULL,
			`active` BOOLEAN DEFAULT FALSE,
			PRIMARY KEY(`id_cronjob`),
			INDEX (`id_module`))
			ENGINE='._MYSQL_ENGINE_.' default CHARSET=utf8'
		);
	}

	public function uninstallDb()
	{
		return Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.$this->name);
	}

	public function installTab()
	{
		$tab = new Tab();
		$tab->active = 1;
		$tab->name = array();
		$tab->class_name = "AdminCronJobs";
		
		foreach (Language::getLanguages(true) as $lang)
			$tab->name[$lang['id_lang']] = "Cron Jobs";
		
		$tab->id_parent = (int)-1;
		$tab->module = $this->name;
		
		return $tab->add();
	}

	public function uninstallTab()
	{
		$id_tab = (int)Tab::getIdFromClassName('AdminCronJobs');
		
		if ($id_tab)
		{
			$tab = new Tab($id_tab);
			return $tab->delete();
		}
		
		return false;
	}
	
	public function hookBackOfficeHeader()
	{
		$this->context->controller->addCSS($this->_path.'css/configure.css');
	}
	
	public function getContent()
	{
		$output = null;
		
		if (Tools::isSubmit('submitCronJobs'))
			$this->_postProcessConfiguration();
		elseif (Tools::isSubmit('submitNewCronJob'))
			$submit_cron = $this->_postProcessNewJob();
		elseif (Tools::isSubmit('submitUpdateCronJob'))
			$submit_cron = $this->_postProcessUpdateJob();
		
		$this->context->smarty->assign(array(
			'module_dir' => $this->_path,
			'module_local_dir' => $this->local_path,
		));
		
		$this->context->smarty->assign('form_errors', $this->_errors);
		$this->context->smarty->assign('form_successes', $this->_successes);

		if (Tools::isSubmit('submitNewCronJob') || Tools::isSubmit('newcronjobs') || Tools::isSubmit('updatecronjobs'))
		{
			$back_url = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
				.'&token='.Tools::getAdminTokenLite('AdminModules');
				
			$output = $output.$this->context->smarty->fetch($this->local_path.'views/templates/admin/task.tpl').'<hr />';
		}
		
		if (Tools::isSubmit('newcronjobs'))
			$output = $output.$this->renderForm($this->getJobForm(), $this->getNewJobFormValues(), 'submitNewCronJob', true, $back_url).'<hr />';
		elseif (Tools::isSubmit('updatecronjobs') && Tools::isSubmit('id_cronjob'))
			$output = $output.$this->renderForm($this->getJobForm('Update cron job', true), $this->getUpdateJobFormValues(), 'submitUpdateCronJob', true, $back_url, true).'<hr />';
		elseif (Tools::isSubmit('deletecronjobs') && Tools::isSubmit('id_cronjob'))
			$this->_postProcessDeleteCronJob((int)Tools::getValue('id_cronjob'));
		elseif (Tools::isSubmit('statuscronjobs'))
			$this->_postProcessUpdateJobStatus();
		else
		{
			$output = $output.$this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl').'<hr />';
			$output = $output.$this->renderForm($this->getForm(), $this->getFormValues(), 'submitCronJobs').'<hr />';
		}
		
		return $output.$this->renderTasksList();
	}
	
	public function sendCallback()
	{
		ignore_user_abort(true);
		set_time_limit(0);
		
		ob_start();
		echo $this->name.'_prestashop';
		header('Connection: close');
		header('Content-Length: '.ob_get_length());
		ob_end_flush();
		ob_flush();
		flush();
	}
	
	protected function renderForm($form, $form_values, $action, $cancel = false, $back_url = false, $update = false)
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
	 
		$helper->identifier = $this->identifier;
		$helper->submit_action = $action;
		
		if ($update == true)
		{
			$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
				.'&id_cronjob='.(int)Tools::getValue('id_cronjob');
		}
		else
		{
			$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		}
		  
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
	
	protected function renderTasksList()
	{
		$helper = new HelperList();
		
		$helper->title = $this->l('Cron tasks');
		$helper->table = $this->name;
		$helper->no_link = true;
		$helper->shopLinkType = '';
		$helper->identifier = 'id_cronjob';
		$helper->actions = array('edit', 'delete');
		
		$values = $this->getTasksListValues();
		$helper->listTotal = count($values);
		
		$helper->tpl_vars = array(
			'show_filters' => false
		);
		
		$helper->toolbar_btn['new'] = array(
			'href' => $this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
		   .'&newcronjobs=1&token='.Tools::getAdminTokenLite('AdminModules'),
			'desc' => $this->l('Add new task')
		);
		
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		
		return $helper->generateList($values, $this->getTasksList());
	}

	protected function _postProcessConfiguration()
	{
		if (Tools::isSubmit('cron_mode') == true)
		{
			$cron_mode = Tools::getValue('cron_mode');
			
			if (in_array($cron_mode, array('advanced', 'webservice')) == true)
				return $this->toggleWebservice();
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
			
			$result = Db::getInstance()->getRow('SELECT id_cronjob FROM '._DB_PREFIX_.$this->name.'
				WHERE `task` = \''.$task.'\' AND `hour` = \''.$hour.'\' AND `day` = \''.$day.'\'
				AND `month` = \''.$month.'\' AND `day_of_week` = \''.$day_of_week.'\'');

			if ($result == false)
			{
				$query = 'INSERT INTO '._DB_PREFIX_.$this->name.'
					(`task`, `hour`, `day`, `month`, `day_of_week`, `last_execution`, `active`)
					VALUES (\''.$task.'\', \''.$hour.'\', \''.$day.'\', \''.$month.'\', \''.$day_of_week.'\', NULL, TRUE)';
				
				if (($result = Db::getInstance()->execute($query)) != false)
					$this->setSuccessMessage('The task has been added');
				else
					$this->setErrorMessage('The task has not been added');
				
				return $result;
			}
			
			$this->setErrorMessage('This cron task already exists');
		}
		
		return false;
	}

	protected function _postProcessUpdateJob()
	{
		if (Tools::isSubmit('id_cronjob') == false)
			return false;
			
		$task = urlencode(Tools::getValue('task'));
		$hour = (int)Tools::getValue('hour');
		$day = (int)Tools::getValue('day');
		$month = (int)Tools::getValue('month');
		$day_of_week = (int)Tools::getValue('day_of_week');
		
		$cronjob = Tools::getValue('id_cronjob');
		$id_cronjob = (int)substr($cronjob, 1);
		$cron_type = (int)substr($cronjob, 0, 1);

		if ($cron_type == TASKS_PREFIX)
		{
			$task = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.$this->name.'` WHERE `id_cronjob` = \''.$id_cronjob.'\'');
			$query = 'UPDATE '._DB_PREFIX_.$this->name.' SET `task` = \''.$task.'\', `hour` = \''.$hour.'\', `day` = \''.$day.'\', `month` = \''.$month.'\', `day_of_week` = \''.$day_of_week.'\' WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'';
		}
		elseif ($cron_type == MODULES_PREFIX)
		{
			$task = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.$this->name.'` WHERE `id_module` = \''.$id_cronjob.'\'');
			
			if ($task == false)
				$query = 'INSERT INTO '._DB_PREFIX_.$this->name.' (`id_module`, `hour`, `day`, `month`, `day_of_week`, `last_execution`) VALUES (\''.(int)$id_cronjob.'\', \''.$hour.'\', \''.$day.'\', \''.$month.'\', \''.$day_of_week.'\', NULL)';
			else
				$query = 'UPDATE '._DB_PREFIX_.$this->name.' SET `hour` = \''.$hour.'\', `day` = \''.$day.'\', `month` = \''.$month.'\', `day_of_week` = \''.$day_of_week.'\' WHERE `id_module` = \''.(int)$id_cronjob.'\'';
		}

		if (($result = Db::getInstance()->execute($query)) != false)
			$this->setSuccessMessage('The task has been updated');
		else
			$this->setErrorMessage('The task has not been updated');
		
		return $result;
	}
	
	protected function _postProcessUpdateJobStatus()
	{
		if (Tools::isSubmit('id_cronjob') == false)
			return false;
		
		$cronjob = Tools::getValue('id_cronjob');
		$id_cronjob = (int)substr($cronjob, 1);
		$cron_type = (int)substr($cronjob, 0, 1);
		
		if ($cron_type == TASKS_PREFIX)
			Db::getInstance()->execute('UPDATE '._DB_PREFIX_.$this->name.' SET `active` = IF (`active`, 0, 1) WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');
		elseif ($cron_type == MODULES_PREFIX)
		{
			$result = Db::getInstance()->getRow('SELECT id_cronjob FROM '._DB_PREFIX_.$this->name.' WHERE `id_module` = \''.(int)$id_cronjob.'\'');

			if ($result == false)
				Db::getInstance()->execute('INSERT INTO '._DB_PREFIX_.$this->name.' (`id_module`, `hour`, `day`, `month`, `day_of_week`, `last_execution`, `active`)
					VALUES (\''.(int)$id_cronjob.'\', \'-1\', \'-1\', \'-1\', \'-1\', NULL, TRUE)');
			else
				Db::getInstance()->execute('UPDATE '._DB_PREFIX_.$this->name.' SET `active` = IF (`active`, 0, 1) WHERE `id_module` = '.(int)$id_cronjob);
		}
		
		Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
		   .'&token='.Tools::getAdminTokenLite('AdminModules'));
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
				return $this->setErrorMessage('The task link you entered is not valid');
			
			$success = true;
			$hour = Tools::getValue('hour');
			$day = Tools::getValue('day');
			$month = Tools::getValue('month');
			$day_of_week = Tools::getValue('day_of_week');
			
			if ((($hour >= -1) && ($hour < 24)) == false)
				$success &= $this->setErrorMessage('The value you choose for the hour is not valid');
			if ((($day >= -1) && ($day < 24)) == false)
				$success &= $this->setErrorMessage('The value you choose for the day is not valid');
			if ((($month >= -1) && ($month <= 31)) == false)
				$success &= $this->setErrorMessage('The value you choose for the month is not valid');
			if ((($day_of_week >= -1) && ($day_of_week < 7)) == false)
				$success &= $this->setErrorMessage('The value you choose for the week\'s day is not valid');
				
			return $success;
		}
		
		return false;
	}
	
	protected function setErrorMessage($message)
	{
		$this->_errors[] = $this->l($message);
		return false;
	}
	
	protected function setSuccessMessage($message)
	{
		$this->_successes[] = $this->l($message);
		return true;
	}
	
	protected function toggleWebservice()
	{
		$cron_mode = Tools::getValue('cron_mode');
		$admin_folder = str_replace(_PS_ROOT_DIR_, null, _PS_ADMIN_DIR_);
		$path = Tools::getShopDomainSsl(true, true).$admin_folder.__PS_BASE_URI__;
		$cron_url = $path.$this->context->link->getAdminLink('AdminCronJobs', false);
		$webservice_id = Configuration::get('CRONJOBS_WEBSERVICE_ID') ? Configuration::get('CRONJOBS_WEBSERVICE_ID') : null;
		
		$data = http_build_query(array(
			'callback' => $this->context->link->getModuleLink('cronjobs', 'callback'),
			'cronjob' => $cron_url.'&token='.Configuration::get('CRONJOBS_EXECUTION_TOKEN'),
			'cron_token' => Configuration::get('CRONJOBS_EXECUTION_TOKEN'),
			'active' => ($cron_mode == 'advanced') ? false : true,
		));
		
		$context_options = array (
			'http' => array (
				'method' => $webservice_id ? 'PUT' : 'POST',
				'content' => $data
			)
		);
		
		$context = stream_context_create($context_options);
		$result = Tools::file_get_contents($this->webservice_url.$webservice_id, false, $context);
		Configuration::updateValue('CRONJOBS_WEBSERVICE_ID', (int)$result);

		if ((bool)$result == false)
			return $this->setErrorMessage('An error occured while trying to contact the PrestaShop\'s webcrons service');
			
		Configuration::updateValue('CRONJOBS_MODE', $cron_mode);
		
		switch ($cron_mode)
		{
			case 'advanced':
				return $this->setSuccessMessage('Your cron jobs have been successfully registered using the \'advanced\' mode');
			case 'webservice':
				return $this->setSuccessMessage('Your cron jobs have been successfully added to our webcrons service');
			default:
				return;
		}
	}
	
	protected function _postProcessDeleteCronJob($id_cronjob)
	{
		$cronjob = Tools::getValue('id_cronjob');
		$cron_type = (int)substr($cronjob, 0, 1);
		
		if ($cron_type != MODULES_PREFIX)
		{
			$id_cronjob = (int)substr($id_cronjob, 1);
			Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.$this->name.' WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');
		}
		
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
							array('id' => 'advanced', 'value' => 'advanced', 'label' => $this->l('Advanced')),
							array('id' => 'webservice', 'value' => 'webservice', 'label' => $this->l('Webservice'))
						),
					),
				),
				'submit' => array('title' => $this->l('Save'), 'type' => 'submit', 'class' => 'btn btn-default pull-right'),
			),
		);
		
		if (Configuration::get('CRONJOBS_MODE') == 'advanced')
			$form['form']['input'][] = array('type' => 'free','name' => 'advanced_help','col' => 12,'offset' => 0);
		
		return array($form);
	}
	
	protected function getFormValues()
	{
		$token = Configuration::get('CRONJOBS_EXECUTION_TOKEN');
		$php_client_path = $this->local_path.'classes/php_client.php token='.$token;
		
		$admin_folder = str_replace(_PS_ROOT_DIR_, null, _PS_ADMIN_DIR_);
		$path = Tools::getShopDomainSsl(true, true).$admin_folder.__PS_BASE_URI__;
		$curl_url = $path.$this->context->link->getAdminLink('AdminCronJobs', false);
		$curl_url .= '&token='.$token;
		
		return array(
			'cron_mode' => Configuration::get('CRONJOBS_MODE'),
			'advanced_help' =>
				'<div class="alert alert-info">
					<p>'
						.$this->l('First of all, be sure \'php-cli\' or \'php-curl\' is installed on your server.')
						.'<br />'.$this->l('To execute your cron jobs please insert one of the following commands in your crontab manager').':
					</p>
					<br />
					<ul class="list-unstyled">
						<li><code>0 * * * * php '.$php_client_path.'</code></li>
						<li><code>0 * * * * curl '.$curl_url.'</code></li>
					</ul>
				</div>'
		);
	}
	
	protected function getJobForm($title = 'New cron job', $update = false)
	{
		$form = array(
			array(
				'form' => array(
					'legend' => array(
						'title' => $this->l($title),
						'icon' => 'icon-plus',
					),
					'input' => array(),
					'submit' => array('title' => $this->l('Save'), 'type' => 'submit', 'class' => 'btn btn-default pull-right'),
				),
			),
		);
		
		if (($update == true) && (Tools::isSubmit('id_cronjob')))
		{
			$cronjob = Tools::getValue('id_cronjob');
			$cron_type = substr($cronjob, 0, 1);
			
			if ($cron_type == TASKS_PREFIX)
			{
				$form[0]['form']['input'][] = array(
					'type' => 'text',
					'name' => 'task',
					'label' => $this->l('Origin'),
					'desc' => $this->l('Define the link of your cron task'),
					'placeholder' => Tools::getShopDomain(true, true).__PS_BASE_URI__.basename(_PS_ADMIN_DIR_).'/cron_currency_rates.php?secure_key='.md5(_COOKIE_KEY_.Configuration::get('PS_SHOP_NAME')),
				);
			}
			elseif ($cron_type == MODULES_PREFIX)
			{
				$form[0]['form']['input'][] = array(
					'type' => 'free',
					'name' => 'task',
					'label' => $this->l('Origin'),
				);
			}
		}
		else
		{
			$form[0]['form']['input'][] = array(
				'type' => 'text',
				'name' => 'task',
				'label' => $this->l('Origin'),
				'desc' => $this->l('Define the link of your cron task'),
				'placeholder' => Tools::getShopDomain(true, true).__PS_BASE_URI__.basename(_PS_ADMIN_DIR_).'/cron_currency_rates.php?secure_key='.md5(_COOKIE_KEY_.Configuration::get('PS_SHOP_NAME')),
			);
		}
		
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'hour',
			'label' => $this->l('Frequency'),
			'options' => array(
				'query' => $this->getHoursFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'day',
			'options' => array(
				'query' => $this->getDaysFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'month',
			'options' => array(
				'query' => $this->getMonthsFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'day_of_week',
			'options' => array(
				'query' => $this->getDaysofWeekFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		
		return $form;
	}
	
	protected function getNewJobFormValues()
	{
		return array(
			'task' => Tools::safeOutput(Tools::getValue('task', null)),
			'hour' => (int)Tools::getValue('hour', -1),
			'day' => (int)Tools::getValue('day', -1),
			'month' => (int)Tools::getValue('month', -1),
			'day_of_week' => (int)Tools::getValue('day_of_week', -1),
		);
	}
	
	protected function getUpdateJobFormValues()
	{
		$cronjob = Tools::getValue('id_cronjob');
		$id_cronjob = substr($cronjob, 1);
		$cron_type = substr($cronjob, 0, 1);

		if ($cron_type == TASKS_PREFIX)
		{
			$task = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.$this->name.'` WHERE `id_cronjob` = \''.$id_cronjob.'\'');
			$origin = Tools::safeOutput(urldecode(Tools::getValue('task', $task['task'])));
		}
		else
		{
			$task = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.$this->name.'` WHERE `id_module` = \''.$id_cronjob.'\'');
			
			if ($task == false)
			{
				$task['hour'] = -1;
				$task['day'] = -1;
				$task['month'] = -1;
				$task['day_of_week'] = -1;
			}
			$module_info = Db::getInstance()->getRow('SELECT `name` FROM `'._DB_PREFIX_.'module` WHERE `id_module` = \''.$id_cronjob.'\'');
			$origin = '<p class="form-control-static">'.Tools::safeOutput(Module::getModuleName($module_info['name'])).'</p>';
		}
		
		return array(
			'task' => $origin,
			'hour' => (int)Tools::getValue('hour', $task['hour']),
			'day' => (int)Tools::getValue('day', $task['day']),
			'month' => (int)Tools::getValue('month', $task['month']),
			'day_of_week' => (int)Tools::getValue('day_of_week', $task['day_of_week']),
		);
	}
	
	protected function getHoursFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Every hours')));
		
		for ($hour = 0; $hour < 24; $hour += 1)
			$data[] = array('id' => $hour, 'name' => date('H:i', mktime($hour, 0, 0, 0, 1)));
		
		return  $data;
	}
	
	protected function getDaysFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Every days')));
		
		for ($day = 1; $day <= 31; $day += 1)
			$data[] = array('id' => $day, 'name' => $day);
		
		return  $data;
	}
	
	protected function getMonthsFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Every months')));
		
		for ($month = 1; $month <= 12; $month += 1)
			$data[] = array('id' => $month, 'name' => $this->l(date('F', mktime(0, 0, 0, $month, 1))));

		return  $data;
	}
	
	protected function getDaysofWeekFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Every week days')));
		
		for ($day = 1; $day <= 7; $day += 1)
			$data[] = array('id' => $day, 'name' => $this->l(date('l', mktime(0, 0, 0, 0, $day))));
		
		return  $data;
	}
	
	protected function getTasksList()
	{
		return array(
			'task' => array('title' => $this->l('Origin'), 'type' => 'text', 'orderby' => false),
			'hour' => array('title' => $this->l('Hour'), 'type' => 'text', 'orderby' => false),
			'day' => array('title' => $this->l('Day'), 'type' => 'text', 'orderby' => false),
			'month' => array('title' => $this->l('Month'), 'type' => 'text', 'orderby' => false),
			'day_of_week' => array('title' => $this->l('Day of week'), 'type' => 'text', 'orderby' => false),
			'last_execution' => array('title' => $this->l('Last execution'), 'type' => 'text', 'orderby' => false),
			'active' => array('title' => $this->l('Active'), 'active' => 'status', 'type' => 'bool', 'align' => 'center', 'orderby' => false)
		);
	}

	protected function getTasksListValues()
	{
		$modules = Hook::getHookModuleExecList('actionCronJob');
		$tasks = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.$this->name.'`');
		
		foreach ($tasks as &$task)
			foreach ($modules as $module_key => &$module)
				if ((is_null($module) == false) && ($task['id_module'] == $module['id_module']))
					unset($modules[$module_key]);
		
		foreach ($tasks as &$task)
		{	
			if (empty($task['task']) == true)
			{
				$task['id_cronjob'] = MODULES_PREFIX.$task['id_module'];
				$task['id_module'] = MODULES_PREFIX.$task['id_module'];
				$module_info = Db::getInstance()->getRow('SELECT `name` FROM `'._DB_PREFIX_.'module` WHERE `id_module` = \''.substr($task['id_module'], 1).'\'');
				$task['task'] = Tools::safeOutput(Module::getModuleName($module_info['name']));
			}
			else
			{
				$task['id_cronjob'] = TASKS_PREFIX.$task['id_cronjob'];
				$task['id_module'] = TASKS_PREFIX.$task['id_module'];
				$task['task'] = Tools::safeOutput(urldecode($task['task']));
			}
				
			$task['hour'] = ($task['hour'] == -1) ? $this->l('Every hours') : date('H:i', mktime((int)$task['hour'], 0, 0, 0, 1));
			$task['day'] = ($task['day'] == -1) ? $this->l('Every days') : (int)$task['day'];
			$task['month'] = ($task['month'] == -1) ? $this->l('Every months') : $this->l(date('F', mktime(0, 0, 0, (int)$task['month'], 1)));
			$task['day_of_week'] = ($task['day_of_week'] == -1) ? $this->l('Every week days') : $this->l(date('l', mktime(0, 0, 0, 0, (int)$task['day_of_week'])));
			$task['last_execution'] = ($task['last_execution'] == 0) ? $this->l('Never') : $this->l(date('c', $task['last_execution']));
			$task['active'] = (bool)$task['active'];
		}
		
		foreach ($modules as &$module)
		{
			$module['id_cronjob'] = MODULES_PREFIX.$module['id_module'];
			$module['task'] = Tools::safeOutput(Module::getModuleName($module['module']));
			$module['hour'] = $this->l('Every hours');
			$module['day'] = $this->l('Every days');
			$module['month'] = $this->l('Every months');
			$module['day_of_week'] = $this->l('Every week days');
			$module['last_execution'] = $this->l('Never');
			$module['active'] = false;
		}
		
		return array_merge($tasks, $modules);
	}
}
