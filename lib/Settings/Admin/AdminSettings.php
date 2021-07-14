<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Settings\Admin;

use OCA\Talk\MatterbridgeManager;
use OCA\Talk\Config;
use OCA\Talk\Model\Command;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\CommandService;
use OCA\Talk\Exceptions\WrongPermissionsException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

	/** @var Config */
	private $talkConfig;
	/** @var IConfig */
	private $serverConfig;
	/** @var CommandService */
	private $commandService;
	/** @var IInitialState */
	private $initialState;
	/** @var ICacheFactory */
	private $memcacheFactory;
	/** @var IGroupManager */
	private $groupManager;
	/** @var MatterbridgeManager */
	private $bridgeManager;
	/** @var IUser */
	private $currentUser;
	/** @var IL10N */
	private $l10n;
	/** @var IFactory */
	private $l10nFactory;

	public function __construct(Config $talkConfig,
								IConfig $serverConfig,
								CommandService $commandService,
								IInitialState $initialState,
								ICacheFactory $memcacheFactory,
								IGroupManager $groupManager,
								MatterbridgeManager $bridgeManager,
								IUserSession $userSession,
								IL10N $l10n,
								IFactory $l10nFactory) {
		$this->talkConfig = $talkConfig;
		$this->serverConfig = $serverConfig;
		$this->commandService = $commandService;
		$this->initialState = $initialState;
		$this->memcacheFactory = $memcacheFactory;
		$this->groupManager = $groupManager;
		$this->bridgeManager = $bridgeManager;
		$this->currentUser = $userSession->getUser();
		$this->l10n = $l10n;
		$this->l10nFactory = $l10nFactory;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$this->initGeneralSettings();
		$this->initAllowedGroups();
		$this->initCommands();
		$this->initMatterbridge();
		$this->initStunServers();
		$this->initTurnServers();
		$this->initSignalingServers();
		$this->initRequestSignalingServerTrial();
		$this->initSIPBridge();

		return new TemplateResponse('spreed', 'settings/admin-settings', [], '');
	}

	protected function initGeneralSettings(): void {
		$this->initialState->provideInitialState('ask_guest_username', (int) $this->serverConfig->getAppValue('spreed', 'ask_guest_username', '0'));
		$this->initialState->provideInitialState('default_group_notification', (int) $this->serverConfig->getAppValue('spreed', 'default_group_notification', Participant::NOTIFY_MENTION));
		$this->initialState->provideInitialState('conversations_files', (int) $this->serverConfig->getAppValue('spreed', 'conversations_files', '1'));
		$this->initialState->provideInitialState('conversations_files_public_shares', (int) $this->serverConfig->getAppValue('spreed', 'conversations_files_public_shares', '1'));
	}

	protected function initAllowedGroups(): void {
		$this->initialState->provideInitialState('start_calls', (int) $this->serverConfig->getAppValue('spreed', 'start_calls', Room::START_CALL_EVERYONE));

		$groups = $this->getGroupDetailsArray($this->talkConfig->getAllowedConversationsGroupIds(), 'start_conversations');
		$this->initialState->provideInitialState('start_conversations', $groups);

		$groups = $this->getGroupDetailsArray($this->talkConfig->getAllowedTalkGroupIds(), 'allowed_groups');
		$this->initialState->provideInitialState('allowed_groups', $groups);
	}

	protected function initCommands(): void {
		$commands = $this->commandService->findAll();

		$result = array_map(function (Command $command) {
			return $command->asArray();
		}, $commands);

		$this->initialState->provideInitialState('commands', $result);
	}

	protected function initMatterbridge(): void {
		$error = '';
		try {
			$version = (string) $this->bridgeManager->getCurrentVersionFromBinary();
			if ($version === '') {
				$error = 'binary';
			}
		} catch (WrongPermissionsException $e) {
			$version = '';
			$error = 'binary_permissions';
		}
		$this->initialState->provideInitialState(
			'matterbridge_error', $error
		);
		$this->initialState->provideInitialState(
			'matterbridge_version', $version
		);

		$this->initialState->provideInitialState(
			'matterbridge_enable',
			$this->serverConfig->getAppValue('spreed', 'enable_matterbridge', '0') === '1'
		);
	}

	protected function initStunServers(): void {
		$this->initialState->provideInitialState('stun_servers', $this->talkConfig->getStunServers());
		$this->initialState->provideInitialState('has_internet_connection', $this->serverConfig->getSystemValueBool('has_internet_connection', true));
	}

	protected function initTurnServers(): void {
		$this->initialState->provideInitialState('turn_servers', $this->talkConfig->getTurnServers());
	}

	protected function initSignalingServers(): void {
		$this->initialState->provideInitialState('has_cache_configured', $this->memcacheFactory->isAvailable());
		$this->initialState->provideInitialState('signaling_mode', $this->talkConfig->getSignalingMode(false));
		$this->initialState->provideInitialState('signaling_servers', [
			'servers' => $this->talkConfig->getSignalingServers(),
			'secret' => $this->talkConfig->getSignalingSecret(),
			'hideWarning' => $this->talkConfig->getHideSignalingWarning(),
		]);
	}

	protected function initRequestSignalingServerTrial(): void {
		$countries = [
			['code' => 'AD', 'name' => $this->l10n->t('Andorra')],
			['code' => 'AE', 'name' => $this->l10n->t('United Arab Emirates')],
			['code' => 'AF', 'name' => $this->l10n->t('Afghanistan')],
			['code' => 'AG', 'name' => $this->l10n->t('Antigua and Barbuda')],
			['code' => 'AI', 'name' => $this->l10n->t('Anguilla')],
			['code' => 'AL', 'name' => $this->l10n->t('Albania')],
			['code' => 'AM', 'name' => $this->l10n->t('Armenia')],
			['code' => 'AO', 'name' => $this->l10n->t('Angola')],
			['code' => 'AQ', 'name' => $this->l10n->t('Antarctica')],
			['code' => 'AR', 'name' => $this->l10n->t('Argentina')],
			['code' => 'AS', 'name' => $this->l10n->t('American Samoa')],
			['code' => 'AT', 'name' => $this->l10n->t('Austria')],
			['code' => 'AU', 'name' => $this->l10n->t('Australia')],
			['code' => 'AW', 'name' => $this->l10n->t('Aruba')],
			['code' => 'AX', 'name' => $this->l10n->t('Åland Islands')],
			['code' => 'AZ', 'name' => $this->l10n->t('Azerbaijan')],
			['code' => 'BA', 'name' => $this->l10n->t('Bosnia and Herzegovina')],
			['code' => 'BB', 'name' => $this->l10n->t('Barbados')],
			['code' => 'BD', 'name' => $this->l10n->t('Bangladesh')],
			['code' => 'BE', 'name' => $this->l10n->t('Belgium')],
			['code' => 'BF', 'name' => $this->l10n->t('Burkina Faso')],
			['code' => 'BG', 'name' => $this->l10n->t('Bulgaria')],
			['code' => 'BH', 'name' => $this->l10n->t('Bahrain')],
			['code' => 'BI', 'name' => $this->l10n->t('Burundi')],
			['code' => 'BJ', 'name' => $this->l10n->t('Benin')],
			['code' => 'BL', 'name' => $this->l10n->t('Saint Barthélemy')],
			['code' => 'BM', 'name' => $this->l10n->t('Bermuda')],
			['code' => 'BN', 'name' => $this->l10n->t('Brunei Darussalam')],
			['code' => 'BO', 'name' => $this->l10n->t('Bolivia, Plurinational State of')],
			['code' => 'BQ', 'name' => $this->l10n->t('Bonaire, Sint Eustatius and Saba')],
			['code' => 'BR', 'name' => $this->l10n->t('Brazil')],
			['code' => 'BS', 'name' => $this->l10n->t('Bahamas')],
			['code' => 'BT', 'name' => $this->l10n->t('Bhutan')],
			['code' => 'BV', 'name' => $this->l10n->t('Bouvet Island')],
			['code' => 'BW', 'name' => $this->l10n->t('Botswana')],
			['code' => 'BY', 'name' => $this->l10n->t('Belarus')],
			['code' => 'BZ', 'name' => $this->l10n->t('Belize')],
			['code' => 'CA', 'name' => $this->l10n->t('Canada')],
			['code' => 'CC', 'name' => $this->l10n->t('Cocos (Keeling) Islands')],
			['code' => 'CD', 'name' => $this->l10n->t('Congo, the Democratic Republic of the')],
			['code' => 'CF', 'name' => $this->l10n->t('Central African Republic')],
			['code' => 'CG', 'name' => $this->l10n->t('Congo')],
			['code' => 'CH', 'name' => $this->l10n->t('Switzerland')],
			['code' => 'CI', 'name' => $this->l10n->t('Côte d\'Ivoire')],
			['code' => 'CK', 'name' => $this->l10n->t('Cook Islands')],
			['code' => 'CL', 'name' => $this->l10n->t('Chile')],
			['code' => 'CM', 'name' => $this->l10n->t('Cameroon')],
			['code' => 'CN', 'name' => $this->l10n->t('China')],
			['code' => 'CO', 'name' => $this->l10n->t('Colombia')],
			['code' => 'CR', 'name' => $this->l10n->t('Costa Rica')],
			['code' => 'CU', 'name' => $this->l10n->t('Cuba')],
			['code' => 'CV', 'name' => $this->l10n->t('Cabo Verde')],
			['code' => 'CW', 'name' => $this->l10n->t('Curaçao')],
			['code' => 'CX', 'name' => $this->l10n->t('Christmas Island')],
			['code' => 'CY', 'name' => $this->l10n->t('Cyprus')],
			['code' => 'CZ', 'name' => $this->l10n->t('Czechia')],
			['code' => 'DE', 'name' => $this->l10n->t('Germany')],
			['code' => 'DJ', 'name' => $this->l10n->t('Djibouti')],
			['code' => 'DK', 'name' => $this->l10n->t('Denmark')],
			['code' => 'DM', 'name' => $this->l10n->t('Dominica')],
			['code' => 'DO', 'name' => $this->l10n->t('Dominican Republic')],
			['code' => 'DZ', 'name' => $this->l10n->t('Algeria')],
			['code' => 'EC', 'name' => $this->l10n->t('Ecuador')],
			['code' => 'EE', 'name' => $this->l10n->t('Estonia')],
			['code' => 'EG', 'name' => $this->l10n->t('Egypt')],
			['code' => 'EH', 'name' => $this->l10n->t('Western Sahara')],
			['code' => 'ER', 'name' => $this->l10n->t('Eritrea')],
			['code' => 'ES', 'name' => $this->l10n->t('Spain')],
			['code' => 'ET', 'name' => $this->l10n->t('Ethiopia')],
			['code' => 'FI', 'name' => $this->l10n->t('Finland')],
			['code' => 'FJ', 'name' => $this->l10n->t('Fiji')],
			['code' => 'FK', 'name' => $this->l10n->t('Falkland Islands (Malvinas)')],
			['code' => 'FM', 'name' => $this->l10n->t('Micronesia, Federated States of')],
			['code' => 'FO', 'name' => $this->l10n->t('Faroe Islands')],
			['code' => 'FR', 'name' => $this->l10n->t('France')],
			['code' => 'GA', 'name' => $this->l10n->t('Gabon')],
			['code' => 'GB', 'name' => $this->l10n->t('United Kingdom of Great Britain and Northern Ireland')],
			['code' => 'GD', 'name' => $this->l10n->t('Grenada')],
			['code' => 'GE', 'name' => $this->l10n->t('Georgia')],
			['code' => 'GF', 'name' => $this->l10n->t('French Guiana')],
			['code' => 'GG', 'name' => $this->l10n->t('Guernsey')],
			['code' => 'GH', 'name' => $this->l10n->t('Ghana')],
			['code' => 'GI', 'name' => $this->l10n->t('Gibraltar')],
			['code' => 'GL', 'name' => $this->l10n->t('Greenland')],
			['code' => 'GM', 'name' => $this->l10n->t('Gambia')],
			['code' => 'GN', 'name' => $this->l10n->t('Guinea')],
			['code' => 'GP', 'name' => $this->l10n->t('Guadeloupe')],
			['code' => 'GQ', 'name' => $this->l10n->t('Equatorial Guinea')],
			['code' => 'GR', 'name' => $this->l10n->t('Greece')],
			['code' => 'GS', 'name' => $this->l10n->t('South Georgia and the South Sandwich Islands')],
			['code' => 'GT', 'name' => $this->l10n->t('Guatemala')],
			['code' => 'GU', 'name' => $this->l10n->t('Guam')],
			['code' => 'GW', 'name' => $this->l10n->t('Guinea-Bissau')],
			['code' => 'GY', 'name' => $this->l10n->t('Guyana')],
			['code' => 'HK', 'name' => $this->l10n->t('Hong Kong')],
			['code' => 'HM', 'name' => $this->l10n->t('Heard Island and McDonald Islands')],
			['code' => 'HN', 'name' => $this->l10n->t('Honduras')],
			['code' => 'HR', 'name' => $this->l10n->t('Croatia')],
			['code' => 'HT', 'name' => $this->l10n->t('Haiti')],
			['code' => 'HU', 'name' => $this->l10n->t('Hungary')],
			['code' => 'ID', 'name' => $this->l10n->t('Indonesia')],
			['code' => 'IE', 'name' => $this->l10n->t('Ireland')],
			['code' => 'IL', 'name' => $this->l10n->t('Israel')],
			['code' => 'IM', 'name' => $this->l10n->t('Isle of Man')],
			['code' => 'IN', 'name' => $this->l10n->t('India')],
			['code' => 'IO', 'name' => $this->l10n->t('British Indian Ocean Territory')],
			['code' => 'IQ', 'name' => $this->l10n->t('Iraq')],
			['code' => 'IR', 'name' => $this->l10n->t('Iran, Islamic Republic of')],
			['code' => 'IS', 'name' => $this->l10n->t('Iceland')],
			['code' => 'IT', 'name' => $this->l10n->t('Italy')],
			['code' => 'JE', 'name' => $this->l10n->t('Jersey')],
			['code' => 'JM', 'name' => $this->l10n->t('Jamaica')],
			['code' => 'JO', 'name' => $this->l10n->t('Jordan')],
			['code' => 'JP', 'name' => $this->l10n->t('Japan')],
			['code' => 'KE', 'name' => $this->l10n->t('Kenya')],
			['code' => 'KG', 'name' => $this->l10n->t('Kyrgyzstan')],
			['code' => 'KH', 'name' => $this->l10n->t('Cambodia')],
			['code' => 'KI', 'name' => $this->l10n->t('Kiribati')],
			['code' => 'KM', 'name' => $this->l10n->t('Comoros')],
			['code' => 'KN', 'name' => $this->l10n->t('Saint Kitts and Nevis')],
			['code' => 'KP', 'name' => $this->l10n->t('Korea, Democratic People\'s Republic of')],
			['code' => 'KR', 'name' => $this->l10n->t('Korea, Republic of')],
			['code' => 'KW', 'name' => $this->l10n->t('Kuwait')],
			['code' => 'KY', 'name' => $this->l10n->t('Cayman Islands')],
			['code' => 'KZ', 'name' => $this->l10n->t('Kazakhstan')],
			['code' => 'LA', 'name' => $this->l10n->t('Lao People\'s Democratic Republic')],
			['code' => 'LB', 'name' => $this->l10n->t('Lebanon')],
			['code' => 'LC', 'name' => $this->l10n->t('Saint Lucia')],
			['code' => 'LI', 'name' => $this->l10n->t('Liechtenstein')],
			['code' => 'LK', 'name' => $this->l10n->t('Sri Lanka')],
			['code' => 'LR', 'name' => $this->l10n->t('Liberia')],
			['code' => 'LS', 'name' => $this->l10n->t('Lesotho')],
			['code' => 'LT', 'name' => $this->l10n->t('Lithuania')],
			['code' => 'LU', 'name' => $this->l10n->t('Luxembourg')],
			['code' => 'LV', 'name' => $this->l10n->t('Latvia')],
			['code' => 'LY', 'name' => $this->l10n->t('Libya')],
			['code' => 'MA', 'name' => $this->l10n->t('Morocco')],
			['code' => 'MC', 'name' => $this->l10n->t('Monaco')],
			['code' => 'MD', 'name' => $this->l10n->t('Moldova, Republic of')],
			['code' => 'ME', 'name' => $this->l10n->t('Montenegro')],
			['code' => 'MF', 'name' => $this->l10n->t('Saint Martin (French part)')],
			['code' => 'MG', 'name' => $this->l10n->t('Madagascar')],
			['code' => 'MH', 'name' => $this->l10n->t('Marshall Islands')],
			['code' => 'MK', 'name' => $this->l10n->t('Macedonia, the former Yugoslav Republic of')],
			['code' => 'ML', 'name' => $this->l10n->t('Mali')],
			['code' => 'MM', 'name' => $this->l10n->t('Myanmar')],
			['code' => 'MN', 'name' => $this->l10n->t('Mongolia')],
			['code' => 'MO', 'name' => $this->l10n->t('Macao')],
			['code' => 'MP', 'name' => $this->l10n->t('Northern Mariana Islands')],
			['code' => 'MQ', 'name' => $this->l10n->t('Martinique')],
			['code' => 'MR', 'name' => $this->l10n->t('Mauritania')],
			['code' => 'MS', 'name' => $this->l10n->t('Montserrat')],
			['code' => 'MT', 'name' => $this->l10n->t('Malta')],
			['code' => 'MU', 'name' => $this->l10n->t('Mauritius')],
			['code' => 'MV', 'name' => $this->l10n->t('Maldives')],
			['code' => 'MW', 'name' => $this->l10n->t('Malawi')],
			['code' => 'MX', 'name' => $this->l10n->t('Mexico')],
			['code' => 'MY', 'name' => $this->l10n->t('Malaysia')],
			['code' => 'MZ', 'name' => $this->l10n->t('Mozambique')],
			['code' => 'NA', 'name' => $this->l10n->t('Namibia')],
			['code' => 'NC', 'name' => $this->l10n->t('New Caledonia')],
			['code' => 'NE', 'name' => $this->l10n->t('Niger')],
			['code' => 'NF', 'name' => $this->l10n->t('Norfolk Island')],
			['code' => 'NG', 'name' => $this->l10n->t('Nigeria')],
			['code' => 'NI', 'name' => $this->l10n->t('Nicaragua')],
			['code' => 'NL', 'name' => $this->l10n->t('Netherlands')],
			['code' => 'NO', 'name' => $this->l10n->t('Norway')],
			['code' => 'NP', 'name' => $this->l10n->t('Nepal')],
			['code' => 'NR', 'name' => $this->l10n->t('Nauru')],
			['code' => 'NU', 'name' => $this->l10n->t('Niue')],
			['code' => 'NZ', 'name' => $this->l10n->t('New Zealand')],
			['code' => 'OM', 'name' => $this->l10n->t('Oman')],
			['code' => 'PA', 'name' => $this->l10n->t('Panama')],
			['code' => 'PE', 'name' => $this->l10n->t('Peru')],
			['code' => 'PF', 'name' => $this->l10n->t('French Polynesia')],
			['code' => 'PG', 'name' => $this->l10n->t('Papua New Guinea')],
			['code' => 'PH', 'name' => $this->l10n->t('Philippines')],
			['code' => 'PK', 'name' => $this->l10n->t('Pakistan')],
			['code' => 'PL', 'name' => $this->l10n->t('Poland')],
			['code' => 'PM', 'name' => $this->l10n->t('Saint Pierre and Miquelon')],
			['code' => 'PN', 'name' => $this->l10n->t('Pitcairn')],
			['code' => 'PR', 'name' => $this->l10n->t('Puerto Rico')],
			['code' => 'PS', 'name' => $this->l10n->t('Palestine, State of')],
			['code' => 'PT', 'name' => $this->l10n->t('Portugal')],
			['code' => 'PW', 'name' => $this->l10n->t('Palau')],
			['code' => 'PY', 'name' => $this->l10n->t('Paraguay')],
			['code' => 'QA', 'name' => $this->l10n->t('Qatar')],
			['code' => 'RE', 'name' => $this->l10n->t('Réunion')],
			['code' => 'RO', 'name' => $this->l10n->t('Romania')],
			['code' => 'RS', 'name' => $this->l10n->t('Serbia')],
			['code' => 'RU', 'name' => $this->l10n->t('Russian Federation')],
			['code' => 'RW', 'name' => $this->l10n->t('Rwanda')],
			['code' => 'SA', 'name' => $this->l10n->t('Saudi Arabia')],
			['code' => 'SB', 'name' => $this->l10n->t('Solomon Islands')],
			['code' => 'SC', 'name' => $this->l10n->t('Seychelles')],
			['code' => 'SD', 'name' => $this->l10n->t('Sudan')],
			['code' => 'SE', 'name' => $this->l10n->t('Sweden')],
			['code' => 'SG', 'name' => $this->l10n->t('Singapore')],
			['code' => 'SH', 'name' => $this->l10n->t('Saint Helena, Ascension and Tristan da Cunha')],
			['code' => 'SI', 'name' => $this->l10n->t('Slovenia')],
			['code' => 'SJ', 'name' => $this->l10n->t('Svalbard and Jan Mayen')],
			['code' => 'SK', 'name' => $this->l10n->t('Slovakia')],
			['code' => 'SL', 'name' => $this->l10n->t('Sierra Leone')],
			['code' => 'SM', 'name' => $this->l10n->t('San Marino')],
			['code' => 'SN', 'name' => $this->l10n->t('Senegal')],
			['code' => 'SO', 'name' => $this->l10n->t('Somalia')],
			['code' => 'SR', 'name' => $this->l10n->t('Suriname')],
			['code' => 'SS', 'name' => $this->l10n->t('South Sudan')],
			['code' => 'ST', 'name' => $this->l10n->t('Sao Tome and Principe')],
			['code' => 'SV', 'name' => $this->l10n->t('El Salvador')],
			['code' => 'SX', 'name' => $this->l10n->t('Sint Maarten (Dutch part)')],
			['code' => 'SY', 'name' => $this->l10n->t('Syrian Arab Republic')],
			['code' => 'SZ', 'name' => $this->l10n->t('Eswatini')],
			['code' => 'TC', 'name' => $this->l10n->t('Turks and Caicos Islands')],
			['code' => 'TD', 'name' => $this->l10n->t('Chad')],
			['code' => 'TF', 'name' => $this->l10n->t('French Southern Territories')],
			['code' => 'TG', 'name' => $this->l10n->t('Togo')],
			['code' => 'TH', 'name' => $this->l10n->t('Thailand')],
			['code' => 'TJ', 'name' => $this->l10n->t('Tajikistan')],
			['code' => 'TK', 'name' => $this->l10n->t('Tokelau')],
			['code' => 'TL', 'name' => $this->l10n->t('Timor-Leste')],
			['code' => 'TM', 'name' => $this->l10n->t('Turkmenistan')],
			['code' => 'TN', 'name' => $this->l10n->t('Tunisia')],
			['code' => 'TO', 'name' => $this->l10n->t('Tonga')],
			['code' => 'TR', 'name' => $this->l10n->t('Turkey')],
			['code' => 'TT', 'name' => $this->l10n->t('Trinidad and Tobago')],
			['code' => 'TV', 'name' => $this->l10n->t('Tuvalu')],
			['code' => 'TW', 'name' => $this->l10n->t('Taiwan, Province of China')],
			['code' => 'TZ', 'name' => $this->l10n->t('Tanzania, United Republic of')],
			['code' => 'UA', 'name' => $this->l10n->t('Ukraine')],
			['code' => 'UG', 'name' => $this->l10n->t('Uganda')],
			['code' => 'UM', 'name' => $this->l10n->t('United States Minor Outlying Islands')],
			['code' => 'US', 'name' => $this->l10n->t('United States of America')],
			['code' => 'UY', 'name' => $this->l10n->t('Uruguay')],
			['code' => 'UZ', 'name' => $this->l10n->t('Uzbekistan')],
			['code' => 'VA', 'name' => $this->l10n->t('Holy See')],
			['code' => 'VC', 'name' => $this->l10n->t('Saint Vincent and the Grenadines')],
			['code' => 'VE', 'name' => $this->l10n->t('Venezuela, Bolivarian Republic of')],
			['code' => 'VG', 'name' => $this->l10n->t('Virgin Islands, British')],
			['code' => 'VI', 'name' => $this->l10n->t('Virgin Islands, U.S.')],
			['code' => 'VN', 'name' => $this->l10n->t('Viet Nam')],
			['code' => 'VU', 'name' => $this->l10n->t('Vanuatu')],
			['code' => 'WF', 'name' => $this->l10n->t('Wallis and Futuna')],
			['code' => 'WS', 'name' => $this->l10n->t('Samoa')],
			['code' => 'YE', 'name' => $this->l10n->t('Yemen')],
			['code' => 'YT', 'name' => $this->l10n->t('Mayotte')],
			['code' => 'ZA', 'name' => $this->l10n->t('South Africa')],
			['code' => 'ZM', 'name' => $this->l10n->t('Zambia')],
			['code' => 'ZW', 'name' => $this->l10n->t('Zimbabwe')],
		];

		$userLanguage = $this->serverConfig->getUserValue($this->currentUser->getUID(), 'core', 'lang', 'en');
		if ($userLanguage === 'de_DE') { // hardcode de_DE to de because this is a quirk in Nextcloud itself
			$userLanguage = 'de';
		}
		$userLocale = $this->serverConfig->getUserValue($this->currentUser->getUID(), 'core', 'locale', 'en_US');
		$guessCountry = 'US';
		if (strpos($userLocale, '_') !== false) {
			$guessCountry = substr($userLocale, strrpos($userLocale, '_') + 1);
			$correctGuess = false;
			foreach ($countries as $country) {
				if ($country['code'] === $guessCountry) {
					$correctGuess = true;
					break;
				}
			}
			if (!$correctGuess) {
				$guessCountry = 'US';
			}
		}

		$this->initialState->provideInitialState('hosted_signaling_server_prefill', [
			'url' => $this->serverConfig->getSystemValueString('overwrite.cli.url'),
			'fullName' => $this->currentUser->getDisplayName(),
			'email' => $this->currentUser->getEMailAddress() ?: '',
			'language' => $userLanguage,
			'country' => $guessCountry,
		]);
		$this->initialState->provideInitialState('hosted_signaling_server_trial_data',
			json_decode($this->serverConfig->getAppValue('spreed', 'hosted-signaling-server-account', "[]"), true) ?? []
		);
		$languages = $this->l10nFactory->getLanguages();
		foreach ($languages['commonlanguages'] as $key => $value) {
			// remove "Deutsch (Formal)"
			if ($value['code'] === 'de_DE') {
				unset($languages['commonlanguages'][$key]);
			}
			// rename "Deutsch (Persönlich)" to "Deutsch"
			if ($value['code'] === 'de') {
				$languages['commonlanguages'][$key]['name'] = 'Deutsch';
			}
		}
		$languages['commonlanguages'] = array_values($languages['commonlanguages']);
		// TODO maybe filter out languages with an _
		usort($countries, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		$this->initialState->provideInitialState('hosted_signaling_server_language_data', [
			'languages' => $languages,
			'countries' => $countries,
		]);
	}

	protected function initSIPBridge(): void {
		$groups = $this->getGroupDetailsArray($this->talkConfig->getSIPGroups(), 'sip_bridge_groups');

		$this->initialState->provideInitialState('sip_bridge_groups', $groups);
		$this->initialState->provideInitialState('sip_bridge_shared_secret', $this->talkConfig->getSIPSharedSecret());
		$this->initialState->provideInitialState('sip_bridge_dialin_info', $this->talkConfig->getDialInInfo());
	}

	protected function getGroupDetailsArray(array $gids, string $configKey): array {
		$groups = [];
		foreach ($gids as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group instanceof IGroup) {
				$groups[] = [
					'id' => $group->getGID(),
					'displayname' => $group->getDisplayName(),
				];
			}
		}

		if (count($gids) !== count($groups)) {
			$gids = array_map(static function (array $group) {
				return $group['id'];
			}, $groups);
			$this->serverConfig->setAppValue('spreed', $configKey, json_encode($gids));
		}

		return $groups;
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'talk';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 *
	 * E.g.: 70
	 */
	public function getPriority(): int {
		return 0;
	}
}
