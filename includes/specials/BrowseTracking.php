<?php

/**
 * This file is part of the MediaWiki extension ContactManager.
 *
 * ContactManager is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ContactManager is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ContactManager.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager\Special;

// use MediaWiki\Extension\ContactManager\Special\Tracking as TrackingSpecial;
use MediaWiki\MediaWikiServices;

/**
 * A special page that lists protected pages
 *
 * @ingroup SpecialPage
 */
class BrowseTracking extends \SpecialPage {

	/** @var int */
	public $par;

	/** @var Title */
	public $localTitle;

	/** @var Title */
	public $localTitlePar;

	/** @var User */
	private $user;

	/** @var Request */
	private $request;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		$listed = true;
		parent::__construct( 'ContactManagerBrowseTracking', '', $listed );
	}

	/**
	 * @return string|Message
	 */
	public function getDescription() {
		// @see SpecialPageFactory
		$title = $this->getContext()->getTitle();
		$bits = explode( '/', $title->getDbKey(), 2 ) + [ null, null ];
		$par = (int)$bits[1];

		if ( !$par ) {
			$msg = $this->msg( 'contactmanagerbrowsetracking' );

		} else {
			$view = strtolower( (string)$this->getRequest()->getVal( 'view' ) );
			$msg = $this->msg( "contactmanager-browsetracking-$view" );
		}

		if ( version_compare( MW_VERSION, '1.40', '>' ) ) {
			return $msg;
		}
		return $msg->text();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();
		$this->setHeaders();
		$this->outputHeader();

		$user = $this->getUser();
		$isAuthorized = \ContactManager::isAuthorizedGroup( $user );

		if ( !$isAuthorized ) {
			if ( !$user->isAllowed( 'contactmanager-can-browse-tracking' ) ) {
				$this->displayRestrictionError();
				return;
			}
		}

		$this->par = $par;
		$out = $this->getOutput();

		$out->addModuleStyles( 'mediawiki.special' );
		$out->enableOOUI();

		$out->addModules( [ 'ext.ContactManager' ] );

		$this->addHelpLink( 'Extension:ContactManager' );

		$request = $this->getRequest();
		$this->request = $request;
		$this->user = $user;

		$view = $request->getVal( 'view' );
		$class = ( !$par ? 'Sent' : $view );

		$this->localTitle = \SpecialPage::getTitleFor( 'ContactManagerBrowseTracking' );
		$this->localTitlePar = \SpecialPage::getTitleFor( 'ContactManagerBrowseTracking', $this->par );

		if ( $par ) {
			switch ( $view ) {
				case 'Tracking':
					$returnTo = $this->getAddReturnTo(
						$this->localTitle,
						[],
						$this->msg( 'contactmanager-browsetracking-form-returnlink-text' )->text(),
					);
					break;

				case 'Details':
					$query_ = [
						'view' => 'Tracking',
						'account' => $request->getVal( 'account' )
					];
					$returnTo = $this->getAddReturnTo(
						$this->localTitlePar,
						$query_,
						$this->msg( 'contactmanager-browsetracking-form-returnlink-tracking-text' )->text(),
					);
					break;
			}
			$out->addHTML( $returnTo );
		}

		$out->addHTML( $this->showOptions( $view ) );

		$class = "MediaWiki\\Extension\\ContactManager\\Pagers\\$class";
		$pager = new $class(
			$this,
			$request,
			$this->getLinkRenderer()
		);

		if ( $pager->getNumRows() ) {
			$out->addParserOutputContent( $pager->getFullOutput() );

		} else {
			$out->addWikiMsg( 'contactmanager-browsetracking-table-empty' );
		}
	}

	/**
	 * @see MediaWiki\Output\OutputPage -> addReturnTo
	 * @param LinkTarget $title Title to link
	 * @param array $query Query string parameters
	 * @param string|null $text Text of the link (input is not escaped)
	 * @param array $options Options array to pass to Linker
	 * @return string
	 */
	public function getAddReturnTo( $title, array $query = [], $text = null, $options = [] ) {
		$linkRenderer = MediaWikiServices::getInstance()
			->getLinkRendererFactory()->createFromLegacyOptions( $options );
		return $this->msg( 'contactmanager-browsetracking-form-returnlink-placeholder' )->rawParams(
			$linkRenderer->makeLink( $title, $text, [], $query ) )->escaped();
		// return "<p id=\"mw-returnto\">{$link}</p>\n";
	}

	/**
	 * @param string $view
	 * @return string
	 */
	protected function showOptions( $view ) {
		switch ( $view ) {
			case 'Tracking':
				return $this->showOptionsTracking();
			case 'Details':
				return $this->showOptionsDetails();
			case 'Sent':
			default:
				return $this->showOptionsSent();
		}

		return '';
	}

	/**
	 * @return string
	 */
	protected function showOptionsSent() {
		$formDescriptor = [];

		$user = $this->request->getVal( 'user' );
		$formDescriptor['user'] = [
			'label-message' => 'contactmanager-browsetracking-form-search-user-label',
			'type' => 'user',
			'name' => 'user',
			'required' => false,
			'help-message' => 'contactmanager-browsetracking-form-search-user-help',
			'default' => $user,
		];

		$schema = $GLOBALS['wgContactManagerSchemasMailbox'];
		$query = '[[name::+]]';
		$printouts = [ 'name' ];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts );

		if ( array_key_exists( 'errors', $results ) ) {
			throw new \MWException( 'error query: ' . print_r( $results, true ) );
		}

		$mailboxes = [];
		if ( !array_key_exists( 'errors', $results ) ) {
			foreach ( $results as $value ) {
				$mailboxes[$value['data']['name']] = $value['data']['name'];
			}
		}

		$mailbox = $this->request->getVal( 'mailbox' );
		$formDescriptor['mailbox'] = [
			'label-message' => 'contactmanager-browsetracking-form-search-mailbox-label',
			'type' => 'select',
			'name' => 'mailbox',
			'required' => false,
			'help-message' => 'contactmanager-browsetracking-form-search-mailbox-help',
			'options' => $mailboxes,
			'default' => $mailbox
		];

		$schema = $GLOBALS['wgContactManagerSchemasMailer'];
		$query = '[[name::+]]';
		$printouts = [ 'name' ];
		$results = \VisualData::getQueryResults( $schema, $query, $printouts );

		if ( array_key_exists( 'errors', $results ) ) {
			throw new \MWException( 'error query: ' . print_r( $results, true ) );
		}

		$accounts = [];
		if ( !array_key_exists( 'errors', $results ) ) {
			foreach ( $results as $value ) {
				$accounts[$value['data']['name']] = $value['data']['name'];
			}
		}

		$account = $this->request->getVal( 'account' );
		$formDescriptor['account'] = [
			'label-message' => 'contactmanager-browsetracking-form-search-account-label',
			'type' => 'select',
			'name' => 'account',
			'required' => false,
			'help-message' => 'contactmanager-browsetracking-form-search-account-help',
			'options' => $accounts,
			'default' => $account,
		];

		$htmlForm = new \OOUIHTMLForm( $formDescriptor, $this->getContext() );

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'contactmanager-browsetracking-form-search-legend' )
			->setSubmitText( $this->msg( 'contactmanager-browsetracking-form-search-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @return string
	 */
	protected function showOptionsTracking() {
		$formDescriptor = [];

		$email = $this->request->getVal( 'email' );
		$formDescriptor['email'] = [
			'label-message' => 'contactmanager-browsetracking-form-search-email-label',
			'type' => 'text',
			'name' => 'email',
			'required' => false,
			'help-message' => 'contactmanager-browsetracking-form-search-email-help',
			'default' => $email,
		];

		$name = $this->request->getVal( 'name' );
		$formDescriptor['name'] = [
			'label-message' => 'contactmanager-browsetracking-form-search-name-label',
			'type' => 'text',
			'name' => 'name',
			'required' => false,
			'help-message' => 'contactmanager-browsetracking-form-search-name-help',
			'default' => $name,
		];

		$hiddenFields = [ 'view', 'account' ];
		foreach ( $hiddenFields as $value ) {
			$value_ = $this->request->getVal( $value );
			$formDescriptor[$value] = [
				'type' => 'hidden',
				'name' => $value,
				'required' => true,
				'default' => $value_,
			];
		}

		$htmlForm = new \OOUIHTMLForm( $formDescriptor, $this->getContext() );

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'contactmanager-browsetracking-form-search-legend' )
			->setSubmitText( $this->msg( 'contactmanager-browsetracking-form-search-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @return string
	 */
	protected function showOptionsDetails() {
		$formDescriptor = [];
		$mailer = $this->request->getVal( 'mailer' );
		switch ( $mailer ) {
			case 'sendgrid':
				// $columns = TrackingSpecial::$sendgridColumns;

				$event = $this->request->getVal( 'event' );
				$formDescriptor['event'] = [
					'label-message' => 'contactmanager-browsetracking-form-search-event-label',
					'type' => 'text',
					'name' => 'event',
					'required' => false,
					'help-message' => 'contactmanager-browsetracking-form-search-event-help',
					'default' => $event,
				];

				$sg_event_id = $this->request->getVal( 'sg_event_id' );
				$formDescriptor['sg_event_id'] = [
					'label-message' => 'contactmanager-browsetracking-form-search-sg_event_id-label',
					'type' => 'text',
					'name' => 'sg_event_id',
					'required' => false,
					'help-message' => 'contactmanager-browsetracking-form-search-sg_event_id-help',
					'default' => $sg_event_id,
				];

				$sg_message_id = $this->request->getVal( 'sg_message_id' );
				$formDescriptor['sg_message_id'] = [
					'label-message' => 'contactmanager-browsetracking-form-search-sg_message_id-label',
					'type' => 'text',
					'name' => 'sg_message_id',
					'required' => false,
					'help-message' => 'contactmanager-browsetracking-form-search-sg_message_id-help',
					'default' => $sg_message_id,
				];

				$hiddenFields = [ 'view', 'page_id', 'email', 'account', 'mailer' ];
				foreach ( $hiddenFields as $value ) {
					$value_ = $this->request->getVal( $value );
					$formDescriptor[$value] = [
						'type' => 'hidden',
						'name' => $value,
						'required' => true,
						'default' => $value_,
					];
				}
				break;
		}

		$htmlForm = new \OOUIHTMLForm( $formDescriptor, $this->getContext() );

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'contactmanager-browsetracking-form-search-legend' )
			->setSubmitText( $this->msg( 'contactmanager-browsetracking-form-search-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'contactmanager';
	}
}
