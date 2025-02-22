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

use MediaWiki\Extension\ContactManager\Transport\SendgridApiTransport;
use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Title;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use User;

class Mailer {

	/** @var array */
	private $obj;

	/** @var AbstractApiTransport */
	private $transportClass = null;

	/** @var string */
	private $editor;

	/** @var SymfonyMailer */
	private $mailer;

	/** @var User */
	private $user;

	/** @var array */
	public $errors = [];

	/**
	 * @param User $user
	 * @param array $obj
	 * @param string $editor
	 * @return bool
	 */
	public function __construct( $user, $obj, $editor ) {
		$this->user = $user;
		$this->obj = $obj;
		$this->editor = $editor;

		$dns = null;
		$username = null;
		$password = null;
		$host = null;
		$transportScheme = null;

		// @see https://symfony.com/doc/5.x/mailer.html
		switch ( $obj['transport'] ) {
			case 'mailbox':
				if ( empty( $GLOBALS['wgContactManagerSMTP'][$obj['mailbox']] ) ) {
					$this->errors[] = 'credentials not found';
					return false;
				}

				$credentials = $GLOBALS['wgContactManagerSMTP'][$obj['mailbox']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				$transportScheme = 'smtp';
				$username = $credentials['username'];
				$password = $credentials['password'];
				$host = $credentials['server'] . ':' . $credentials['port'];
				break;
			case 'mailer':
				$this->obj['from'] = $this->obj['from_mailer'];
				$schema = $GLOBALS['wgContactManagerSchemasMailer'];
				$mailer = preg_replace( '/\s*-[^-]+$/', '', $obj['mailer'] );
				$query = '[[name::' . $mailer . ']]';
				$results = \VisualData::getQueryResults( $schema, $query );

				if ( empty( $results[0]['data'] ) ) {
					$this->errors[] = 'mailer not found';
					return false;
				}
				$data = $results[0]['data'];
				if ( empty( $GLOBALS['wgContactManager' . strtoupper( $data['provider'] ) ][$data['name']] ) ) {
					$this->errors[] = 'credentials not found';
					return false;
				}

				$credentials = $GLOBALS['wgContactManager' . strtoupper( $data['provider'] ) ][$data['name']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				if ( empty( $credentials[$data['transport']] ) ) {
					$this->errors[] = 'transport not found';
					return false;
				}

				$credentials = $credentials[$data['transport']];
				$credentials = array_change_key_case( $credentials, CASE_LOWER );

				// @see https://symfony.com/doc/5.x/mailer.html
				switch ( $data['provider'] ) {
					case 'smtp':
						$transportScheme = 'smtp';
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
										$transportScheme = 'ses+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'gmail':
										$transportScheme = 'gmail+smtp';
										$username = $credentials['username'];
										$password = $credentials['app-password'];
										break;
									case 'mandrill':
										$transportScheme = 'mandrill+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'mailgun':
										$transportScheme = 'mailgun+smtp';
										$username = $credentials['username'];
										$password = $credentials['password'];
										break;
									case 'mailjet':
										$transportScheme = 'mailjet+smtp';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'postmark':
										$transportScheme = 'postmark+smtp';
										$username = $credentials['id'];
										break;
									case 'sendgrid':
										$transportScheme = 'sendgrid+smtp';
										$username = $credentials['key'];
										break;
									case 'sendinblue':
										$transportScheme = 'sendinblue+smtp';
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
										$transportScheme = 'ses+htpps';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'gmail':
										break;
									case 'mandrill':
										$transportScheme = 'mandrill+htpps';
										$username = $credentials['key'];
										break;
									case 'mailgun':
										$transportScheme = 'mailgun+htpps';
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
										$transportScheme = 'ses+api';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'gmail':
										break;
									case 'mandrill':
										$transportScheme = 'mandrill+api';
										$username = $credentials['key'];
										break;
									case 'mailgun':
										$transportScheme = 'mailgun+api';
										$username = $credentials['key'];
										$password = $credentials['domain'];
										break;
									case 'mailjet':
										$transportScheme = 'mailjet+api';
										$username = $credentials['access_key'];
										$password = $credentials['secret_key'];
										break;
									case 'postmark':
										$transportScheme = 'postmark+api';
										$username = $credentials['key'];
										break;
									case 'sendgrid':
										$transportScheme = 'sendgrid+api';
										$username = $credentials['key'];
										$this->transportClass = new SendgridApiTransport( $username );
										break;
									case 'sendinblue':
										$transportScheme = 'sendinblue+api';
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

		if ( $this->transportClass ) {
			$this->mailer = new SymfonyMailer( $this->transportClass );
			return true;
		}

		if ( !$dns ) {
			if ( !$username ) {
				$this->errors[] = 'transport not supported';
				return false;
			}

			// ses+smtp://USERNAME:PASSWORD@default
			$dns = $transportScheme . '://' . urlencode( $username );

			if ( $password ) {
				$dns .= ':' . urlencode( $password );
			}

			$dns .= '@' . ( $host ?? 'default' );
		}

		$transport = Transport::fromDsn( $dns );
		$this->mailer = new SymfonyMailer( $transport );

		return true;
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
	 * @param array $recipients
	 * @return array
	 */
	public function getContactDataFromRecipients( $recipients ) {
		if ( !count( $recipients ) ) {
			return [];
		}

		$parsedRecipients = array_filter( array_map( static function ( $value ) {
			return \ContactManager::parseRecipient( $value );
		}, $recipients ) );

		if ( !count( $parsedRecipients ) ) {
			return [];
		}

		$names = [];
		$emailAddresses = [];
		foreach ( $parsedRecipients as $value ) {
			$names[] = trim( $value[0] );
			$emailAddresses[] = trim( $value[1] );
		}
		$schema = $GLOBALS['wgContactManagerSchemasContact'];
		$query = '[[' . implode( '||', array_map( static function ( $value ) {
			return "full_name::$value";
		}, $names ) ) . ']]';

		$ret = \VisualData::getQueryResults( $schema, $query );

		// @TODO log errors
		if ( array_key_exists( 'errors', $ret ) ) {
			return [];
		}

		return $this->filterResultObjectEmailAddresses( $ret, $emailAddresses );
	}

	/**
	 * @param array $result
	 * @param array $addresses
	 * @return array
	 */
	public function filterResultObjectEmailAddresses( $result, $addresses ) {
		// remove unselected email addresses
		foreach ( $result as $key => $value ) {
			if ( !array_key_exists( 'email_addresses', $value['data'] ) ) {
				$value['data']['email_addresses'] = [];
			}
			$result[$key]['data']['email_addresses'] = array_values(
				array_intersect( $value['data']['email_addresses'], $addresses )
			);
		}
		return $result;
	}

	private function prepareData() {
		$contactsData = [
			'to' => [],
			'cc' => [],
			'bcc' => [],
			'bcc_categories' => [],
		];

		if ( !empty( $this->obj['substitutions'] ) ) {
			$contactsData['to'] = $this->getContactDataFromRecipients( $this->obj['to'] );
			$contactsData['cc'] = $this->getContactDataFromRecipients( $this->obj['cc'] );
			$contactsData['bcc'] = $this->getContactDataFromRecipients( $this->obj['bcc'] );
		}

		if ( !empty( $this->obj['bcc_categories'] ) ) {
			$schema = $GLOBALS['wgContactManagerSchemasContact'];
			$query = '[[' . implode( '||', array_map( static function ( $value ) {
				return "Category:$value";
			}, $this->obj['bcc_categories'] ) ) . ']]';

			$contactsData['bcc_categories'] = \VisualData::getQueryResults( $schema, $query );

			if ( array_key_exists( 'exclude_bcc_categories', $this->obj )
				&& is_array( $this->obj['exclude_bcc_categories'] )
			) {
				foreach ( $contactsData['bcc_categories'] as $key => $value ) {
					$contactsData['bcc_categories'][$key]['data']['email_addresses'] = array_diff(
						$value['data']['email_addresses'],
						$this->obj['exclude_bcc_categories']
					);
				}
			}
		}

		// append to bcc, use standard personalizations object
		if ( empty( $this->obj['substitutions'] ) ) {
			foreach ( $contactsData as $key => $value ) {
				foreach ( $value as $k => $v ) {
					$data_ = $v['data'];
					if ( empty( $data_['email_addresses'][0] ) ) {
						continue;
					}
					$this->obj['bcc'][] = new Address( $data_['email_addresses'][0], $data_['full_name'] );
				}
			}
			return;
		}

		// identify the properties needed for substitutions
		$filterProperties = [ 'full_name' => '', 'email_addresses' => '' ];
		foreach ( $contactsData as $value ) {
			if ( count( $value ) ) {
				$schemaProperties = array_keys( $value[0]['data'] );
				foreach ( $schemaProperties as $property ) {
					if ( strpos( $this->obj['text'], "%$property%" ) !== false
						|| strpos( $this->obj['text_html'], "%$property%" ) !== false
					) {
						$filterProperties[$property] = '';
					}
				}
				break;
			}
		}

		foreach ( $contactsData as $key => $value ) {
			foreach ( $value as $k => $v ) {
				$contactsData[$key][$k]['data'] = array_intersect_key( $v['data'], $filterProperties );
			}
		}

		// *** 'template_id' is not used anymore, use an alternative
		// form for that
		// @FIXME this is sendgrid api v3 specific, extend with other
		// providers as needed
		$substitutionKey = ( empty( $this->obj['template_id'] ) ? 'substitutions'
			: 'dynamic_template_data' );

		$personalizations = [];
		foreach ( $contactsData as $key => $value ) {
			foreach ( $value as $k => $v ) {
				$data_ = $v['data'];
				if ( empty( $data_['email_addresses'][0] ) ) {
					continue;
				}
				$email_ = $data_['email_addresses'][0];
				unset( $data_['email_addresses'] );
				$personalizations[] = [
					// must be an array
					( $key !== 'bcc_categories' ? $key : 'bcc' ) => [
						[
							'email' => $email_,
							'name' => $data_['full_name'],
						]
					],
					$substitutionKey => ( $substitutionKey === 'dynamic_template_data' ?
						$data_ : array_combine( array_map( static function ( $value ) {
							return "%$value%";
						}, array_keys( $data_ ) ), array_values( $data_ ) ) )
				];
			}
		}

		$this->transportClass->setPersonalizations( $personalizations );
	}

	/**
	 * @return string|void
	 */
	public function renderTwigTemplate() {
		$templateTitle = Title::newFromText( $this->obj['template'], NS_CONTACTMANAGER_EMAIL_TEMPLATE );
		if ( !$templateTitle->isKnown() ) {
			return;
		}

		$content = \VisualData::getWikipageContent( $templateTitle );

		if ( !$content ) {
			return;
		}

		$templateName = 'twigTemplate';
		$loader = new ArrayLoader( [
			$templateName => $content,
		] );

		$twig = new Environment( $loader );
		$substitutions = [ 'body' => $this->obj['text'], 'subject' => $this->obj['subject'] ];

		try {
			$html = $twig->render( $templateName, $substitutions );

		} catch ( \Exception $e ) {
			$this->errors[] = $e->getMessage();
			return false;
		}

		return $html;
	}

	/**
	 * @return bool
	 */
	public function sendEmail() {
		if ( $this->transportClass && method_exists( $this->transportClass, 'setPersonalizations' ) ) {
			$this->prepareData();
		}

		$email = new Email();
		$email->from( $this->obj['from'] );

		if ( $this->editor === 'VisualEditor' ) {
			[ $text, $html ] = $this->parseWikitext( $this->obj['text'] );
			$email->text( $text );
			$email->html( $html );

		} else {
			if ( $this->obj['is_html'] ) {
				$html = $this->obj['text_html'];
				$html2Text = new \Html2Text\Html2Text( $html );
				$text = $html2Text->getText();
				$email->text( $text );
				$email->html( $html );

			} else {
				if ( !empty( $this->obj['template'] ) ) {
					// throws an error if it does not work
					$email->html( $this->renderTwigTemplate() );
				}

				$email->text( $this->obj['text'] );
			}
		}

		// this is equivalent to $email->to( ...$this->obj['to'] );
		// however it filters the invalid email addresses
		$getParsedRecipients = static function ( $recipients ) {
			$arr = array_filter( array_map( static function ( $value ) {
				return \ContactManager::parseRecipient( $value );
			}, $recipients ) );

			return array_map( static function ( $value ) {
				return new Address( $value[1], $value[0] );
			}, $arr );
		};

		if ( count( $this->obj['to'] ) ) {
			$email->to( ...$getParsedRecipients( $this->obj['to'] ) );
		}

		if ( count( $this->obj['cc'] ) ) {
			$email->cc( ...$getParsedRecipients( $this->obj['cc'] ) );
		}

		if ( count( $this->obj['bcc'] ) ) {
			$email->bcc( ...$getParsedRecipients( $this->obj['bcc'] ) );
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

		} catch ( TransportExceptionInterface | TransportException | \Exception $e ) {
			$this->errors[] = $e->getMessage();
			return false;
		}

		return true;
	}
}
