<?php

/**
 * @file plugins/pubIds/urn/URNPubIdPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class URNPubIdPlugin
 * @ingroup plugins_pubIds_urn
 *
 * @brief URN plugin class
 */


import('classes.plugins.PubIdPlugin');

class URNPubIdPlugin extends PubIdPlugin {

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('Publication::getProperties::summaryProperties', array($this, 'modifyObjectProperties'));
			HookRegistry::register('Publication::getProperties::fullProperties', array($this, 'modifyObjectProperties'));
			HookRegistry::register('Publication::getProperties::values', array($this, 'modifyObjectPropertyValues'));
			HookRegistry::register('Publication::validate', array($this, 'validatePublicationUrn'));
			HookRegistry::register('Form::config::before', array($this, 'addPublicationFormFields'));
			HookRegistry::register('Form::config::before', array($this, 'addPublishFormNotice'));
			HookRegistry::register('TemplateManager::display', array($this, 'loadUrnFieldComponent'));
		}
		return $success;
	}

	//
	// Implement template methods from Plugin.
	//
	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.pubIds.urn.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.pubIds.urn.description');
	}


	//
	// Implement template methods from PubIdPlugin.
	//
	/**
	 * @copydoc PKPPubIdPlugin::constructPubId()
	 */
	function constructPubId($pubIdPrefix, $pubIdSuffix, $contextId) {
		$urn = $pubIdPrefix . $pubIdSuffix;
		$suffixFieldName = $this->getSuffixFieldName();
		$suffixGenerationStrategy = $this->getSetting($contextId, $suffixFieldName);
		// checkNo is already calculated for custom suffixes
		if ($suffixGenerationStrategy != 'customId' && $this->getSetting($contextId, 'urnCheckNo')) {
			$urn .= $this->_calculateCheckNo($urn);
		}
		return $urn;
	}

	/**
	 * @copydoc PKPPubIdPlugin::getPubIdType()
	 */
	function getPubIdType() {
		return 'other::urn';
	}

	/**
	 * @copydoc PKPPubIdPlugin::getPubIdDisplayType()
	 */
	function getPubIdDisplayType() {
		return 'URN';
	}

	/**
	 * @copydoc PKPPubIdPlugin::getPubIdFullName()
	 */
	function getPubIdFullName() {
		return 'Uniform Resource Name';
	}

	/**
	 * @copydoc PKPPubIdPlugin::getResolvingURL()
	 */
	function getResolvingURL($contextId, $pubId) {
		$resolverURL = $this->getSetting($contextId, 'urnResolver');
		return $resolverURL . $pubId;
	}

	/**
	 * @copydoc PKPPubIdPlugin::getPubIdMetadataFile()
	 */
	function getPubIdMetadataFile() {
		return $this->getTemplateResource('urnSuffixEdit.tpl');
	}

	/**
	 * @copydoc PKPPubIdPlugin::addJavaScript()
	 */
	function addJavaScript($request, $templateMgr) {
		$templateMgr->addJavaScript(
			'urnCheckNo',
			$request->getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'checkNumber.js',
			array(
				'inline' => false,
				'contexts' => ['publicIdentifiersForm', 'backend'],
			)
		);
	}

	/**
	 * @copydoc PKPPubIdPlugin::getPubIdAssignFile()
	 */
	function getPubIdAssignFile() {
		return $this->getTemplateResource('urnAssign.tpl');
	}

	/**
	 * @copydoc PKPPubIdPlugin::instantiateSettingsForm()
	 */
	function instantiateSettingsForm($contextId) {
		$this->import('classes.form.URNSettingsForm');
		return new URNSettingsForm($this, $contextId);
	}

	/**
	 * @copydoc PKPPubIdPlugin::getFormFieldNames()
	 */
	function getFormFieldNames() {
		return array('urnSuffix');
	}

	/**
	 * @copydoc PKPPubIdPlugin::getAssignFormFieldName()
	 */
	function getAssignFormFieldName() {
		return 'assignURN';
	}

	/**
	 * @copydoc PKPPubIdPlugin::getPrefixFieldName()
	 */
	function getPrefixFieldName() {
		return 'urnPrefix';
	}

	/**
	 * @copydoc PKPPubIdPlugin::getSuffixFieldName()
	 */
	function getSuffixFieldName() {
		return 'urnSuffix';
	}

	/**
	 * @copydoc PKPPubIdPlugin::getLinkActions()
	 */
	function getLinkActions($pubObject) {
		$linkActions = array();
		import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
		$request = Application::get()->getRequest();
		$userVars = $request->getUserVars();
		$userVars['pubIdPlugIn'] = get_class($this);
		// Clear object pub id
		$linkActions['clearPubIdLinkActionURN'] = new LinkAction(
			'clearPubId',
			new RemoteActionConfirmationModal(
				$request->getSession(),
				__('plugins.pubIds.urn.editor.clearObjectsURN.confirm'),
				__('common.delete'),
				$request->url(null, null, 'clearPubId', null, $userVars),
				'modal_delete'
			),
			__('plugins.pubIds.urn.editor.clearObjectsURN'),
			'delete',
			__('plugins.pubIds.urn.editor.clearObjectsURN')
		);
		return $linkActions;
	}

	/**
	 * @copydoc PKPPubIdPlugin::getSuffixPatternsFieldNames()
	 */
	function getSuffixPatternsFieldNames() {
		return  array(
			'Publication' => 'urnPublicationSuffixPattern',
			'Representation' => 'urnRepresentationSuffixPattern',
			'SubmissionFile' => 'urnSubmissionFileSuffixPattern',
			'Chapter' => 'urnChapterSuffixPattern',
		);
	}

	/**
	 * @copydoc PKPPubIdPlugin::getDAOFieldNames()
	 */
	function getDAOFieldNames() {
		return array('pub-id::other::urn');
	}

	/**
	 * @copydoc PKPPubIdPlugin::isObjectTypeEnabled()
	 */
	function isObjectTypeEnabled($pubObjectType, $contextId) {
		return (boolean) $this->getSetting($contextId, "enable{$pubObjectType}URN");
	}

	/**
	 * @copydoc PKPPubIdPlugin::isObjectTypeEnabled()
	 */
	function getNotUniqueErrorMsg() {
		return __('plugins.pubIds.urn.editor.urnSuffixCustomIdentifierNotUnique');
	}

	/**
	 * Add URN to publication properties
	 *
	 * @param $hookName string <Object>::getProperties::summaryProperties or
	 *  <Object>::getProperties::fullProperties
	 * @param $args array [
	 * 		@option $props array Existing properties
	 * 		@option $object Publication
	 * 		@option $args array Request args
	 * ]
	 *
	 * @return array
	 */
	public function modifyObjectProperties($hookName, $args) {
		$props =& $args[0];

		$props[] = 'pub-id::other::urn';
	}

	/**
	 * Add URN publication values
	 *
	 * @param $hookName string <Object>::getProperties::values
	 * @param $args array [
	 * 		@option $values array Key/value store of property values
	 * 		@option $object Publication
	 * 		@option $props array Requested properties
	 * 		@option $args array Request args
	 * ]
	 *
	 * @return array
	 */
	public function modifyObjectPropertyValues($hookName, $args) {
		$values =& $args[0];
		$object = $args[1];
		$props = $args[2];

		// URNs are not supported for IssueGalleys
		if (get_class($object) === 'IssueGalley') {
			return;
		}

		// URNs are already added to property values for Publications and Galleys
		if (get_class($object) === 'Publication' || get_class($object) === 'ArticleGalley') {
			return;
		}

		if (in_array('pub-id::other::urn', $props)) {
			$pubId = $this->getPubId($object);
			$values['pub-id::other::urn'] = $pubId ? $pubId : null;
		}
	}

	/**
	 * Validate a publication's URN against the plugin's settings
	 *
	 * @param $hookName string
	 * @param $args array
	 */
	public function validatePublicationUrn($hookName, $args) {
		$errors =& $args[0];
		$action = $args[1];
		$props =& $args[2];

		if (empty($props['pub-id::other::urn'])) {
			return;
		}

		if ($action === VALIDATE_ACTION_ADD) {
			$submission = Services::get('submission')->get($props['submissionId']);
		} else {
			$publication = Services::get('publication')->get($props['id']);
			$submission = Services::get('submission')->get($publication->getData('submissionId'));
		}

		$contextId = $submission->getData('contextId');
		$urnPrefix = $this->getSetting($contextId, 'urnPrefix');

		$urnErrors = [];
		if (strpos($props['pub-id::other::urn'], $urnPrefix) !== 0) {
			$urnErrors[] = __('plugins.pubIds.urn.editor.missingPrefix', ['urnPrefix' => $urnPrefix]);
		}
		if (!$this->checkDuplicate($props['pub-id::other::urn'], 'Publication', $submission->getId(), $contextId)) {
			$urnErrors[] = $this->getNotUniqueErrorMsg();
		}
		if (!empty($urnErrors)) {
			$errors['pub-id::other::urn'] = $urnErrors;
		}
	}

	/**
	 * Add URN fields to the publication identifiers form
	 *
	 * @param $hookName string Form::config::before
	 * @param $form FormComponent The form object
	 */
	public function addPublicationFormFields($hookName, $form) {

		if ($form->id !== 'publicationIdentifiers') {
			return;
		}

		if (!$this->getSetting($form->submissionContext->getId(), 'enablePublicationURN')) {
			return;
		}

		$prefix = $this->getSetting($form->submissionContext->getId(), 'urnPrefix');

		$suffixType = $this->getSetting($form->submissionContext->getId(), 'urnSuffix');
		$pattern = '';
		if ($suffixType === 'default') {
			$pattern = '%p.%m';
		} elseif ($suffixType === 'pattern') {
			$pattern = $this->getSetting($form->submissionContext->getId(), 'urnPublicationSuffixPattern');
		}

		$appyCheckNumber = $this->getSetting($form->submissionContext->getId(), 'urnCheckNo');

		if ($appyCheckNumber) {
			// Load the checkNumber.js file that is required for URN fields
			$this->addJavaScript(Application::get()->getRequest(), TemplateManager::getManager(Application::get()->getRequest()));
		}

		// If a pattern exists, use a DOI-like field to generate the URN
		if ($pattern) {
			$fieldData = [
				'label' => __('plugins.pubIds.urn.displayName'),
				'value' => $form->publication->getData('pub-id::other::urn'),
				'prefix' => $prefix,
				'pattern' => $pattern,
				'contextInitials' => $form->submissionContext->getData('acronym', $form->submissionContext->getData('primaryLocale')) ?? '',
				'isPForPress' => true,
				'submissionId' => $form->publication->getData('submissionId'),
				'assignIdLabel' => __('plugins.pubIds.urn.editor.urn.assignUrn'),
				'clearIdLabel' => __('plugins.pubIds.urn.editor.clearObjectsURN'),
				'missingPartsLabel' => __('plugins.pubIds.doi.editor.missingParts'),
				'applyCheckNumber' => $appyCheckNumber,
			];
			if ($form->publication->getData('pub-id::publisher-id')) {
				$fieldData['publisherId'] = $form->publication->getData('pub-id::publisher-id');
			}
			if ($form->publication->getData('pages')) {
				$fieldData['pages'] = $form->publication->getData('pages');
			}
			if ($suffixType === 'default') {
				$fieldData['missingPartsLabel'] = __('plugins.pubIds.urn.editor.missingIssue');
			} else {
				$fieldData['missingPartsLabel'] = __('plugins.pubIds.urn.editor.missingParts');
			}
			$this->import('classes.form.FieldPubIdUrn');
			$form->addField(new \Plugins\Generic\URN\FieldPubIdUrn('pub-id::other::urn', $fieldData));

		// Otherwise add a field for manual entry that includes a button to generate
		// the check number
		} else {
			$this->import('classes.form.FieldTextUrn');
			$form->addField(new \Plugins\Generic\URN\FieldTextUrn('pub-id::other::urn', [
				'label' => __('plugins.pubIds.urn.displayName'),
				'description' => __('plugins.pubIds.urn.editor.urn.description', ['prefix' => $prefix]),
				'value' => $form->publication->getData('pub-id::other::urn'),
				'urnPrefix' => $prefix,
				'applyCheckNumber' => $appyCheckNumber,
			]));
		}
	}

	/**
	 * Show URN during final publish step
	 *
	 * @param $hookName string Form::config::before
	 * @param $form FormComponent The form object
	 */
	public function addPublishFormNotice($hookName, $form) {

		if ($form->id !== 'publish' || !empty($form->errors)) {
			return;
		}

		$submission = Services::get('submission')->get($form->publication->getData('submissionId'));
		$publicationUrnEnabled = $this->getSetting($submission->getData('contextId'), 'enablePublicationURN');
		$chapterUrnEnabled = $this->getSetting($submission->getData('contextId'), 'enableChapterURN');
		$publicationFormatUrnEnabled = $this->getSetting($submission->getData('contextId'), 'enableRepresentationURN');
		$submissionFileUrnEnabled = $this->getSetting($submission->getData('contextId'), 'enableSubmissionFileURN');
		$warningIconHtml = '<span class="fa fa-exclamation-triangle pkpIcon--inline"></span>';

		if (!$publicationUrnEnabled && !$chapterUrnEnabled && !$publicationFormatUrnEnabled && !$submissionFileUrnEnabled) {
			return;

		// Use a simplified view when only assigning to the publication
		} else if ($publicationFormatUrnEnabled && !$chapterUrnEnabled && !$publicationFormatUrnEnabled && !$submissionFileUrnEnabled) {
			if ($form->publication->getData('pub-id::other::urn')) {
				$msg = __('plugins.pubIds.urn.editor.preview.publication', ['urn' => $form->publication->getData('pub-id::other::urn')]);
			} else {
				$msg = '<div class="pkpNotification pkpNotification--warning">' . $warningIconHtml . __('plugins.pubIds.urn.editor.preview.publication.none') . '</div>';
			}
			$form->addField(new \PKP\components\forms\FieldHTML('urn', [
				'description' => $msg,
				'groupId' => 'default',
			]));
			return;

		// Show a table if more than one URN is going to be created
		} else {
			$urnTableRows = [];
			if ($publicationUrnEnabled) {
				if ($form->publication->getData('pub-id::other::urn')) {
					$urnTableRows[] = [$form->publication->getData('pub-id::other::urn'), 'Publication'];
				} else {
					$urnTableRows[] = [$warningIconHtml . __('submission.status.unassigned'), 'Publication'];
				}
			}
			if ($chapterUrnEnabled) {
				/** @var Chapter $chapter */
				foreach ((array) $form->publication->getData('chapters') as $chapter) {
					if ($chapter->getStoredPubId('other::urn')) {
						$urnTableRows[] = [$chapter->getStoredPubId('other::urn'), __('plugins.pubIds.urn.editor.preview.chapters', ['chapterLabel' => $chapter->getLocalizedTitle()])];
					} else {
						$urnTableRows[] = [$warningIconHtml . __('submission.status.unassigned'),__('plugins.pubIds.urn.editor.preview.chapters', ['chapterLabel' => $chapter->getLocalizedTitle()])];
					}
				}
			}
			if ($publicationFormatUrnEnabled) {
				/** @var PublicationFormat $publicationFormat */
				foreach ((array) $form->publication->getData('publicationFormats') as $publicationFormat) {
					if ($publicationFormat->getStoredPubId('other::urn')) {
						$urnTableRows[] = [$publicationFormat->getStoredPubId('other::urn'), __('plugins.pubIds.urn.editor.preview.publicationFormats', ['publicationFormatLabel' => $publicationFormat->getLocalizedName()])];
					} else {
						$urnTableRows[] = [$warningIconHtml . __('submission.status.unassigned'),__('plugins.pubIds.urn.editor.preview.publicationFormats', ['publicationFormatLabel' => $publicationFormat->getLocalizedName()])];
					}
				}
			}
			if ($submissionFileUrnEnabled) {
				import('lib.pkp.classes.submission.SubmissionFile'); // Constants
				import('classes.search.MonographSearch'); // Constants
				$submissionFiles = Services::get('submissionFile')->getMany([
					'submissionIds' => [$form->publication->getData('submissionId')],
					'fileStages' => [SUBMISSION_FILE_PROOF],
				]);
				/** @var SubmissionFile $submissionFile */
				foreach ($submissionFiles as $submissionFile) {
					if ($submissionFile->getStoredPubId('other::urn')) {
						$urnTableRows[] = [$submissionFile->getStoredPubId('other::urn'), __('plugins.pubIds.urn.editor.preview.submissionFiles', ['submissionFileLabel' => $submissionFile->getLocalizedData('name')])];
					} else {
						$urnTableRows[] = [$warningIconHtml . __('submission.status.unassigned'),__('plugins.pubIds.urn.editor.preview.submissionFiles', ['submissionFileLabel' => $submissionFile->getLocalizedData('name')])];
					}
				}
			}
			if (!empty($urnTableRows)) {
				$table = '<table class="pkpTable"><thead><tr>' .
					'<th>' . __('plugins.pubIds.urn.displayName') . '</th>' .
					'<th>' . __('plugins.pubIds.urn.editor.preview.objects') . '</th>' .
					'</tr></thead><tbody>';
				foreach ($urnTableRows as $urnTableRow) {
					$table .= '<tr><td>' . $urnTableRow[0] . '</td><td>' . $urnTableRow[1] . '</td></tr>';
				}
				$table .= '</tbody></table>';
			}
			$form->addField(new \PKP\components\forms\FieldHTML('urn', [
				'description' => $table,
				'groupId' => 'default',
			]));
		}
	}

	/**
	 * Load the FieldTextUrn or FieldPubIdUrn Vue.js component into Vue.js
	 *
	 * @param string $hookName
	 * @param array $args
	 */
	public function loadUrnFieldComponent($hookName, $args) {
		$templateMgr = $args[0];
		$template = $args[1];

		if ($template !== 'workflow/workflow.tpl') {
			return;
		}

		$context = Application::get()->getRequest()->getContext();
		$suffixType = $this->getSetting($context->getId(), 'urnSuffix');
		if ($suffixType === 'default' || $suffixType === 'pattern') {
			$templateMgr->addJavaScript(
				'field-pub-id-urn-component',
				Application::get()->getRequest()->getBaseUrl() . '/' . $this->getPluginPath() . '/js/FieldPubIdUrn.js',
				[
					'contexts' => 'backend',
					'priority' => STYLE_SEQUENCE_LAST,
				]
			);
		} else {
			$templateMgr->addJavaScript(
				'urn-field-component',
				Application::get()->getRequest()->getBaseUrl() . '/' . $this->getPluginPath() . '/js/FieldTextUrn.js',
				[
					'contexts' => 'backend',
					'priority' => STYLE_SEQUENCE_LAST,
				]
			);

			$templateMgr->addStyleSheet(
				'urn-field-component',
				'
					.pkpFormField--urn__input {
						display: inline-block;
					}

					.pkpFormField--urn__button {
						margin-left: 0.25rem;
						height: 2.5rem; // Match input height
					}
				',
				[
					'contexts' => 'backend',
					'inline' => true,
					'priority' => STYLE_SEQUENCE_LAST,
				]
			);
		}
	}


	//
	// Private helper methods
	//
	/**
	 * Get the last, check number.
	 * Algorithm (s. http://www.persistent-identifier.de/?link=316):
	 *  every URN character is replaced with a number according to the conversion table,
	 *  every number is multiplied by it's position/index (beginning with 1),
	 *  the numbers' sum is calculated,
	 *  the sum is divided by the last number,
	 *  the last number of the quotient before the decimal point is the check number.
	 */
	function _calculateCheckNo($urn) {
	    $urnLower = strtolower_codesafe($urn);
	    $conversionTable = array('9' => '41', '8' => '9', '7' => '8', '6' => '7', '5' => '6', '4' => '5', '3' => '4', '2' => '3', '1' => '2', '0' => '1', 'a' => '18', 'b' => '14', 'c' => '19', 'd' => '15', 'e' => '16', 'f' => '21', 'g' => '22', 'h' => '23', 'i' => '24', 'j' => '25', 'k' => '42', 'l' => '26', 'm' => '27', 'n' => '13', 'o' => '28', 'p' => '29', 'q' => '31', 'r' => '12', 's' => '32', 't' => '33', 'u' => '11', 'v' => '34', 'w' => '35', 'x' => '36', 'y' => '37', 'z' => '38', '-' => '39', ':' => '17', '_' => '43', '/' => '45', '.' => '47', '+' => '49');
	    $newURN = '';
	    for ($i = 0; $i < strlen($urnLower); $i++) {
			$char = $urnLower[$i];
			$newURN .= $conversionTable[$char];
	    }
	    $sum = 0;
	    for ($j = 1; $j <= strlen($newURN); $j++) {
			$sum = $sum + ($newURN[$j-1] * $j);
	    }
	    $lastNumber = $newURN[strlen($newURN)-1];
	    $quot = $sum / $lastNumber;
	    $quotRound = floor($quot);
	    $quotString = (string)$quotRound;
	    return $quotString[strlen($quotString)-1];
	}


}
