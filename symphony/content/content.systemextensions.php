<?php

	/**
	 * @package content
	 */

	/**
	 * This page generates the Extensions index which shows all Extensions
	 * that are available in this Symphony installation.
	 */
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(CONTENT . '/class.sortable.php');

	Class contentSystemExtensions extends AdministrationPage{

		public function sort(&$sort, &$order, $params){
			if(is_null($sort)) $sort = 'name';

			return ExtensionManager::fetch(array(), array(), $sort . ' ' . $order);
		}

		public function __viewIndex(){
			$this->setPageType('table');
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Extensions'), __('Symphony'))));
			$this->appendSubheading(__('Extensions'));

			$this->Form->setAttribute('action', SYMPHONY_URL . '/system/extensions/');

			Sortable::init($this, $extensions, $sort, $order);

			$columns = array(
				array(
					'label' => __('Name'),
					'sortable' => true,
					'handle' => 'name'
				),
				array(
					'label' => __('Installed Version'),
					'sortable' => false,
				),
				array(
					'label' => __('Enabled'),
					'sortable' => false,
				),
				array(
					'label' => __('Authors'),
					'sortable' => true,
					'handle' => 'author'
				)
			);

			$aTableHead = Sortable::buildTableHeaders(
				$columns, $sort, $order, (isset($_REQUEST['filter']) ? '&amp;filter=' . $_REQUEST['filter'] : '')
			);

			$aTableBody = array();

			if(!is_array($extensions) || empty($extensions)){
				$aTableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None found.'), 'inactive', NULL, count($aTableHead))), 'odd')
				);
			}

			else{
				foreach($extensions as $name => $about){

					$td1 = Widget::TableData((!empty($about['table-link']) && $about['status'] == EXTENSION_ENABLED ? Widget::Anchor($about['name'], Administration::instance()->getCurrentPageURL() . 'extension/' . trim($about['table-link'], '/') . '/') : $about['name']));
					$installed_version = Symphony::ExtensionManager()->fetchInstalledVersion($name);
					$td2 = Widget::TableData(is_null($installed_version) ? __('Not Installed') : $installed_version);

					// If the extension is using the new `extension.meta.xml` format, check the
					// compatibility of the extension. This won't prevent a user from installing
					// it, but it will let them know that it requires a version of Symphony greater
					// then what they have.
					if($about['status'] != EXTENSION_ENABLED && ($meta = Symphony::ExtensionManager()->about($name, true)) instanceof DOMDocument) {
						$xpath = new DOMXPath($meta);
						$required_version = null;
						$required_min_version = $xpath->evaluate('string(/extension/releases/release[1]/@min)');
						$required_max_version = $xpath->evaluate('string(/extension/releases/release[1]/@max)');
						$current_symphony_version = Symphony::Configuration()->get('version', 'symphony');

						if(isset($required_min_version) && version_compare($current_symphony_version, $required_min_version, '<')) {
							$about['status'] = EXTENSION_NOT_COMPATIBLE;
							$required_version = $required_min_version;
						}
						else if(isset($required_max_version) && version_compare($current_symphony_version, $required_max_version, '>')) {
							$about['status'] = EXTENSION_NOT_COMPATIBLE;
							$required_version = $required_max_version;
						}
					}

					if($about['status'] == EXTENSION_ENABLED) {
						$td3 = Widget::TableData(__('Yes'));
					}
					else if($about['status'] == EXTENSION_DISABLED) {
						$td3 = Widget::TableData(__('Disabled'));
					}
					else if($about['status'] == EXTENSION_NOT_COMPATIBLE) {
						$td3 = Widget::TableData(__('Requires Symphony %s', array($required_version)));
					}
					else if($about['status'] == EXTENSION_NOT_INSTALLED) {
						$td3 = Widget::TableData(__('Enable to install %s', array($about['version'])));
					}
					else if($about['status'] == EXTENSION_REQUIRES_UPDATE) {
						$td3 = Widget::TableData(__('Enable to update to %s', array($about['version'])));
					}

					$td4 = Widget::TableData(NULL);
					if(isset($about['author'][0]) && is_array($about['author'][0])) {
						$authors = '';
						foreach($about['author'] as $i => $author) {

							if(isset($author['website']))
								$link = Widget::Anchor($author['name'], General::validateURL($author['website']));
							else if(isset($author['email']))
								$link = Widget::Anchor($author['name'], 'mailto:' . $author['email']);
							else
								$link = $author['name'];

							$authors .= ($link instanceof XMLElement ? $link->generate() : $link)
									. ($i != count($about['author']) - 1 ? ", " : "");
						}

						$td4->setValue($authors);
					}
					else {
						if(isset($about['author']['website']))
							$link = Widget::Anchor($about['author']['name'], General::validateURL($about['author']['website']));
						else if(isset($about['author']['email']))
							$link = Widget::Anchor($about['author']['name'], 'mailto:' . $about['author']['email']);
						else
							$link = $about['author']['name'];

						$td4->setValue($link instanceof XMLElement ? $link->generate() : $link);
					}

					$td4->appendChild(Widget::Input('items['.$name.']', 'on', 'checkbox'));

					## Add a row to the body array, assigning each cell to the row
					$aTableBody[] = Widget::TableRow(array($td1, $td2, $td3, $td4), ($about['status'] == EXTENSION_NOT_INSTALLED ? 'inactive' : NULL));

				}
			}

			$table = Widget::Table(
				Widget::TableHead($aTableHead),
				NULL,
				Widget::TableBody($aTableBody),
				'selectable'
			);

			$this->Form->appendChild($table);

			$tableActions = new XMLElement('div');
			$tableActions->setAttribute('class', 'actions');

			$options = array(
				array(NULL, false, __('With Selected...')),
				array('enable', false, __('Enable/Install')),
				array('disable', false, __('Disable')),
				array('uninstall', false, __('Uninstall'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to uninstall the selected extensions?')
				))
			);

			$tableActions->appendChild(Widget::Select('with-selected', $options));
			$tableActions->appendChild(Widget::Input('action[apply]', __('Apply'), 'submit'));

			$this->Form->appendChild($tableActions);

		}

		public function __actionIndex(){
			$checked = (is_array($_POST['items'])) ? array_keys($_POST['items']) : null;

			if(isset($_POST['with-selected']) && is_array($checked) && !empty($checked)){

				try{
					switch($_POST['with-selected']){

						case 'enable':

							/**
							 * Notifies just before an Extension is to be enabled.
							 *
							 * @delegate ExtensionPreEnable
							 * @since Symphony 2.2
							 * @param string $context
							 * '/system/extensions/'
							 * @param array $extensions
							 *  An array of all the extension name's to be enabled, passed by reference
							 */
							Symphony::ExtensionManager()->notifyMembers('ExtensionPreEnable', '/system/extensions/', array('extensions' => &$checked));

							foreach($checked as $name){
								if(Symphony::ExtensionManager()->enable($name) === false) return;
							}

							break;

						case 'disable':

							/**
							 * Notifies just before an Extension is to be disabled.
							 *
							 * @delegate ExtensionPreDisable
							 * @since Symphony 2.2
							 * @param string $context
							 * '/system/extensions/'
							 * @param array $extensions
							 *  An array of all the extension name's to be disabled, passed by reference
							 */
							Symphony::ExtensionManager()->notifyMembers('ExtensionPreDisable', '/system/extensions/', array('extensions' => &$checked));

							foreach($checked as $name){
								Symphony::ExtensionManager()->disable($name);
							}
							break;

						case 'uninstall':

							/**
							 * Notifies just before an Extension is to be uninstalled
							 *
							 * @delegate ExtensionPreUninstall
							 * @since Symphony 2.2
							 * @param string $context
							 * '/system/extensions/'
							 * @param array $extensions
							 *  An array of all the extension name's to be uninstalled, passed by reference
							 */
							Symphony::ExtensionManager()->notifyMembers('ExtensionPreUninstall', '/system/extensions/', array('extensions' => &$checked));

							foreach($checked as $name){
								Symphony::ExtensionManager()->uninstall($name);
							}

							break;
					}

					redirect(Administration::instance()->getCurrentPageURL());
				}
				catch(Exception $e){
					$this->pageAlert($e->getMessage(), Alert::ERROR);
				}
			}
		}
	}
