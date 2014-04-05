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
		return parent::install() && $this->registerHook('backOfficeHeader');
	}
	
	public function hookBackOfficeHeader()
	{
		$this->context->controller->addCSS($this->_path.'css/configure.css');
		$this->context->controller->addJS($this->_path.'js/configure.js');
	}
	
	public function getContent()
	{
		if (Tools::isSubmit('submitCronJobs'))
			$this->_postProcess();

		$this->context->smarty->assign(array(
			'module_dir' => $this->_path,
			'module_local_dir' => $this->local_path,
		));
		
		$output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
		return $output.$this->renderForm().'<hr />';
	}
	
	protected function renderForm()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
	 
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitCronJobs';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
		   .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getFormsValues(),
			'id_language' => $this->context->language->id,
			'languages' => $this->context->controller->getLanguages(),
		);

		return $helper->generateForm($this->getForms());
	}

	protected function _postProcess()
	{
	}
	
	protected function getForms()
	{
		return array(
			array(
				'form' => array(
					'legend' => array(
						'title' => $this->l('Mode'),
						'icon' => 'icon-cog',
					),
					'input' => array(
						array(
							'label' => $this->l('Working mode'),
							'name' => 'working_mode',
/* 							'is_bool' => false, */
							'type' => 'radio',
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
			),
		);
	}
	
	protected function getFormsValues()
	{
		return array(
			'working_mode' => 'advanced',
		);
	}
}
