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

use MediaWiki\Category\Category;
use MediaWiki\Extension\ContactManager\Transport\SendgridApiTransport;
use MediaWiki\Extension\VisualData\DatabaseManager;
use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use RequestContext;
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

	/** @var array */
	private $personalizations = [];

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
	 * @param array $allPrintouts
	 * @return array
	 */
	public function getContactDataFromRecipients( $recipients, $allPrintouts ) {
		if ( !count( $recipients ) ) {
			return [];
		}

		$parsedRecipients = array_filter( array_map( static function ( $value ) {
			return \ContactManager::parseRecipient( $value );
		}, $recipients ) );

		if ( !count( $parsedRecipients ) ) {
			return [];
		}

		$emailAddresses = [];
		foreach ( $parsedRecipients as $value ) {
			$emailAddresses[] = trim( $value[1] );
		}

		$schemaName = $GLOBALS['wgContactManagerSchemasContact'];
		$query = '[[' . implode( '||', array_map( static function ( $value ) {
			return "email::$value";
		}, $emailAddresses ) ) . ']]';

		$params = [ 'nested' => false ];
		$ret = \VisualData::getQueryResults( $schemaName, $query, $allPrintouts, $params );

		// @TODO log errors
		if ( array_key_exists( 'errors', $ret ) ) {
			return [];
		}

		return $ret;
	}

	/**
	 * @param array $schema
	 * @param string $text
	 * @return array
	 */
	private function getRelevantPrintouts( $schema, $text ) {
		$emailPrintouts = [];
		$requiredPrintouts = [];
		$callback = static function ( $subSchema, $path, $printout, $property )
			use ( &$emailPrintouts, &$requiredPrintouts, $text
		) {
			// $printout will match both items' subschema or
			// single values
			if ( array_key_exists( 'format', $subSchema ) && $subSchema['format'] === 'email' ) {
				$emailPrintouts[] = $printout;
			}

			if ( strpos( $text, "%$printout%" ) !== false ) {
				$requiredPrintouts[] = $printout;
			}
		};
		$printout = '';
		$path = '';
		DatabaseManager::traverseSchema( $schema, $path, $printout, $callback );

		return [ array_unique( $emailPrintouts ), $requiredPrintouts ];
	}

	/**
	 * @param array $data
	 * @param array $recipientsParams
	 * @return array
	 */
	private function getDataMap( $data, $recipientsParams ) {
		$dataMap = [];
		$emailAddresses = [];
		$path = '';
		$printout = '';
		$callback = static function () {
		};
		$callbackValue = static function ( $schema, &$data, $path, $printout, $key )
			use ( $recipientsParams, &$dataMap, &$emailAddresses
		) {
			if ( in_array( $printout, $recipientsParams['requiredPrintouts'] ) ) {
				$dataMap[$printout] = $data;
			}
			if ( in_array( $printout, $recipientsParams['emailPrintouts'] ) ) {
				$emailAddresses[] = $data;
			}
		};
		DatabaseManager::traverseData( $recipientsParams['schema'], $data, $path, $printout, $callback, $callbackValue );

		return [ $dataMap, $emailAddresses ];
	}

	/**
	 * @param string $key
	 * @param string $name
	 * @param array $requiredPrintouts
	 * @param array $emailAddresses
	 * @param array $dataMap
	 */
	private function appendToPayload( $key, $name, $requiredPrintouts, $emailAddresses, $dataMap ) {
		if ( count( $requiredPrintouts ) === 0 ) {
			$this->obj[$key][] = new Address( $emailAddresses[0], $name );
			return;
		}

		$this->personalizations[] = [
			// must be an array
			$key => [
				[
					'email' => $emailAddresses[0],
					'name' => $name,
				]
			],
			'substitutions' => array_combine( array_map( static function ( $value ) {
				return "%$value%";
			}, array_keys( $dataMap ) ), array_values( $dataMap ) )
		];
	}

	private function prepareData() {
		$context = RequestContext::getMain();

		$contactsData = [
			'to' => [],
			'cc' => [],
			'bcc' => [],
		];

		// get required printouts for Contacts schema
		$schemaName = $GLOBALS['wgContactManagerSchemasContact'];
		$schema = \VisualData::getSchema( $context, $schemaName );
		[ $emailPrintouts, $requiredPrintouts ] = $this->getRelevantPrintouts( $schema, $this->obj['text'] );
		$allPrintouts = array_merge( $requiredPrintouts, $emailPrintouts );

		if ( count( $allPrintouts ) > 0 ) {
			if ( !in_array( 'full_name', $allPrintouts ) ) {
				$allPrintouts[] = 'full_name';
			}

			$contactsData['to'] = $this->getContactDataFromRecipients( $this->obj['to'], $allPrintouts );
			$contactsData['cc'] = $this->getContactDataFromRecipients( $this->obj['cc'], $allPrintouts );
			$contactsData['bcc'] = $this->getContactDataFromRecipients( $this->obj['bcc'], $allPrintouts );

			// $recipientsParams = [
			// 	'emailPrintouts' => $emailPrintouts,
			// 	'requiredPrintouts' => $requiredPrintouts,
			// 	'schema' => $schema
			// ];

			foreach ( $contactsData as $key => $value ) {
				foreach ( $value as $k => $v ) {
					$dataMap = array_intersect_key( $v['data'], array_flip( $requiredPrintouts ) );
					$emailAddresses = [];
					foreach ( $emailPrintouts as $printout ) {
						$emailAddresses[] = $v['data'][$printout];
					}
					$name = $v['data']['full_name'];

					// *** not required, since we use $params = [ 'nested' => false ];
					// inside getContactDataFromRecipients
					// [ $dataMap, $emailAddresses ] = $this->getDataMap( $v['data'], $recipientsParams );

					$this->appendToPayload( $key, $name, $requiredPrintouts, $emailAddresses, $dataMap );
				}
			}
		}

		$categoriesParams = [];
		if ( !empty( $this->obj['bcc_categories'] ) ) {
			foreach ( $this->obj['bcc_categories'] as $value ) {
				// $title_ = Title::newFromText( $value, NS_CATEGORY );

				// get the schemas associated to the articles within
				// the given category and the relevant printouts
				$cat = Category::newFromName( $value );
				$iterator_ = $cat->getMembers( 10 );
				while ( $iterator_->valid() ) {
					$title_ = $iterator_->current();
					$data_ = \VisualData::getJsonData( $title_ );
					if ( !empty( $data_['schemas'] ) ) {
						$schemas_ = array_keys( $data_['schemas'] );
						foreach ( $schemas_ as $schemaName_ ) {
							$schema_ = \VisualData::getSchema( $context, $schemaName_ );
							[ $emailPrintouts, $requiredPrintouts ] = $this->getRelevantPrintouts( $schema_, $this->obj['text'] );

							if ( $emailPrintouts ) {
								$categoriesParams[$value][$schemaName_] = [ $emailPrintouts, $requiredPrintouts, $schema_ ];
							}
						}
					}
					$iterator_->next();
				}
			}

			// get articles data of the requested categories
			// using the relevant schema and the required printouts
			$categoriesData = [];
			foreach ( $categoriesParams as $cat => $value ) {
				foreach ( $value as $schemaName => $values ) {
					$query = '[[' . implode( '||', array_map( static function ( $value ) {
						return "Category:$value";
					}, $this->obj['bcc_categories'] ) ) . ']]';

					[ $emailPrintouts, $requiredPrintouts ] = $values;
					$params = [ 'format' => 'json-raw' ];
					$allPrintouts = array_merge( $emailPrintouts, $requiredPrintouts );
					$categoriesData[$cat][$schemaName] = \VisualData::getQueryResults( $schemaName, $query, $allPrintouts, $params );
				}
			}

			$exclude_bcc_categories = [];
			if ( array_key_exists( 'exclude_bcc_categories', $this->obj )
				&& is_array( $this->obj['exclude_bcc_categories'] )
			) {
				$exclude_bcc_categories = $this->obj['exclude_bcc_categories'];
			}

			// create the payload associating the required printouts
			// to nested data
			foreach ( $categoriesData as $cat => $value ) {
				foreach ( $value as $schemaName => $values ) {
					[ $emailPrintouts, $requiredPrintouts, $schema_ ] = $categoriesParams[$cat][$schemaName];

					$recipientsParams = [
						'emailPrintouts' => $emailPrintouts,
						'requiredPrintouts' => $requiredPrintouts,
						'schema' => $schema_
					];

					foreach ( $values as $k => $v ) {
						[ $dataMap, $emailAddresses ] = $this->getDataMap( $v['data'], $recipientsParams );
						if ( count( array_intersect( $exclude_bcc_categories, $emailAddresses ) ) ) {
							continue;
						}

						$namePrintouts = [ 'full_name', 'name', 'contact_person' ];
						$name = '';
						foreach ( $namePrintouts as $printout_ ) {
							if ( array_key_exists( $printout_, $dataMap ) ) {
								$name = $dataMap[$printout_];
								break;
							}
						}
						$this->appendToPayload( 'bcc', $name, $requiredPrintouts, $emailAddresses, $dataMap );
					}
				}
			}
		}

		$this->transportClass->setPersonalizations( $this->personalizations );
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
