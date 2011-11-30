<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010 René Nitzsche (nitzsche@das-medienkombinat.de)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');
tx_rnbase::load('tx_mkforms_session_IManager');

/**
 * A session manager that uses php session and cache framework to store data
 *
 * Relevante Daten:
 * - ajax_services:  Widgets, die Ajax nutzen
 * - hibernate: Das serialisierte Formular
 * Pfad: hibernate/formid/
 *   object - das serialisierte Formular
 *   runningobjects - alle verwendeten Formobjekte inklusive Datahandler und Renderer
 *   tsfe_config - das Typoscript-Array
 *
 */
class tx_mkforms_session_MixedSessionManager implements tx_mkforms_session_IManager {
	private $form;
	public function __construct() {
		session_start();
		$this->init();
	}
	private function init() {
		if(!array_key_exists('ameos_formidable', $GLOBALS['_SESSION'])) {

			$GLOBALS['_SESSION']['ameos_formidable'] = array();
			$GLOBALS['_SESSION']['ameos_formidable']['ajax_services'] = array();
			$GLOBALS['_SESSION']['ameos_formidable']['ajax_services']['tx_ameosformidable'] = array();
			$GLOBALS['_SESSION']['ameos_formidable']['ajax_services']['tx_ameosformidable']['ajaxevent'] = array();

			$GLOBALS['_SESSION']['ameos_formidable']['hibernate'] = array();

			$GLOBALS['_SESSION']['ameos_formidable']['applicationdata'] = array();
		}
	}
	/**
	 * Legt das Formular und weitere Objekte auf dem Server in einem Cache ab.
	 * Optional kann bei Ajax-calls das Laden der TSFE abgeschaltet werden. Beim Persistieren
	 * muss dann darauf geachtet werden, daß diese Daten nicht überschrieben werden! Eigentlich
	 * können sie bei Ajax-Calls eh aussen vor gelassen werden. Die Daten ändern sich ja nicht...
	 * @param boolean $fromAjax wenn true wird die Persistierung von einem Ajax-Call angestossen.
	 */
	public function persistForm($fromAjax = false) {
		$form = $this->getForm();
		if(!$form) throw new Exception('No form found to persist!');
		
		// ts und tc cachen, wenn aktiviert.
		if(!$fromAjax && $form->getConfTS('cache.enabled')) {
			$this->persistFeConfig($formId);
			// wir cachen das ts setup
			// aber nur die im ts angegebenen pfade
			$this->persistFeSetup($formId,
					$form->getConfTS('cache.tsPaths') ? $form->getConfTS('cache.tsPaths.') : array()
				);
		}
		//aufräumen vor dem cachen der form
		$form->cleanBeforeSession();
		$formId = $this->getForm()->getFormId();

		// hohe Kompression ist nicht notwendig und kostet nur Zeit!
		$serForm = gzcompress(serialize($form), 1);
		//form cachen
		tx_rnbase::load('tx_rnbase_cache_Manager');
		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		$cache->set($this->getUserFormKey($formId), $serForm, 60*60*3); // 3h Lifetime

		$sessData = array();
		$sessData['xmlpath'] = $this->getForm()->_xmlPath;
		$sessData['runningobjects'] = $this->getForm()->getObjectLoader()->getRunningObjects($formId);
		$sessData['loadedClasses'] = $this->getForm()->getObjectLoader()->getLoadedClasses($formId);

		if(!$fromAjax) {
//			$sessData['tsfe_config'] = ''; // Wird gesondert gespeichert
			$sessData['sys_language_uid'] = intval($GLOBALS['TSFE']->sys_language_uid);
			$sessData['sys_language_content'] = intval($GLOBALS['TSFE']->sys_language_content);
			$sessData['pageid'] = $GLOBALS['TSFE']->id;
			$sLang = (tx_mkforms_util_Div::getEnvExecMode() === 'BE') ? $GLOBALS['LANG']->lang : $GLOBALS['TSFE']->lang;
			$sessData['lang'] = $sLang;
			$sessData['spamProtectEmailAddresses'] = $GLOBALS['TSFE']->spamProtectEmailAddresses;
			$sessData['spamProtectEmailAddresses_atSubst'] = $GLOBALS['TSFE']->config['config']['spamProtectEmailAddresses_atSubst'];
			$sessData['spamProtectEmailAddresses_lastDotSubst'] = $GLOBALS['TSFE']->config['config']['spamProtectEmailAddresses_lastDotSubst'];
			$sessData['formidable_tsconfig'] = $GLOBALS['TSFE']->tmpl->setup['config.']['tx_ameosformidable.'];
		}
		$sessData['parent'] = FALSE;

		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		$serData = $cache->get($this->getPageFormKey($formId, $GLOBALS['TSFE']->id));

		if(is_array($GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId]))
			$GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId] = array_merge($GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId], $sessData);
		else
			$GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId] = $sessData;

		// FIXME: Das funktioniert noch nicht. Wird in mainrenderlet::_getEventsArray() gesetzt. Aber noch im Form!
		if($this->bStoreParentInSession === TRUE) {

			$sClass = get_class($this->getForm()->getParent());
			$aParentConf = $GLOBALS['TSFE']->tmpl->setup['plugin.'][$sClass . '.'];

			$GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId]['parent'] = array(
				'classpath' => tx_mkforms_util_Div::removeEndingSlash(
					t3lib_div::getIndpEnv('TYPO3_DOCUMENT_ROOT')) . '/' . tx_mkforms_util_Div::removeStartingSlash($aParentConf['includeLibs']),
			);
		}

		// Warning for large sessions
		tx_rnbase::load('tx_rnbase_util_Logger');
		if(tx_rnbase_util_Logger::isNoticeEnabled()) {
			$sessionLen = strlen(serialize($GLOBALS['_SESSION']));
			if($sessionLen > 300000) {
				tx_rnbase_util_Logger::notice('Alert: Large session size!', 'mkforms', array('Size'=>$sessionLen, 'PHP-SessionID'=> session_id(), 'FormId' => $formId));
			}
// nicht gut für den live betrieb, lieber mal in die devlog schauen!
// 			if ($_REQUEST['debug']==1)
// 				t3lib_div::debug($sessionLen,'Das ganze _SESSION in Bytes in ' . $formId );
		}
	}

	public $tsfeMode = 'nosession';
	/**
	 * Save $GLOBALS['TSFE']->config to cache. This Typoscript data is globally cached. There is no user
	 * specific data inside.
	 * @param string $formId
	 */
	private function persistFeConfig($formId) {
		$feConfig = $GLOBALS['TSFE']->config;
		// das ganze komprimieren
		$feConfig = serialize(gzcompress(serialize($feConfig),1));
		// Das Speichern in der PHP-Session ist sehr schnell. Die Verwendung des DB-Caches dagegen ziemlich langsam
		// Bei MemCached kann das schon wieder anders aussehen... Erstmal beide Optionen offen halten.
		if($this->tsfeMode == 'session') {
			$GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId]['tsfe_config'] = $feConfig;
			return;
		}

		tx_rnbase::load('tx_rnbase_cache_Manager');
		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		// Die Daten müssen im Context des Forms und der PageID gespeichert werden, da ein
		// Formular in verschiedenen Seiten verwendet werden kann.
		$cache->set($this->getPageFormKey($formId, $GLOBALS['TSFE']->id), $feConfig, 60*60*3); // 3h Lifetime
		return false;
	}
	public function restoreFeConfig($formId) {
		if($this->tsfeMode == 'session') {
			if(!array_key_exists($formid, $GLOBALS['_SESSION']['ameos_formidable']['hibernate'])) return false;
			$aHibernation =& $GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formid];
			$feConfig = unserialize(gzuncompress(unserialize($aHibernation['tsfe_config'])));
			return $feConfig;
		}

		tx_rnbase::load('tx_rnbase_cache_Manager');
		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		
		// Wir holen uns die pageID von dem Formular.
		// Bei AjaxCalls steht im TSFE keine oder die falsche ID!
		$iPageId = $this->getForm()->iPageId;
		$iPageId = $iPageId ? $iPageId : $GLOBALS['TSFE']->id;
		$serData = $cache->get($this->getPageFormKey($formId, $iPageId));
		if(!$serData) return false;
		$feConfig = unserialize(gzuncompress(unserialize($serData)));
//print_r(array(
//		array_keys($feConfig),
//		'DEBUG: '.__METHOD__.' Line: '.__LINE__
//	)); // @TODO: remove me
		return $feConfig;
	}
	
	/**
	 * Save $GLOBALS['TSFE']->tmpl->setup to cache. This Typoscript data is globally cached. There is no user
	 * specific data inside.
	 * @param string $formId
	 */
	private function persistFeSetup($formId, $tsSetupCache = array()) {
		// es ist nichts zu cachen!
		if(empty($tsSetupCache)) return;
		$tsSetup = tx_mkforms_util_Div::getSetupByKeys($GLOBALS['TSFE']->tmpl->setup, $tsSetupCache);

		// das ganze komprimieren
		$tsSetup = serialize(gzcompress(serialize($tsSetup),1));
		// Das Speichern in der PHP-Session ist sehr schnell. Die Verwendung des DB-Caches dagegen ziemlich langsam
		// Bei MemCached kann das schon wieder anders aussehen... Erstmal beide Optionen offen halten.
		if($this->tsfeMode == 'session') {
			$GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formId]['tsfe_setup'] = $tsSetup;
			return;
		}

		tx_rnbase::load('tx_rnbase_cache_Manager');
		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		// Die Daten müssen im Context des Forms und der PageID gespeichert werden, da ein
		// Formular in verschiedenen Seiten verwendet werden kann.
		$cache->set($this->getPageFormKey($formId, $GLOBALS['TSFE']->id, 'setup'), $tsSetup, 60*60*3); // 3h Lifetime
		return false;
	}
	public function restoreFeSetup($formId) {
		if($this->tsfeMode == 'session') {
			if(!array_key_exists($formid, $GLOBALS['_SESSION']['ameos_formidable']['hibernate'])) return false;
			$aHibernation =& $GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formid];
			$feSetup = unserialize(gzuncompress(unserialize($aHibernation['tsfe_setup'])));
			return $feSetup;
		}

		tx_rnbase::load('tx_rnbase_cache_Manager');
		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		
		// Wir holen uns die pageID von dem Formular.
		// Bei AjaxCalls steht im TSFE keine oder die falsche ID!
		$iPageId = $this->getForm()->iPageId;
		$iPageId = $iPageId ? $iPageId : $GLOBALS['TSFE']->id;
		$serData = $cache->get($this->getPageFormKey($formId, $iPageId, 'setup'));
		if(!$serData) return false;
		$feSetup = unserialize(gzuncompress(unserialize($serData)));
//print_r(array(
//		array_keys($feSetup),
//		array_keys($feSetup['lib.']),
//		'DEBUG: '.__METHOD__.' Line: '.__LINE__
//	)); // @TODO: remove me
		return $feSetup;
	}
	/**
	 * Restores form from session
	 * @param 	int
	 * @return 	tx_ameosformidable or false
	 */
	public function restoreForm($formid) {
		
		//registriert eine unserialize_callback_func
		tx_rnbase::load('tx_mkforms_util_AutoLoad');
		tx_mkforms_util_AutoLoad::registerUnserializeCallbackFunc();
		
		if(!array_key_exists($formid, $GLOBALS['_SESSION']['ameos_formidable']['hibernate'])) return false;
		$aHibernation =& $GLOBALS['_SESSION']['ameos_formidable']['hibernate'][$formid];
		$this->loadRunningObjects($aHibernation);
		$this->loadLoadedClasses($aHibernation);
		$this->loadParent($aHibernation);

		tx_rnbase::load('tx_rnbase_cache_Manager');
		$cache = tx_rnbase_cache_Manager::getCache('mkforms');
		$serForm = $cache->get($this->getUserFormKey($formid));
		if(!$serForm) return false;
		
		/* @var $oForm tx_ameosformidable */
		$oForm = unserialize(gzuncompress($serForm));
		
		$oForm->_includeSandBox();	// rebuilding class
		$oForm->oSandBox = unserialize($oForm->oSandBox);
		$oForm->oSandBox->oForm =& $oForm;

		// konfiguration wieder herstellen
		$aConfigArray = unserialize(gzuncompress($oForm->getConfigurations()));
		$config = tx_rnbase::makeInstance('tx_rnbase_configurations');
		$config->init($aConfigArray, $oForm->getCObj(), 'mkforms', 'mkforms');
		$oForm->setConfigurations($config, $oForm->getConfId());
		
		$oForm->oDataHandler->oForm =& $oForm;
		$oForm->oRenderer->oForm =& $oForm;
		$oForm->oJs->oForm =& $oForm;
		$oForm->getRunnable()->initCodeBehinds();

		// stellt die alte unserialize_callback_func wieder her
		tx_mkforms_util_AutoLoad::restoreUnserializeCallbackFunc();
		
		return $oForm;
	}
	
	/**
	 *
	 * @param	array		$$aHibernation
	 */
	private function loadRunningObjects(&$aHibernation) {
		tx_rnbase::load('tx_mkforms_util_Loader');
		$aRObjects =& $aHibernation['runningobjects'];
		tx_mkforms_util_Loader::loadRunningObjects($aRObjects);
	}
	/**
	 *
	 * @param	array		$$aHibernation
	 */
	private function loadLoadedClasses(&$aHibernation) {
		$aRObjects =& $aHibernation['loadedClasses'];
		tx_rnbase::load('tx_mkforms_util_Loader');
		tx_mkforms_util_Loader::loadLoadedClasses($aRObjects);
	}
	/**
	 * [Describe function...]
	 *
	 * @param	[type]		$$aHibernation: ...
	 * @return	[type]		...
	 */
	private function loadParent(&$aHibernation) {
		if($aHibernation['parent'] !== FALSE) {
			$sClassPath = $aHibernation['parent']['classpath'];
			require_once($sClassPath);
		}
	}


	/**
	 * Liefert einen eindeutigen Key für Usersession und Form
	 * @return string
	 */
	private function getUserFormKey($formid) {
		$formid = $formid ? $formid : $this->getForm()->getFormId();
		return 'mkf_'.session_id().'_'.$formid;
	}
	private function getPageFormKey($formid, $pageId, $key = 'config') {
		$formid = $formid ? $formid : $this->getForm()->getFormId();
		return 'mkf_p'.$pageId.'_'.$formid.'_'.$key;
	}

	public function setForm($form) {
		$this->form = $form;
	}
	/**
	 * Returns the form instance
	 * @return tx_ameosformidable
	 */
	public function getForm() {
		return $this->form;
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mkforms/util/class.tx_mkforms_session_MixedSessionManager.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mkforms/util/class.tx_mkforms_session_MixedSessionManager.php']);
}
?>
