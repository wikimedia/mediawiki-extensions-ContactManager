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
 * along with ContactManager.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2024, https://wikisphere.org
 */

namespace MediaWiki\Extension\ContactManager;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../../vendor/autoload.php';
}

use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Title;
use User;

class Mailer {

	/** @var array */
	private $obj;

	/** @var string */
	private $editor;

	/** @var SymfonyMailer */
	private $mailer;

	/** @var User */
	private $user;

	/**
	 * @param User $user
	 * @param array $obj
	 * @param string $editor
	 * @param array &$errors []
	 */
	public function __construct( $user, $obj, $editor, &$errors = [] ) {
		$this->user = $user;
		$this->obj = $obj;
		$this->editor = $editor;

		$dns = null;
		$username = null;
		$password = null;
		$host = null;

		// @see https://symfony.com/doc/5.x/mailer.html
		switch ( $obj['transport'] ) {
			case 'mailbox':
				if ( empty( $GLOBALS['wgContactManagerSMTP'][$obj['mailbox']] ) ) {
					$errors[] = 'credentials not found';
					return false;
				}

				$credentials = $GLOBALS['wgContactManagerSMTP'][$obj['mailbox']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				$transport = 'smtp';
				$username = $credentials['username'];
				$password = $credentials['password'];
				$host = $credentials['server'] . ':' . $credentials['port'];
				break;
			case 'mailer':
				$this->obj['from'] = $this->obj['from_mailer'];
				$schema = $GLOBALS['wgContactManagerSchemasMailer'];
				$query = '[[name::' . $obj['mailer'] . ']]';
				$results = \VisualData::getQueryResults( $schema, $query );

				if ( empty( $results[0]['data'] ) ) {
					$errors[] = 'mailer not found';
					return false;
				}
				$data = $results[0]['data'];
				if ( empty( $GLOBALS['wgContactManager' . strtoupper( $data['provider'] ) ][$data['name']] ) ) {
					$errors[] = 'credentials not found';
					return false;
				}

				$credentials = $GLOBALS['wgContactManager' . strtoupper( $data['provider'] ) ][$data['name']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				if ( empty( $credentials[$data['transport']] ) ) {
					$errors[] = 'transport not found';
					return false;
				}

				$credentials = $credentials[$data['transport']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				// @see https://symfony.com/doc/5.x/mailer.html
				switch ( $data['provider'] ) {
					case 'smtp':
						$transport = 'smtp';
						$username = $credentials['username'];
						$password = $credentials['password'];
						$host = $credentials['server'] . ':' . $credentials['port'];
						break;
					case 'sendmail':
						$dns = 'sendmail://default';
						break;
					case 'native':
						$dns = 'native://default';
						break;

					default:
						switch ( $data['transport'] ) {
							case 'smtp':
								switch ( $data['provider'] ) {
									case 'amazon':
										// ses+smtp://USERNAME:PASSWORD@default
										$transport = 'ses+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'gmail':
										$transport = 'gmail+smtp';
										$username = $credentials['app-password'];
										break;
									case 'mandrill':
										$transport = 'mandrill+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'mailgun':
										$transport = 'mailgun+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'mailjet':
										$transport = 'mailjet+smtp';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'postmark':
										$transport = 'postmark+smtp';
										$username = $credentials['id'];
										break;
									case 'sendgrid':
										$transport = 'sendgrid+smtp';
										$username = $credentials['key'];
										break;
									case 'sendinblue':
										$transport = 'sendinblue+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'ohmysmtp':
										$dns = 'ohmysmtp+smtp';
										$username = $credentials['api_token'];
										break;
								}
								break;
							case 'http':
								switch ( $data['provider'] ) {
									case 'amazon':
										$transport = 'ses+htpps';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'gmail':
										break;
									case 'mandrill':
										$transport = 'mandrill+htpps';
										$username = $credentials['key'];
										break;
									case 'mailgun':
										$transport = 'mailgun+htpps';
										$username = $credentials['key'];
										$password = $credentials['domain'];
										break;
									case 'mailjet':
									case 'postmark':
									case 'sendgrid':
									case 'sendinblue':
									case 'ohmysmtp':
								}
								break;
							case 'api':
								switch ( $data['provider'] ) {
									case 'amazon':
										$transport = 'ses+api';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'gmail':
										break;
									case 'mandrill':
										$transport = 'mandrill+api';
										$username = $credentials['key'];
										break;
									case 'mailgun':
										$transport = 'mailgun+api';
										$username = $credentials['key'];
										$password = $credentials['domain'];
										break;
									case 'mailjet':
										$transport = 'mailjet+api';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'postmark':
										$transport = 'postmark+api';
										$username = $credentials['key'];
										break;
									case 'sendgrid':
										$transport = 'sendgrid+api';
										$username = $credentials['key'];
										break;
									case 'sendinblue':
										$transport = 'sendinblue+api';
										$username = $credentials['key'];
										break;
									case 'ohmysmtp':
										$dns = 'ohmysmtp+api';
										$username = $credentials['api_token'];
										break;
								}
								break;
						}
				}
		}

		if ( !$dns ) {
			if ( !$username ) {
				$errors[] = 'transport not supported';
				return false;
			}

			// ses+smtp://USERNAME:PASSWORD@default
			$dns = $transport . '://' . urlencode( $username );

			if ( $password ) {
				$dns .= ':' . urlencode( $password );
			}

			$dns .= '@' . ( $host ?? 'default' );
		}

		$transport = Transport::fromDsn( $dns );
		$this->mailer = new SymfonyMailer( $transport );
	}

	/**
	 * @param string $text
	 * @return array
	 */
	public function parseWikitext( $text ) {
		$parser = MediaWikiServices::getInstance()->getParserFactory()->getInstance();
		// $parser->setTitle();
		$parserOptions = ParserOptions::newFromUser( $this->user );
		// $parser->setOptions( $parserOptions );

		// OT_PLAIN
		$parser->setOutputType( Parser::OT_HTML );

		// or use source page
		$t = Title::makeTitle( NS_SPECIAL, 'Badtitle/Parser' );
		$parserOutput = $parser->parse( $text, $t, $parserOptions );

		$options = [
			'allowTOC' => false,
			'injectTOC' => false,
			'enableSectionEditLinks' => false,
			'userLang' => null,
			'skin' => null,
			'unwrap' => false,
			// 'wrapperDivClass' => $this->getWrapperDivClass(),
			'deduplicateStyles' => true,
			'absoluteURLs' => true,
			'includeDebugInfo' => false,
			'bodyContentOnly' => true,
		];

		$html = $parserOutput->getText();
		$html2Text = new \Html2Text\Html2Text( $html );
		$text = $html2Text->getText();

		return [ $text, $html ];
	}

	/**
	 * @return bool
	 */
	public function sendEmail() {
		$email = ( new Email() )
			->from( $this->obj['from'] );

		if ( $this->editor === 'VisualEditor' ) {
			[ $text, $html ] = $this->parseWikitext( $this->obj['text'] );
			$email->text( $text )
				->html( $html );
		} else {
			if ( $this->obj['html'] ) {
				$html = $this->obj['text_html'];
				$html2Text = new \Html2Text\Html2Text( $html );
				$text = $html2Text->getText();
				$email->text( $text )
					->html( $html );
			} else {
				$email->text( $this->obj['text'] );
			}
		}

		if ( count( $this->obj['to'] ) ) {
			$email->to( implode( ', ', $this->obj['to'] ) );
		}

		if ( count( $this->obj['cc'] ) ) {
			$email->to( implode( ', ', $this->obj['cc'] ) );
		}

		if ( count( $this->obj['bcc'] ) ) {
			$email->to( implode( ', ', $this->obj['bcc'] ) );
		}

		if ( empty( $this->obj['subject'] ) ) {
			// zero width space, this is a workaround
			// for the annoying error
			// 'Unable to send an email: The subject is required'
			$this->obj['subject'] = '​';
		}

		$email->subject( $this->obj['subject'] );

		try {
			$this->mailer->send( $email );
		} catch ( TransportExceptionInterface | TransportException | Exception $e ) {
			echo $e->getMessage();
			return false;
		}

		return true;
	}
}
