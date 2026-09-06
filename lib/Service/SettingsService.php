<?php

/**
 * Service for handling settings-related operations in the Stackiq.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Service for handling settings-related operations in the Stackiq.
 *
 * Provides functionality for retrieving, saving, and loading settings,
 * as well as managing configuration for different object types.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: 1.0.0
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.StaticAccess)             — Transport::fromDsn is Symfony Mailer's static factory pattern
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class SettingsService {

	/**
	 * The application name for identification and configuration purposes
	 *
	 * @var string The name of the app
	 */
	private string $appName;

	/**
	 * Cache for schema IDs by object type to avoid repeated database queries
	 *
	 * @var array<string, int|null>
	 */
	private array $schemaIdCache = [];

	/**
	 * Cache for register IDs by object type to avoid repeated database queries
	 *
	 * @var array<string, int|null>
	 */
	private array $registerIdCache = [];

	/**
	 * The unique identifier for the OpenRegister application
	 *
	 * @var string The ID of the OpenRegister app
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The minimum version of the OpenRegister application required
	 *
	 * @var string The minimum required version of OpenRegister
	 */
	private const MIN_OPENREGISTER_VERSION = '0.1.7';

	/**
	 * Object type => the app-config key its schema id is actually stored under.
	 *
	 * The key was always derived from the schema SLUG. Eight slugs are now
	 * English while the stored keys are deliberately still Dutch — renaming that
	 * family is a migration across ~40 sites in three shapes, and doing a SUBSET
	 * resolves some types while leaving others silently "not configured", which
	 * is how the ratings feature died once already.
	 *
	 * So slug and key diverge, and every place that DERIVES the key from the
	 * object type (`$objectType . '_schema'`) has to come through here. Missing
	 * one is not an error: the lookup finds nothing and the caller reads it as
	 * "not configured". `getRegisterIdForObjectType()` did exactly that.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_SCHEMA_KEY = [
		'software-review' => 'beoordeeling_schema',
		// Not a Dutch-to-English legacy key like the rest, but the same
		// mechanism: `contract` collided with shillinq's and pipelinq's, all
		// three carrying `contractNumber`, so shillinq owns the contract and
		// this became the catalogue facet. The config KEY stays
		// `contract_schema` because it is live persisted state, and without
		// this entry the default `<type>_schema` rule would look for
		// `catalogContract_schema` and resolve nothing.
		'catalogContract' => 'contract_schema',
		'bioMeasure' => 'bioMaatregel_schema',
		'connection' => 'koppeling_schema',
		'contactPerson' => 'contactpersoon_schema',
		'moduleVersion' => 'moduleVersie_schema',
		'organization' => 'organisatie_schema',
		'catalogService' => 'dienst_schema',
		'usage' => 'gebruik_schema',
		'vulnerability' => 'kwetsbaarheid_schema',
	];

	/**
	 * OpenRegister's shared organisation projection.
	 *
	 * `nc-` prefixed by OpenRegister so it cannot collide with a leaf app's own
	 * `organization`, which has to keep working until every app has migrated.
	 */
	private const SHARED_ORGANISATION_SCHEMA = 'nc-organisation';

	/**
	 * SettingsService constructor
	 *
	 * @param IAppConfig $config App configuration interface
	 * @param IRequest $request Request interface
	 * @param ContainerInterface $container Container for dependency injection
	 * @param IAppManager $appManager App manager interface
	 * @param LoggerInterface $logger Logger interface
	 * @param IGroupManager $groupManager Group manager interface
	 * @param IL10N $l10n Localization service, used for the
	 *                    user-facing register-verification warning text
	 *                    surfaced via getConfigurationStatus().
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
	) {
		$this->appName = 'stackiq';
	}//end __construct()

	/**
	 * Checks if OpenRegister is installed and meets version requirements
	 *
	 * @param string|null $minVersion Minimum required version
	 *
	 * @return bool True if OpenRegister is installed and meets version requirements
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function isOpenRegisterInstalled(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool {
		if ($this->appManager->isInstalled(appId: self::OPENREGISTER_APP_ID) === false) {
			return false;
		}

		if ($minVersion === null) {
			return true;
		}

		$currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
		return version_compare($currentVersion, $minVersion, '>=');
	}//end isOpenRegisterInstalled()

	/**
	 * Checks if OpenRegister is enabled
	 *
	 * @return bool True if OpenRegister is enabled
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function isOpenRegisterEnabled(): bool {
		return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID);
	}//end isOpenRegisterEnabled()

	/**
	 * Attempts to retrieve the OpenRegister service from the container
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The OpenRegister service if available
	 *
	 * @throws \RuntimeException If the service is not available
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new \RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Get the OpenRegister RegisterService.
	 *
	 * @return \OCA\OpenRegister\Service\RegisterService|null The RegisterService instance or null if not available.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getRegisterService(): ?\OCA\OpenRegister\Service\RegisterService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\RegisterService');
		}

		throw new \RuntimeException('OpenRegister RegisterService is not available.');
	}//end getRegisterService()

	/**
	 * Attempts to retrieve the Configuration service from the container
	 *
	 * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available
	 *
	 * @throws \RuntimeException If the service is not available
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		}

		throw new \RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()

	/**
	 * Attempts to retrieve the RegisterResolverService from the container.
	 *
	 * The resolver centralises `<context>_register` / `<context>_schema` / `<context>_property`
	 * config-key reads so per-install admin overrides (and request-scoped caching + tenant
	 * awareness) behave identically across consumer apps. Returns `null` when OpenRegister
	 * is not installed, or when the OR version pre-dates the resolver class (graceful
	 * fallback — callers must consult their existing `getValueString` path in that case).
	 *
	 * @return \OCA\OpenRegister\Service\RegisterResolverService|null The resolver or null.
	 *
	 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
	 */
	public function getRegisterResolverService(): ?\OCA\OpenRegister\Service\RegisterResolverService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		if (class_exists('\OCA\OpenRegister\Service\RegisterResolverService') === false) {
			// OR is installed but pre-dates the resolver (shipped on OR development 2026-06-12,
			// commit 50a6a0afc). Caller falls back to the legacy IAppConfig path.
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\RegisterResolverService');
		} catch (\Throwable $e) {
			// Container resolution may fail in test contexts where OR services are not wired.
			return null;
		}
	}//end getRegisterResolverService()

	/**
	 * Retrieve the current settings
	 *
	 * @return array The current settings configuration
	 *
	 * @throws \RuntimeException If settings retrieval fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getSettings(): array {
		// Initialize the data array.
		$data = [];

		// Define the register-specific configuration.
		$data['registerTypes'] = [
			'amef' => [
				'name' => 'AMEF',
				'description' => 'AMEF register for architectural elements',
				'objectTypes' => ['organization', 'element', 'relation', 'view', 'model', 'property', 'property-definition'],
				// Complete AMEF object types.
			],
			'voorzieningen' => [
				'name' => 'Voorzieningen',
				'description' => 'Voorzieningen register for software catalog services',
				// All voorzieningen schemas.
				'objectTypes' => [
					'sector',
					'suite',
					'catalogService',
					'vulnerability',
					'contactPerson',
					'organization',
					'usage',
					'catalogContract',
					'connection',
					'software-review',
					'module',
					'compliancy',
					'moduleVersion',
					'sbomComponent',
				],
			],
		];

		// Deprecated: For backward compatibility only - use registerTypes instead.
		$data['objectTypes'] = [
			'organization',
			'contact',
		];

		$data['openRegisters'] = false;
		$data['availableRegisters'] = [];

		// Check if the OpenRegister service is available.
		try {
			$openRegisters = $this->getObjectService();
			if ($openRegisters !== null) {
				$data['openRegisters'] = true;

				// Add additional error handling for OpenRegister internal errors.
				try {
					$registerService = $this->getRegisterService();
					$rawRegisters = $registerService->findAll();

					// Convert Register entities to arrays first.
					$rawRegisters = array_map(
						function ($register) {
							if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {
								return $register->jsonSerialize();
							}

							return (array)$register;
						},
						$rawRegisters
					);

					// Collect all schema IDs that need to be fetched (batch approach).
					$allSchemaIds = [];
					foreach ($rawRegisters as $register) {
						foreach (($register['schemas'] ?? []) as $schema) {
							if (is_int($schema) === true || is_numeric($schema) === true) {
								$allSchemaIds[] = (int)$schema;
							}
						}
					}

					// Batch fetch all schemas in one query if we have IDs.
					$schemaMap = [];
					if (empty($allSchemaIds) === false) {
						try {
							$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
							$schemas = $schemaMapper->findMultipleOptimized(array_unique($allSchemaIds));
							foreach ($schemas as $schema) {
								$schemaMap[$schema->getId()] = $schema->jsonSerialize();
							}
						} catch (\Exception $e) {
							$this->logger->warning('Failed to batch fetch schemas', ['error' => $e->getMessage()]);
						}
					}

					// Map schema details back to registers.
					$rawRegisters = array_map(
						function ($register) use ($schemaMap) {
							if (isset($register['schemas']) === true && is_array($register['schemas']) === true) {
								$schemaDetails = [];
								foreach ($register['schemas'] as $schema) {
									if (is_array($schema) === true && isset($schema['slug']) === true) {
										// Schema is already a full object.
										$schemaDetails[] = $schema;
									} elseif (is_int($schema) === true || is_numeric($schema) === true) {
										// Schema is an ID - get from pre-fetched map.
										if (isset($schemaMap[(int)$schema]) === true) {
											$schemaDetails[] = $schemaMap[(int)$schema];
										}
									}
								}

								$register['schemas'] = $schemaDetails;
							}

							return $register;
						},
						$rawRegisters
					);

					// Filter schemas to remove properties field for cleaner response.
					$data['availableRegisters'] = array_map(
						function ($register) {
							if (isset($register['schemas']) === true && is_array($register['schemas']) === true) {
								$register['schemas'] = array_map(
									function ($schema) {
										// Keep only essential schema fields, remove properties.
										if (is_array($schema) === true) {
											return array_filter(
												$schema,
												function ($key) {
													return in_array($key, ['properties']) === false;
												},
												ARRAY_FILTER_USE_KEY
											);
										}

										return $schema;
									},
									$register['schemas']
								);
							}

							return $register;
						},
						$rawRegisters
					);
				} catch (\TypeError $e) {
					// Handle OpenRegister internal errors (e.g. RegisterMapper parameter issues).
					$this->logger->warning(
						'OpenRegister internal error - using empty registers list',
						[
							'exception' => $e->getMessage(),
							'file' => $e->getFile(),
							'line' => $e->getLine(),
						]
					);
					$data['availableRegisters'] = [];
				} catch (\Exception $e) {
					// Handle any other OpenRegister errors.
					$this->logger->warning(
						'OpenRegister getRegisters() failed - using empty registers list',
						[
							'exception' => $e->getMessage(),
							'file' => $e->getFile(),
							'line' => $e->getLine(),
						]
					);
					$data['availableRegisters'] = [];
				}//end try
			}//end if
		} catch (\RuntimeException $e) {
			// Service not available, continue with default values.
			$this->logger->info(
				'OpenRegister service not available',
				[
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		// Build defaults array dynamically based on register types and their object types.
		$defaults = [];
		foreach ($data['registerTypes'] as $registerType => $config) {
			foreach ($config['objectTypes'] as $objectType) {
				// Always use openregister as source.
				$defaults["{$registerType}_{$objectType}_source"] = 'openregister';
				$defaults["{$registerType}_{$objectType}_schema"] = '';
				$defaults["{$registerType}_{$objectType}_register"] = '';
			}
		}

		// Also maintain backward compatibility for the old structure.
		foreach ($data['objectTypes'] as $type) {
			$defaults["{$type}_source"] = 'openregister';
			$defaults["{$type}_schema"] = '';
			$defaults["{$type}_register"] = '';
		}

		// Note: Old individual config keys are no longer used.
		// They are maintained only for backward compatibility during migration.
		// Get the current values from the configuration.
		try {
			foreach ($defaults as $key => $defaultValue) {
				$data['configuration'][$key] = $this->config->getValueString($this->appName, $key, $defaultValue);
			}

			// Add catalog location.
			$data['catalogLocation'] = $this->getCatalogLocation();

			return $data;
		} catch (\Exception $e) {
			throw new \RuntimeException('Failed to retrieve settings: ' . $e->getMessage());
		}
	}//end getSettings()

	/**
	 * Update the settings configuration
	 *
	 * @param array $data The settings data to update
	 *
	 * @return array The updated settings configuration
	 *
	 * @throws \RuntimeException If settings update fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function updateSettings(array $data): array {
		try {
			// Update each setting in the configuration.
			foreach ($data as $key => $value) {
				// Skip empty keys.
				if (empty($key) === true) {
					$this->logger->warning(
						'Skipping empty key in updateSettings',
						[
							'value' => $value,
						]
					);
					continue;
				}

				// Handle arrays and objects by converting to JSON.
				if (is_array($value) === true || is_object($value) === true) {
					$stringValue = json_encode($value);
				} else {
					// Ensure value is converted to string as required by setValueString.
					$stringValue = (string)$value;
					if (is_string($value) === true) {
						$stringValue = $value;
					}
				}

				$this->config->setValueString($this->appName, $key, $stringValue);
				// Retrieve the updated value to confirm the change.
				$data[$key] = $this->config->getValueString($this->appName, $key);
			}//end foreach

			$this->logger->info(
				'Settings updated successfully',
				[
					'updatedKeys' => array_keys($data),
				]
			);

			return $data;
		} catch (\Exception $e) {
			throw new \RuntimeException('Failed to update settings: ' . $e->getMessage());
		}//end try
	}//end updateSettings()

	/**
	 * Attempts to auto-configure registers and schemas
	 *
	 * @return array The updated configuration
	 *
	 * @throws \RuntimeException If auto-configuration fails
	 */

	/**
	 * Auto-configure settings based on available registers and schemas
	 * This method now uses the consolidated auto-configuration logic
	 *
	 * @param bool $force Whether to force reload regardless of version.
	 *
	 * @return array The auto-configuration results.
	 *
	 * @throws \RuntimeException If auto-configuration fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is a simple re-import toggle
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function autoConfigure(bool $force = false): array {
		return $this->performConsolidatedAutoConfiguration(force: $force);
	}//end autoConfigure()

	/**
	 * Auto-configures settings specifically after importing the softwarecatalogus_register_magic.json
	 *
	 * This method looks for the voorzieningen register and automatically configures
	 * ALL schemas using the new consolidated configuration format, and creates required user groups.
	 *
	 * @return array The updated configuration
	 *
	 * @throws \RuntimeException If auto-configuration fails
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function autoConfigureAfterImport(): array {
		try {
			// Check if auto-configuration has already been completed.
			$autoConfigCompleted = $this->config->getValueString(
				$this->appName,
				'auto_config_completed',
				'false'
			) === 'true';
			if ($autoConfigCompleted === true) {
				$this->logger->info('Auto-configuration already completed, skipping');
				return [];
			}

			$this->logger->info('Starting comprehensive auto-configuration after import');

			// Step 1: Create required user groups.
			$this->logger->info('Creating required user groups');
			$this->createRequiredUserGroups();
			$this->logger->info('User groups created successfully');

			// Step 2: Configure Voorzieningen using the consolidated method.
			$this->logger->info('Running voorzieningen auto-configuration');
			$voorzieningenResult = $this->configureVoorzieningen();

			if ($voorzieningenResult['success'] === false) {
				$this->logger->warning(
					'Voorzieningen auto-configuration failed',
					[
						'message' => $voorzieningenResult['message'] ?? 'Unknown error',
					]
				);
				return [];
			}

			$this->logger->info(
				'Voorzieningen auto-configuration completed successfully',
				[
					'configured' => $voorzieningenResult['configured'] ?? [],
				]
			);

			// Step 3: Configure AMEF using the consolidated method.
			$this->logger->info('Running AMEF auto-configuration');
			$amefResult = $this->configureAmef();

			if ($amefResult['success'] === false) {
				$this->logger->info(
					'AMEF auto-configuration not completed',
					[
						'message' => $amefResult['message'] ?? 'No AMEF register found',
					]
				);
			} else {
				$this->logger->info(
					'AMEF auto-configuration completed successfully',
					[
						'configured' => $amefResult['configured'] ?? [],
					]
				);
			}

			// Step 4: Configure OpenCatalogi app settings for pages/menus/themes.
			$this->logger->info('Running OpenCatalogi auto-configuration');
			$openCatalogiResult = $this->configureOpenCatalogi();

			if ($openCatalogiResult['success'] === true) {
				$this->logger->info(
					'OpenCatalogi auto-configuration completed successfully',
					[
						'configured' => $openCatalogiResult['configured'] ?? [],
					]
				);
			} else {
				$this->logger->info(
					'OpenCatalogi auto-configuration skipped',
					[
						'message' => $openCatalogiResult['message'] ?? 'OpenCatalogi not installed or not needed',
					]
				);
			}

			// Mark auto-configuration as completed.
			$this->config->setValueString($this->appName, 'auto_config_completed', 'true');
			$this->logger->info('Comprehensive auto-configuration marked as completed');

			// Return the consolidated configuration result.
			return [
				'voorzieningen' => $voorzieningenResult,
				'amef' => $amefResult,
				'opencatalogi' => $openCatalogiResult,
				'user_groups_created' => true,
			];
		} catch (\Exception $e) {
			throw new \RuntimeException('Failed to auto-configure after import: ' . $e->getMessage());
		}//end try
	}//end autoConfigureAfterImport()

	/**
	 * Configure OpenCatalogi app settings for pages, menus, and themes
	 *
	 * This method automatically configures the opencatalogi app to use the correct
	 * schema and register IDs for pages, menus, and themes from the publication register.
	 *
	 * @return array Configuration result with success status and configured settings
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function configureOpenCatalogi(): array {
		$result = [
			'success' => false,
			'message' => '',
			'configured' => [],
		];

		try {
			// Check if opencatalogi app is installed.
			if (in_array('opencatalogi', $this->appManager->getInstalledApps()) === false) {
				$result['message'] = 'OpenCatalogi app is not installed';
				return $result;
			}

			// Get OpenRegister services.
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

			// Find the publication register.
			$publicationRegister = null;
			$registers = $registerMapper->findAll();
			foreach ($registers as $register) {
				if ($register->getSlug() === 'publication') {
					$publicationRegister = $register;
					break;
				}
			}

			if ($publicationRegister === null) {
				$result['message'] = 'Publication register not found';
				return $result;
			}

			$registerId = (string)$publicationRegister->getId();

			// Find the theme schema that has data in magic mapper tables.
			//
			// `page` and `menu` were here too, until opencatalogi retired its CMS
			// and those moved to Portaliq. Writing `page_schema` / `menu_schema`
			// into opencatalogi's app config now points it at schemas that no
			// longer exist there, which reads as a configured integration and
			// resolves to nothing.
			$schemas = $schemaMapper->findAll();
			$themeSchemaId = null;

			foreach ($schemas as $schema) {
				$slug = $schema->getSlug();
				$schemaId = $schema->getId();

				// Check if this schema has a magic mapper table with data for register 1.
				$tableName = 'oc_openregister_table_' . $registerId . '_' . $schemaId;

				// Try to find schemas that have actual data.
				if ($slug === 'theme' && $themeSchemaId === null) {
					if ($this->tableHasData(tableName: $tableName) === true) {
						$themeSchemaId = (string)$schemaId;
					}
				}
			}//end foreach

			// Set the opencatalogi app configuration.
			$configured = [];

			if ($themeSchemaId !== null) {
				$this->config->setValueString('opencatalogi', 'theme_schema', $themeSchemaId);
				$this->config->setValueString('opencatalogi', 'theme_register', $registerId);
				$configured['theme_schema'] = $themeSchemaId;
				$configured['theme_register'] = $registerId;
			}

			if (empty($configured) === false) {
				$result['success'] = true;
				$result['configured'] = $configured;
				$result['message'] = 'OpenCatalogi configured successfully';
				$this->logger->info('OpenCatalogi configuration set', $configured);
			} else {
				$result['message'] = 'No page/menu/theme schemas with data found';
			}
		} catch (\Exception $e) {
			$result['message'] = 'Failed to configure OpenCatalogi: ' . $e->getMessage();
			$this->logger->error(
				'OpenCatalogi configuration failed',
				[
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return $result;
	}//end configureOpenCatalogi()

	/**
	 * Check if a database table exists and has data
	 *
	 * @param string $tableName The table name to check
	 *
	 * @return bool True if table exists and has data
	 */
	private function tableHasData(string $tableName): bool {
		try {
			$connection = $this->container->get('OCP\IDBConnection');
			$sql = "SELECT COUNT(*) as cnt FROM {$tableName} WHERE _deleted IS NULL LIMIT 1";
			$stmt = $connection->executeQuery($sql);
			$row = $stmt->fetch();
			return ($row !== false && (int)$row['cnt'] > 0);
		} catch (\Exception $e) {
			// Table doesn't exist or other error.
			return false;
		}
	}//end tableHasData()

	/**
	 * Gets the configured schema ID for a specific object type
	 *
	 * @param string $objectType The object type (organization, contact, gebruiker, contactpersoon)
	 *
	 * @return int|null The schema ID or null if not configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getSchemaIdForObjectType(string $objectType): ?int {
		// Check cache first for performance optimization.
		if (array_key_exists($objectType, $this->schemaIdCache) === true) {
			$cachedValue = $this->schemaIdCache[$objectType];
			$this->logger->debug(
				'SettingsService: Schema ID retrieved from cache',
				[
					'objectType' => $objectType,
					'cachedValue' => $cachedValue,
					'fromCache' => true,
				]
			);
			return $cachedValue;
		}

		$startTime = microtime(true);
		$result = null;

		$voorzieningenConfig = $this->getVoorzieningenConfig();

		$this->logger->debug(
			'SettingsService: Starting schema ID lookup (cache miss)',
			[
				'objectType' => $objectType,
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);

		// First try register-specific configuration.
		// Check for AMEF register specific schemas from JSON config.
		$amefConfig = $this->config->getValueString($this->appName, 'amef_config', '{}');
		if (empty($amefConfig) === false && $amefConfig !== '{}') {
			$decodedAmefConfig = json_decode($amefConfig, true);
			if (is_array($decodedAmefConfig) === true) {
				// Map object types to their corresponding AMEF config keys.
				$amefKeyMap = [
					'model' => 'model_schema',
					'element' => 'element_schema',
					'relationship' => 'relation_schema',
					// Note: relation vs relationship.
					'view' => 'view_schema',
					'property_definition' => 'property_definition_schema',
					// Property definitions are root-level AMEF objects.
					'organization' => 'organization_schema',
					// NOTE: 'property' mapping removed - properties are never
					// root-level AMEF objects, only nested within other elements.
				];

				$amefKey = $amefKeyMap[$objectType] ?? null;

				if (isset($decodedAmefConfig[$amefKey]) === true) {
					$schemaId = $decodedAmefConfig[$amefKey];
					if (empty($schemaId) === false) {
						$result = (int)$schemaId;
						$this->logger->debug(
							'SettingsService: Found schema ID in AMEF JSON config',
							[
								'objectType' => $objectType,
								'amefKey' => $amefKey,
								'schemaId' => $result,
							]
						);
					}
				}
			}//end if
		}//end if

		$voorzieningenKeyMap = [
			'module' => 'module_schema',
			'compliancy' => 'compliancy_schema',
			'moduleVersion' => 'moduleVersie_schema',
			'sbomComponent' => 'sbomComponent_schema',
			// Every catalog object type stored in the voorzieningen register must
			// be mapped here, or getSchemaIdForObjectType() returns null for it
			// even though its `<type>_schema` id is present in the config — which
			// silently killed the ratings feature (`beoordeeling` was unmapped, so
			// ReviewService/ReviewAggregateService read "not configured" forever).
			// The object type on the LEFT is the schema slug and moves with it.
			// The config KEY on the right is a stored app-config key and does NOT:
			// renaming it is a data migration across roughly forty sites in this
			// class alone, including differently-prefixed families
			// (`voorzieningen_contactpersoon_schema`) and compound keys
			// (`koppeling_gebruik_schema`). Renaming a subset would resolve some
			// types and silently leave others reporting "not configured", which is
			// how the ratings feature died once already. Tracked as its own change.
			'software-review' => 'beoordeeling_schema',
			'catalogService' => 'dienst_schema',
			'usage' => 'gebruik_schema',
			'catalogContract' => 'contract_schema',
			'connection' => 'koppeling_schema',
			'suite' => 'suite_schema',
			'vulnerability' => 'kwetsbaarheid_schema',
			'sector' => 'sector_schema',
		];

		// Only check voorzieningen config if object type exists in the key map.
		if ($result === null && isset($voorzieningenKeyMap[$objectType]) === true) {
			$voorzieningenKey = $voorzieningenKeyMap[$objectType];
			if (isset($voorzieningenConfig[$voorzieningenKey]) === true) {
				$result = (int)$voorzieningenConfig[$voorzieningenKey];
			}
		}

		// `organisatie` and `organization` used to be two object types resolving
		// to two schemas. They are one now, so the two branches that handled them
		// separately are one branch — and the ORDER inverted with the merge:
		// `amef_organization_schema` points at the ArchiMate schema, which is the
		// one that was absorbed and is now retired, holding no rows. Preferring
		// it would resolve every organisation lookup to an empty schema, so the
		// merged voorzieningen schema wins and AMEF is the last resort.
		if ($result === null && $objectType === 'organization') {
			$schemaId = ($voorzieningenConfig['organisatie_schema'] ?? '');

			if (empty($schemaId) === true) {
				$schemaId = $this->config->getValueString($this->appName, 'amef_organization_schema', '');
			}

			if (empty($schemaId) === false) {
				$result = (int)$schemaId;
			}
		}

		// LAST, deliberately. The organisation is OpenRegister's now — a schema
		// slug is global per organisation, so this app shipping its own
		// `organization` is what collided with opencatalogi's — and
		// `nc-organisation` is the projection every app shares.
		//
		// But it resolves only when nothing local did. Putting it FIRST was the
		// obvious shape and the wrong one: the projection always exists, so it
		// would win on an instance that has not yet run
		// `openregister:organisations:adopt`, and that instance's rows still
		// live in the local schema. Every picker would come back empty with
		// nothing reporting why.
		//
		// This ordering needs no operator timing. An un-migrated instance keeps
		// resolving locally; a fresh install has no local schema and lands on the
		// projection; and once `prune-retired` has removed the local schema, so
		// does a migrated one.
		if ($result === null && $objectType === 'organization') {
			$result = $this->sharedOrganisationSchemaId();
		}

		if ($objectType === 'contactPerson' && $result === null) {
			$schemaId = $voorzieningenConfig['contactpersoon_schema'];
			if (empty($schemaId) === false) {
				$result = (int)$schemaId;
			}
		}

		// Fall back to generic configuration for backward compatibility.
		// @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
		// Prefer the OR RegisterResolverService when available so per-install admin
		// overrides go through the same `<context>_schema` resolution pipeline used by
		// every other Conduction app (request-scoped caching + tenant-aware). Falls back
		// to the bare IAppConfig read when OR is absent or the resolver pre-dates W21-B.
		if ($result === null) {
			$resolver = $this->getRegisterResolverService();
			$schemaId = '';

			if ($resolver !== null) {
				try {
					$schemaId = $resolver->resolveSchemaId(
						appId: $this->appName,
						configKey: "{$objectType}_schema",
						default: '',
					);
				} catch (\Throwable $e) {
					// MissingConfigException or transient resolver failure — fall through to legacy read.
					$schemaId = '';
				}
			}

			if ($schemaId === '') {
				$schemaId = $this->config->getValueString($this->appName, "{$objectType}_schema", '');
			}

			if (empty($schemaId) === false) {
				$result = (int)$schemaId;
			}
		}//end if

		// Cache the result (even if null) to avoid repeated lookups.
		$this->schemaIdCache[$objectType] = $result;

		$lookupTime = round((microtime(true) - $startTime) * 1000, 2);

		if ($result !== null) {
			$this->logger->info(
				'SettingsService: Found schema ID and cached result',
				[
					'objectType' => $objectType,
					'schemaId' => $result,
					'lookupTime' => $lookupTime . 'ms',
					'fromCache' => false,
				]
			);
		} else {
			$this->logger->debug(
				'SettingsService: No schema ID found, cached null result',
				[
					'objectType' => $objectType,
					'lookupTime' => $lookupTime . 'ms',
					'fromCache' => false,
				]
			);
		}

		return $result;
	}//end getSchemaIdForObjectType()

	/**
	 * The id of OpenRegister's shared organisation projection, or null.
	 *
	 * `nc-organisation` is a read-only virtual schema OpenRegister serves from
	 * its always-available `directory` register, alongside `nc-user` and
	 * `nc-group`. Every app can reference it, which is the point: this app
	 * shipping its own `organization` schema is what collided with
	 * opencatalogi's, since a schema slug is global per organisation.
	 *
	 * Resolved lazily and failing to null, because an OpenRegister too old to
	 * carry the projection must fall through to this app's own schema rather
	 * than resolving organisations to nothing.
	 *
	 * @return int|null The schema id, or null when it cannot be resolved.
	 *
	 * @spec openspec/changes/stackiq-uses-the-shared-organisation/specs/shared-organisation/spec.md#requirement-the-organisation-is-openregisters-req-sso-101
	 */
	private function sharedOrganisationSchemaId(): ?int {
		if ($this->isOpenRegisterInstalled() === false) {
			return null;
		}

		try {
			$schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			$schema = $schemaMapper->find(self::SHARED_ORGANISATION_SCHEMA);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'SettingsService: the shared organisation projection is unavailable',
				['error' => $e->getMessage()]
			);
			return null;
		}

		if ($schema === null) {
			return null;
		}

		return (int)$schema->getId();
	}//end sharedOrganisationSchemaId()

	/**
	 * The app-config key an object type's schema id is stored under.
	 *
	 * Slug and stored key diverged when the slugs were translated, so never
	 * build the key as `$objectType . '_schema'` directly — see LEGACY_SCHEMA_KEY.
	 *
	 * @param string $objectType The object type (schema slug).
	 *
	 * @return string The app-config key.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function voorzieningenSchemaKey(string $objectType): string {
		return (self::LEGACY_SCHEMA_KEY[$objectType] ?? $objectType . '_schema');
	}//end voorzieningenSchemaKey()

	/**
	 * Gets the configured register ID for a specific object type
	 *
	 * @param string $objectType The object type (organization, contact, gebruiker)
	 *
	 * @return int|null The register ID or null if not configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getRegisterIdForObjectType(string $objectType): ?int {
		// Check cache first for performance optimization.
		if (array_key_exists($objectType, $this->registerIdCache) === true) {
			$cachedValue = $this->registerIdCache[$objectType];
			$this->logger->debug(
				'SettingsService: Register ID retrieved from cache',
				[
					'objectType' => $objectType,
					'cachedValue' => $cachedValue,
					'fromCache' => true,
				]
			);
			return $cachedValue;
		}

		$result = null;

		// Check AMEF register for organization.
		if ($objectType === 'organization') {
			$amefConfig = $this->getAmefConfig();
			if (isset($amefConfig['register']) === true && empty($amefConfig['register']) === false) {
				$result = (int)$amefConfig['register'];
			}
		}

		// Check Voorzieningen register for every catalog object type. All catalog
		// schemas (organisatie, contactpersoon, module, gebruik, contract,
		// koppeling, beoordeeling, suite, kwetsbaarheid, sector, …) live in the one
		// voorzieningen register, but this method previously mapped it only for
		// organisatie/contactpersoon — so a caller resolving e.g. `beoordeeling`
		// got null and the feature read as "not configured" (ratings submit,
		// aggregate and moderation were all dead this way). Mirror the schema-side
		// key map: any type with a `<type>_schema` in the voorzieningen config
		// belongs to the voorzieningen register.
		$voorzieningenConfig = $this->getVoorzieningenConfig();
		$isVoorzieningenType = in_array($objectType, ['organization', 'contactPerson', 'contact'], true);
		if ($isVoorzieningenType === false) {
			$isVoorzieningenType = isset($voorzieningenConfig[$this->voorzieningenSchemaKey(objectType: $objectType)]);
		}

		if ($result === null && $isVoorzieningenType === true) {
			if (isset($voorzieningenConfig['register']) === true && empty($voorzieningenConfig['register']) === false) {
				$result = (int)$voorzieningenConfig['register'];
			}
		}

		// @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#phase-2
		// Fallback to legacy per-object-type register config — route through the OR
		// RegisterResolverService when available so per-install admin overrides flow
		// through the same `<context>_register` resolution pipeline used by every
		// other Conduction app (request-scoped caching + tenant-aware). Falls back
		// to the bare IAppConfig read when OR is absent or the resolver pre-dates W21-B.
		if ($result === null) {
			$resolver = $this->getRegisterResolverService();
			$registerId = '';

			if ($resolver !== null) {
				try {
					$registerId = $resolver->resolveRegisterId(
						appId: $this->appName,
						configKey: "{$objectType}_register",
						default: '',
					);
				} catch (\Throwable $e) {
					// MissingConfigException or transient resolver failure — fall through to legacy read.
					$registerId = '';
				}
			}

			if ($registerId === '') {
				$registerId = $this->config->getValueString($this->appName, "{$objectType}_register", '');
			}

			$result = null;
			if (empty($registerId) === false) {
				$result = (int)$registerId;
			}
		}//end if

		// Cache the result (even if null) to avoid repeated lookups.
		$this->registerIdCache[$objectType] = $result;

		$this->logger->debug(
			'SettingsService: Register ID looked up and cached',
			[
				'objectType' => $objectType,
				'result' => $result,
				'fromCache' => false,
			]
		);

		return $result;
	}//end getRegisterIdForObjectType()

	/**
	 * Clear cached schema and register IDs
	 *
	 * This method should be called when configuration changes to ensure
	 * cached values don't become stale.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearConfigurationCache(): void {
		$this->logger->debug(
			'SettingsService: Clearing configuration cache',
			[
				'cached_schema_ids' => count($this->schemaIdCache),
				'cached_register_ids' => count($this->registerIdCache),
			]
		);

		$this->schemaIdCache = [];
		$this->registerIdCache = [];

		$this->logger->info('SettingsService: Configuration cache cleared');
	}//end clearConfigurationCache()

	/**
	 * Gets the configured register ID for the voorzieningen register
	 *
	 * @return int|null The register ID or null if not configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenRegisterId(): ?int {
		$startTime = microtime(true);

		$this->logger->debug(
			'SettingsService: Starting voorzieningen register ID lookup',
			[
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);

		// Try voorzieningen-specific configuration first.
		$this->logger->debug(
			'SettingsService: Checking voorzieningen organisatie register',
			[
				'configKey' => 'voorzieningen_organisatie_register',
			]
		);

		$registerId = $this->config->getValueString($this->appName, 'voorzieningen_organisatie_register', '');

		$this->logger->debug(
			'SettingsService: Voorzieningen organisatie register result',
			[
				'configKey' => 'voorzieningen_organisatie_register',
				'rawValue' => $registerId,
				'isEmpty' => empty($registerId) === true,
			]
		);

		if (empty($registerId) === false) {
			$result = (int)$registerId;
			$this->logger->info(
				'SettingsService: Found voorzieningen organisatie register',
				[
					'registerId' => $result,
					'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			return $result;
		}

		// Also try contactpersoon as fallback.
		$this->logger->debug(
			'SettingsService: Checking voorzieningen contactpersoon register',
			[
				'configKey' => 'voorzieningen_contactpersoon_register',
			]
		);

		$registerId = $this->config->getValueString($this->appName, 'voorzieningen_contactpersoon_register', '');

		$this->logger->debug(
			'SettingsService: Voorzieningen contactpersoon register result',
			[
				'configKey' => 'voorzieningen_contactpersoon_register',
				'rawValue' => $registerId,
				'isEmpty' => empty($registerId) === true,
			]
		);

		if (empty($registerId) === false) {
			$result = (int)$registerId;
			$this->logger->info(
				'SettingsService: Found voorzieningen contactpersoon register',
				[
					'registerId' => $result,
					'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			return $result;
		}

		// Fall back to organization register for backward compatibility.
		$this->logger->debug(
			'SettingsService: Checking organization register for backward compatibility',
			[
				'configKey' => 'organization_register',
			]
		);

		$result = $this->getRegisterIdForObjectType(objectType: 'organization');

		if ($result !== null) {
			$this->logger->info(
				'SettingsService: Found organization register for backward compatibility',
				[
					'registerId' => $result,
					'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
				]
			);
			return $result;
		}

		$this->logger->warning(
			'SettingsService: No register ID found for voorzieningen',
			[
				'checkedConfigurations' => [
					'voorzieningen_organisatie_register' => true,
					'voorzieningen_contactpersoon_register' => true,
					'organization_register' => true,
				],
				'lookupTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
			]
		);

		return null;
	}//end getVoorzieningenRegisterId()

	/**
	 * Checks if all required object types are configured
	 *
	 * @return bool True if all object types have schemas configured
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function isFullyConfigured(): bool {
		// Use contactpersoon instead of contact to match the actual schema naming.
		$objectTypes = ['organization', 'contactPerson'];

		foreach ($objectTypes as $type) {
			$schemaId = $this->getSchemaIdForObjectType(objectType: $type);
			if ($schemaId === null) {
				return false;
			}
		}

		return true;
	}//end isFullyConfigured()

	/**
	 * Gets configuration status for each object type
	 *
	 * @return array Configuration status information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getConfigurationStatus(): array {
		return [
			'organization' => $this->buildObjectTypeStatusEntry(objectType: 'organization'),
			'contact' => $this->buildObjectTypeStatusEntry(objectType: 'contactPerson'),
			'registerVerification' => $this->getRegisterVerificationStatus(),
		];
	}//end getConfigurationStatus()

	/**
	 * Reads back the most recent register-verification result persisted by
	 * persistRegisterVerificationStatus() (register-import-reliability),
	 * so a no-op or partial import is visible in the settings status
	 * payload rather than looking identical to a fully successful one.
	 *
	 * @return array{
	 *     ok: bool,
	 *     checked: bool,
	 *     missingSchemas: array<int, string>,
	 *     unresolvedObjectTypes: array<int, string>,
	 *     message: string|null
	 * }
	 *
	 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-read-and-persist-every-configuration-domain-req-002
	 */
	protected function getRegisterVerificationStatus(): array {
		$unchecked = [
			'ok' => true,
			'checked' => false,
			'missingSchemas' => [],
			'unresolvedObjectTypes' => [],
			'message' => null,
		];

		$raw = $this->config->getValueString($this->appName, 'register_verification_status', '');
		if ($raw === '') {
			return $unchecked;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return $unchecked;
		}

		$ok = ($decoded['ok'] ?? true) === true;

		$missingSchemas = [];
		if (is_array($decoded['missingSchemas'] ?? null) === true) {
			$missingSchemas = $decoded['missingSchemas'];
		}

		$unresolvedObjectTypes = [];
		if (is_array($decoded['unresolvedObjectTypes'] ?? null) === true) {
			$unresolvedObjectTypes = $decoded['unresolvedObjectTypes'];
		}

		$message = null;
		if ($ok === false) {
			$message = $this->l10n->t(
				'The most recent register import did not fully reach OpenRegister — some schemas or '
				. 'object types could not be verified. Re-run the import or check the server log for details.'
			);
		}

		return [
			'ok' => $ok,
			'checked' => true,
			'missingSchemas' => $missingSchemas,
			'unresolvedObjectTypes' => $unresolvedObjectTypes,
			'message' => $message,
		];
	}//end getRegisterVerificationStatus()

	/**
	 * Builds a single object-type status entry (configured/schemaId/registerId).
	 *
	 * Extracted from getConfigurationStatus() (W31 method-decomposition 1.5) —
	 * collapses the duplicated three-line "lookup + array literal" block that
	 * was repeated per object type.
	 *
	 * @param string $objectType Schema object type slug ('organization',
	 *                           'contactPerson', etc.)
	 *
	 * @return array{configured: bool, schemaId: ?int, registerId: ?int}
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-1-5
	 */
	private function buildObjectTypeStatusEntry(string $objectType): array {
		$schemaId = $this->getSchemaIdForObjectType(objectType: $objectType);
		$registerId = $this->getRegisterIdForObjectType(objectType: $objectType);

		return [
			'configured' => (empty($schemaId) === false && empty($registerId) === false),
			'schemaId' => $schemaId,
			'registerId' => $registerId,
		];
	}//end buildObjectTypeStatusEntry()

	/**
	 * Initializes the app with all required components
	 *
	 * @param string|null $minOpenRegisterVersion Minimum required OpenRegister version
	 *
	 * @return array The initialization results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function initialize(?string $minOpenRegisterVersion = self::MIN_OPENREGISTER_VERSION): array {
		$startTime = microtime(true);
		$results = [
			'openRegister' => false,
			'autoConfigured' => false,
			'fullyConfigured' => false,
			'settingsLoaded' => false,
			'configurationImported' => false,
			'autoConfigAfterImport' => false,
			'errors' => [],
			'warnings' => [],
			'timing' => [],
		];

		$this->logger->info(
			'SettingsService: Starting initialization',
			[
				'minOpenRegisterVersion' => $minOpenRegisterVersion,
			]
		);

		try {
			// Check if OpenRegister is installed and enabled.
			$checkStart = microtime(true);

			if ($this->isOpenRegisterInstalled(minVersion: $minOpenRegisterVersion) === false) {
				$error = 'OpenRegister is not installed or does not meet minimum version requirements';
				$results['errors'][] = $error;
				$this->logger->error('SettingsService: ' . $error);
				return $results;
			}

			if ($this->isOpenRegisterEnabled() === false) {
				$error = 'OpenRegister is not enabled';
				$results['errors'][] = $error;
				$this->logger->error('SettingsService: ' . $error);
				return $results;
			}

			$results['openRegister'] = true;
			$results['timing']['openregister_check'] = round((microtime(true) - $checkStart) * 1000, 2) . 'ms';

			$this->logger->info('SettingsService: OpenRegister is available');

			// Load settings from file if needed (do this first).
			$loadStart = microtime(true);
			try {
				if ($this->shouldLoadSettings() === true) {
					$this->logger->info('SettingsService: Loading settings from file');
					$loadResult = $this->loadSettings();
					$results['settingsLoaded'] = true;
					$results['configurationImported'] = empty($loadResult['stackiq_imported']) === false;
					$this->logger->info(
						'SettingsService: Settings loaded successfully',
						[
							'imported' => $results['configurationImported'],
						]
					);
				} else {
					$results['settingsLoaded'] = true;
					// Already up to date.
					$this->logger->info('SettingsService: Settings already up to date');
				}
			} catch (\Exception $e) {
				$error = 'Settings loading failed: ' . $e->getMessage();
				$results['errors'][] = $error;
				$this->logger->error(
					'SettingsService: ' . $error,
					[
						'exception' => $e,
					]
				);
			}//end try

			$results['timing']['settings_load'] = round((microtime(true) - $loadStart) * 1000, 2) . 'ms';

			// Try auto-configuration after import if not already configured.
			$autoConfigStart = microtime(true);
			if ($this->isFullyConfigured() === false) {
				$this->logger->info('SettingsService: App not fully configured, attempting auto-configuration');

				try {
					// First try the post-import auto-configuration (more specific).
					$configuration = $this->autoConfigureAfterImport();
					if (empty($configuration) === false) {
						$this->updateSettings(data: $configuration);
						$results['autoConfigAfterImport'] = true;
						$results['autoConfigured'] = true;
						$this->logger->info(
							'SettingsService: Auto-configuration after import successful',
							[
								'configuration' => array_keys($configuration),
							]
						);
					} else {
						// Fallback to general auto-configuration.
						$this->logger->info(
							'SettingsService: Post-import auto-config yielded no results, trying general auto-config'
						);
						$configuration = $this->autoConfigure();
						if (empty($configuration) === false) {
							$this->updateSettings(data: $configuration);
							$results['autoConfigured'] = true;
							$this->logger->info(
								'SettingsService: General auto-configuration successful',
								[
									'configuration' => array_keys($configuration),
								]
							);
						}
					}//end if
				} catch (\Exception $e) {
					$error = 'Auto-configuration failed: ' . $e->getMessage();
					$results['errors'][] = $error;
					$this->logger->error(
						'SettingsService: ' . $error,
						[
							'exception' => $e,
						]
					);
				}//end try
			} else {
				$this->logger->info('SettingsService: App is already fully configured');
			}//end if

			$results['timing']['auto_config'] = round((microtime(true) - $autoConfigStart) * 1000, 2) . 'ms';

			// Final configuration status check.
			$results['fullyConfigured'] = $this->isFullyConfigured();

			if ($results['fullyConfigured'] === false) {
				$warning = 'App is not fully configured after initialization. Manual configuration may be required.';
				$results['warnings'][] = $warning;
				$this->logger->warning(
					'SettingsService: ' . $warning,
					[
						'configStatus' => $this->getConfigurationStatus(),
					]
				);
			}

			$results['timing']['total'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';

			$this->logger->info(
				'SettingsService: Initialization completed',
				[
					'results' => [
						'openRegister' => $results['openRegister'],
						'autoConfigured' => $results['autoConfigured'],
						'fullyConfigured' => $results['fullyConfigured'],
						'settingsLoaded' => $results['settingsLoaded'],
						'errors' => count($results['errors']),
						'warnings' => count($results['warnings']),
					],
					'timing' => $results['timing'],
				]
			);
		} catch (\Exception $e) {
			$error = 'Initialization failed: ' . $e->getMessage();
			$results['errors'][] = $error;
			$this->logger->error(
				'SettingsService: ' . $error,
				[
					'exception' => $e,
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try

		return $results;
	}//end initialize()

	/**
	 * Load settings from register configuration files
	 *
	 * @param bool $force Whether to force the import regardless of version checks.
	 *
	 * @return array The loaded settings configuration
	 *
	 * @throws \RuntimeException If settings loading fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is a simple re-import toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function loadSettings(bool $force = false): array {
		$results = [];

		try {
			// Load settings from merged softwarecatalogus_register.json (magic mapper enabled for performance).
			$softwareCatalogPath = __DIR__ . '/../Settings/softwarecatalogus_register.json';
			if (file_exists($softwareCatalogPath) === true) {
				$softwareCatalogContent = file_get_contents($softwareCatalogPath);
				$softwareCatalogSettings = json_decode($softwareCatalogContent, true);

				if (json_last_error() === JSON_ERROR_NONE) {
					// ADR-037: merge modular register fragments from Settings/register.d/*.json.
					// Each OpenSpec change drops its own fragment file instead of editing this
					// monolith, so concurrent builds touch disjoint files (no merge conflicts).
					// OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
					// fragments union cleanly by key.
					$fragmentDir = __DIR__ . '/../Settings/register.d';
					$fragmentSig = '';
					if (is_dir($fragmentDir) === true) {
						$fragmentFiles = glob($fragmentDir . '/*.json');
						sort($fragmentFiles);
						foreach ($fragmentFiles as $fragmentFile) {
							$fragmentContent = file_get_contents($fragmentFile);
							if ($fragmentContent === false) {
								continue;
							}

							$fragmentData = json_decode($fragmentContent, true);
							if (json_last_error() !== JSON_ERROR_NONE) {
								$this->logger->warning(
									'SettingsService: skipping malformed register fragment ' . basename($fragmentFile)
									. ': ' . json_last_error_msg()
								);
								continue;
							}

							$softwareCatalogSettings = self::deepMergeConfig(base: $softwareCatalogSettings, overlay: $fragmentData);
							$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
						}
					}//end if

					$results['stackiq'] = $softwareCatalogSettings;

					// Import via configuration service if available with version checking.
					try {
						// Default to the caller-supplied $force in case an exception is
						// thrown below (e.g. from getConfigurationService()) before
						// resolveImportForce() runs — the catch block's error-surfacing
						// check below still needs a defined value.
						$effectiveForce = $force;
						$configurationService = $this->getConfigurationService();

						// Content-derived version signature (register-import-reliability):
						// folds a hash of the monolith's OWN content (+base.<md5-8>) alongside
						// the existing fragment-file hash (+frag.<md5-8>) so ANY register edit —
						// monolith or fragment — produces a version OpenRegister has not seen
						// before and therefore re-imports, instead of relying on a human
						// remembering to bump info.version by hand. See computeConfigVersion()'s
						// own docblock for the full @spec anchor.
						$configVersion = self::computeConfigVersion(
							baseVersion: (string)($softwareCatalogSettings['info']['version'] ?? '0.0.0'),
							monolithContent: $softwareCatalogContent,
							fragmentSig: $fragmentSig
						);

						$appId = \OCA\Stackiq\AppInfo\Application::APP_ID;

						// Force-when-stale workaround (register-import-reliability,
						// https://github.com/ConductionNL/openregister/issues/2075):
						// OpenRegister's importFromApp(force: false) advances the
						// STORED configuration version whenever any
						// registers/schemas/objects come back from the import, but
						// does NOT apply property/authorization changes to schemas
						// that already exist — only newly-created schemas get the
						// full payload. A monolith or fragment edit to an EXISTING
						// schema (e.g. a fragment adding a property + an
						// authorization rule to an already-shipped schema)
						// therefore advances the version, makes the instance LOOK
						// up to date, and leaves the schema stale — strictly worse
						// than the pre-computeConfigVersion() no-op, because the
						// very version this call just wrote now also gates off
						// every later non-forced import. Verified live: a version
						// that legitimately advanced across an `occ upgrade` still
						// left the pre-existing schema unchanged until a
						// force:true import was run.
						//
						// Work around it here, entirely on the consumer side: use
						// this app's own content-derived $configVersion as the
						// authority for "something changed" (resolveImportForce()
						// reads back the version OpenRegister already stored via
						// the same content-derived scheme, so this is a
						// like-for-like comparison — unlike the removed
						// app-semver-vs-content-version comparison documented on
						// shouldLoadSettings()) and force the import whenever it
						// differs, so the change actually applies instead of just
						// being recorded. When the versions match we keep today's
						// cheap no-op path — do not import on every request.
						$effectiveForce = $this->resolveImportForce(
							configurationService: $configurationService,
							appId: $appId,
							configVersion: $configVersion,
							force: $force
						);

						// Log the import attempt for debugging.
						$this->logger->info(
							'SettingsService: Attempting to import softwarecatalogus_register.json',
							[
								'force' => $force,
								'effective_force' => $effectiveForce,
								'app_id' => $appId,
								'config_version' => $configVersion,
								'data_size' => strlen(json_encode($softwareCatalogSettings)),
							]
						);

						// Use importFromApp which handles Configuration entity creation automatically.
						// NOTE (register-import-reliability): this app's own call site is the only
						// place it calls importFromApp() and always passes the same appId, so it
						// cannot itself cause duplicate Configuration rows. If more than one
						// "Software Catalog Register" configuration row is ever observed in
						// oc_openregister_configurations, the cause is upstream: OpenRegister's
						// ConfigurationMapper::findByApp()/findBySourceUrl() organisation-scope
						// their lookup, so a caller whose active-organisation context differs from
						// an existing row's can fail to find it and create a duplicate instead. See
						// https://github.com/ConductionNL/openregister/issues/2072 (filed with the
						// full mechanism) — do not attempt to de-duplicate rows or change lookup
						// behavior from this app; the fix belongs in OpenRegister.
						$importResult = $configurationService->importFromApp(
							appId: $appId,
							data: $softwareCatalogSettings,
							version: $configVersion,
							force: $effectiveForce
						);

						$this->logger->info(
							'SettingsService: Import completed successfully',
							[
								'import_result' => $importResult,
							]
						);

						$results['stackiq_imported'] = true;
						$results['import_result'] = $importResult;

						// Post-import verification (register-import-reliability): a version-gate
						// skip, a partial import, or a duplicate-configuration-row resolution
						// mistake on OpenRegister's side can all make an import look
						// successful here while the live schema set never actually changed.
						// Walk the effective (monolith + fragments) merged register and confirm
						// every schema slug it declares, and every schema id this app resolves
						// for its own object-type lookups, actually resolves in OpenRegister. See
						// verifyRegisterAgainstEffectiveConfig()'s own docblock for the @spec anchor.
						$verification = $this->verifyRegisterAgainstEffectiveConfig(effectiveRegister: $softwareCatalogSettings);
						$results['registerVerification'] = $verification;
						$this->persistRegisterVerificationStatus(verification: $verification);
					} catch (\Exception $e) {
						$results['stackiq_import_error'] = $e->getMessage();
						$this->logger->error(
							'Failed to import stackiq settings: ' . $e->getMessage(),
							[
								'exception' => $e,
								'trace' => $e->getTraceAsString(),
								'force_flag' => $force,
								'effective_force' => $effectiveForce,
								'app_id' => \OCA\Stackiq\AppInfo\Application::APP_ID,
							]
						);

						// In force mode, we want to surface import errors more prominently.
						// Uses $effectiveForce (not just the caller-supplied $force) so a
						// failure while forcing because of a detected version mismatch is
						// surfaced just as loudly as an explicit caller force:true.
						if ($effectiveForce === true) {
							throw new \RuntimeException('Force import failed: ' . $e->getMessage(), 0, $e);
						}
					}//end try
				}//end if
			}//end if

			if (empty($results) === true) {
				throw new \Exception('No register configuration files found');
			}

			return $results;
		} catch (\Exception $e) {
			throw new \RuntimeException('Failed to load settings: ' . $e->getMessage());
		}//end try
	}//end loadSettings()

	/**
	 * Computes the content-derived import version passed to
	 * ConfigurationService::importFromApp()'s version gate.
	 *
	 * Folds an md5 of the monolith register file's OWN raw content into the
	 * signature (`+base.<md5-8>`) alongside the existing ADR-037 fragment
	 * signature (`+frag.<md5-8>`), so ANY register change — whether it
	 * edits the monolith directly or lands as a fragment file — produces a
	 * version string OpenRegister has not seen before and therefore
	 * re-imports. Before this, the signature was derived only from
	 * `info.version` + the fragment hash: a monolith edit that did not also
	 * bump `info.version` by hand produced a byte-identical version and the
	 * import was silently skipped.
	 *
	 * Deliberately content-derived rather than "always re-import" — the
	 * hash is cheap (one extra md5() call on a string already read into
	 * memory) and only changes when the shipped register content actually
	 * changes, so an unchanged register still short-circuits at
	 * OpenRegister's version gate.
	 *
	 * @param string $baseVersion The register JSON's own `info.version` field.
	 * @param string $monolithContent The raw (unparsed) content of the monolith register file.
	 * @param string $fragmentSig The accumulated `filename:md5;` signature of merged
	 *                            ADR-037 fragment files, or an empty string if none exist.
	 *
	 * @return string The content-derived version, e.g. `2.4.0+base.1a2b3c4d+frag.9003c029`.
	 *
	 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
	 */
	private static function computeConfigVersion(string $baseVersion, string $monolithContent, string $fragmentSig): string {
		$configVersion = $baseVersion;
		$configVersion .= '+base.' . substr(md5($monolithContent), 0, 8);

		if ($fragmentSig !== '') {
			$configVersion .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return $configVersion;
	}//end computeConfigVersion()

	/**
	 * Decides whether `importFromApp()` should be forced for this
	 * `loadSettings()` call.
	 *
	 * Workaround for https://github.com/ConductionNL/openregister/issues/2075:
	 * `ConfigurationService::importFromApp(force: false)` advances the
	 * STORED configuration version whenever any registers/schemas/objects
	 * come back from the import, but does NOT apply property or
	 * authorization changes to schemas that already exist — only
	 * newly-created schemas receive the full payload. A register edit that
	 * only touches an EXISTING schema therefore advances the version,
	 * makes the instance LOOK up to date, and leaves the schema itself
	 * stale — worse than a plain no-op, because the version this call just
	 * wrote also gates off every later non-forced import attempt.
	 *
	 * This method treats the content-derived `$configVersion` computed by
	 * `computeConfigVersion()` as the authority for "something changed":
	 * it reads back the version OpenRegister already has stored for this
	 * app via `ConfigurationService::getConfiguredAppVersion()` — the same
	 * content-derived scheme, so this is a like-for-like comparison
	 * (unlike the app-semver-vs-content-version comparison removed from
	 * `shouldLoadSettings()`, see that method's docblock) — and forces the
	 * import whenever the two differ, so the change actually applies
	 * instead of merely being recorded. When they match, the caller's
	 * `$force` is passed through unchanged, preserving the existing cheap
	 * no-op path (this method MUST NOT force an import on every request).
	 *
	 * An explicit caller-supplied `$force=true` always forces, regardless
	 * of the version comparison.
	 *
	 * A stored version of `null` — either nothing has ever been imported
	 * for this app, or `getConfiguredAppVersion()` itself could not
	 * determine one (it swallows its own exceptions and returns `null`,
	 * see its docblock) — is treated as "differs". For a first-ever
	 * import there is nothing existing to skip, so forcing is harmless.
	 * For an undeterminable lookup, this mirrors `importFromApp()`'s own
	 * internal `findByApp()`/organisation-scope lookup (see the
	 * register-import-reliability note above the `importFromApp()` call
	 * in `loadSettings()`): a miss there already causes OpenRegister to
	 * treat the call as a fresh import today, so this does not introduce
	 * a new failure mode.
	 *
	 * @param \OCA\OpenRegister\Service\ConfigurationService $configurationService The resolved OpenRegister configuration service.
	 * @param string $appId The app id to look up the stored version for.
	 * @param string $configVersion The version this call just computed via `computeConfigVersion()`.
	 * @param bool $force The caller-supplied `$force` argument to `loadSettings()`.
	 *
	 * @return bool Whether `importFromApp()` should be called with `force=true`.
	 *
	 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
	 */
	private function resolveImportForce(
		\OCA\OpenRegister\Service\ConfigurationService $configurationService,
		string $appId,
		string $configVersion,
		bool $force,
	): bool {
		if ($force === true) {
			return true;
		}

		try {
			$storedConfigVersion = $configurationService->getConfiguredAppVersion($appId);
		} catch (\Exception $e) {
			// Defensive only — getConfiguredAppVersion() already catches its
			// own exceptions and returns null. Treat as "unknown", same as a
			// null return below.
			$storedConfigVersion = null;
		}

		return $storedConfigVersion !== $configVersion;
	}//end resolveImportForce()

	/**
	 * Verifies the live OpenRegister schema set against the register this
	 * app just (attempted to) import, so a no-op or partial import is
	 * observable instead of looking identical to a full success.
	 *
	 * Walks every schema slug declared in the effective (monolith +
	 * merged fragments) register and confirms it resolves in OpenRegister,
	 * and confirms every schema id this app resolves for its own tracked
	 * object types (per getConfigurationStatus()) is non-null. Any miss is
	 * logged as a WARNING and recorded in the returned summary rather than
	 * failing the request — verification is a diagnostic, not a gate.
	 *
	 * @param array<string, mixed> $effectiveRegister The merged register data
	 *                                                (monolith + fragments) that was just imported.
	 *
	 * @return array{ok: bool, missingSchemas: array<int, string>, unresolvedObjectTypes: array<int, string>}
	 *
	 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
	 */
	private function verifyRegisterAgainstEffectiveConfig(array $effectiveRegister): array {
		$verification = [
			'ok' => true,
			'missingSchemas' => [],
			'unresolvedObjectTypes' => [],
		];

		$schemas = $effectiveRegister['components']['schemas'] ?? [];
		if (is_array($schemas) === false || empty($schemas) === true) {
			return $verification;
		}

		try {
			$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
		} catch (\Throwable $e) {
			// Cannot verify without the mapper — do not fail the import over a diagnostic.
			$this->logger->warning(
				'SettingsService: could not resolve SchemaMapper for register verification, skipping',
				['exception' => $e->getMessage()]
			);
			return $verification;
		}

		foreach (array_keys($schemas) as $slug) {
			if (is_string($slug) === false || $slug === '') {
				continue;
			}

			try {
				$matches = $schemaMapper->findBySlug(slug: $slug, limit: 1);
			} catch (\Throwable $e) {
				$matches = [];
			}

			if (empty($matches) === true) {
				$verification['ok'] = false;
				$verification['missingSchemas'][] = $slug;
				$this->logger->warning(
					'SettingsService: register verification found a schema slug from the shipped '
					. 'register that does not resolve in OpenRegister — the import may not have '
					. 'reached this instance.',
					['schemaSlug' => $slug]
				);
			}
		}//end foreach

		// Also confirm the object types this app's own status reporting tracks
		// (see getConfigurationStatus()) resolve to a schema id post-import.
		foreach (['organization', 'contactPerson'] as $objectType) {
			if ($this->getSchemaIdForObjectType(objectType: $objectType) === null) {
				$verification['ok'] = false;
				$verification['unresolvedObjectTypes'][] = $objectType;
				$this->logger->warning(
					'SettingsService: register verification found an object type this app tracks '
					. 'that does not resolve to a configured schema id after import.',
					['objectType' => $objectType]
				);
			}
		}

		return $verification;
	}//end verifyRegisterAgainstEffectiveConfig()

	/**
	 * Persists the most recent register-verification result to app config
	 * so getConfigurationStatus() can surface it without re-running an
	 * import — verification only runs when loadSettings() actually
	 * attempts an import, while status can be polled independently.
	 *
	 * @param array<string, mixed> $verification The summary from verifyRegisterAgainstEffectiveConfig().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-read-and-persist-every-configuration-domain-req-002
	 */
	private function persistRegisterVerificationStatus(array $verification): void {
		try {
			$this->config->setValueString(
				$this->appName,
				'register_verification_status',
				json_encode($verification, JSON_THROW_ON_ERROR)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'SettingsService: failed to persist register verification status',
				['exception' => $e->getMessage()]
			);
		}
	}//end persistRegisterVerificationStatus()

	/**
	 * Gets the list of generic user groups from configuration
	 *
	 * @return array Array of generic user groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getGenericUserGroups(): array {
		$groupsJson = $this->config->getValueString($this->appName, 'generic_user_groups', '');

		if (empty($groupsJson) === true) {
			// Return only truly generic groups as default (not role-specific).
			// Role-specific groups are now assigned based on organization type.
			return [
				'software-catalog-users'
			];
		}

		$groups = json_decode($groupsJson, true);
		if (is_array($groups) === true) {
			return $groups;
		}

		return [];
	}//end getGenericUserGroups()

	/**
	 * Sets the list of generic user groups in configuration
	 *
	 * @param array $groups Array of generic user groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setGenericUserGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->config->setValueString($this->appName, 'generic_user_groups', $groupsJson);

		$this->logger->info(
			'Updated generic user groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setGenericUserGroups()

	/**
	 * Gets the list of organization admin groups from configuration
	 *
	 * @return array Array of organization admin groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getOrganizationAdminGroups(): array {
		// DISABLED: No automatic group assignment for organization admins.
		// Users should be assigned groups explicitly via the admin UI.
		// Previously this returned ['organisaties-beheerder', 'organisatie-beheerder'] by default.
		return [];
	}//end getOrganizationAdminGroups()

	/**
	 * Sets the list of organization admin groups in configuration
	 *
	 * @param array $groups Array of organization admin groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setOrganizationAdminGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->config->setValueString($this->appName, 'organization_admin_groups', $groupsJson);

		$this->logger->info(
			'Updated organization admin groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setOrganizationAdminGroups()

	/**
	 * Gets the list of super user groups from configuration
	 *
	 * @return array Array of super user groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getSuperUserGroups(): array {
		$groupsJson = $this->config->getValueString($this->appName, 'super_user_groups', '');

		if (empty($groupsJson) === true) {
			// Return default groups if no configuration exists.
			return [
				'admin',
				'software-catalog-admins',
			];
		}

		$groups = json_decode($groupsJson, true);
		if (is_array($groups) === true) {
			return $groups;
		}

		return [];
	}//end getSuperUserGroups()

	/**
	 * Sets the list of super user groups in configuration
	 *
	 * @param array $groups Array of super user groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setSuperUserGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->config->setValueString($this->appName, 'super_user_groups', $groupsJson);

		$this->logger->info(
			'Updated super user groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setSuperUserGroups()

	/**
	 * Validates a list of group names
	 *
	 * @param array $groups Array of group names to validate
	 *
	 * @return array Array with validation results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function validateGroups(array $groups): array {
		$results = [
			'valid' => [],
			'invalid' => [],
			'errors' => [],
		];

		foreach ($groups as $groupName) {
			if (empty($groupName) === true || is_string($groupName) === false) {
				$results['invalid'][] = $groupName;
				$results['errors'][] = 'Group name cannot be empty';
				continue;
			}

			// Check for invalid characters.
			if (preg_match('/[^a-zA-Z0-9._-]/', $groupName) === 1) {
				$results['invalid'][] = $groupName;
				$results['errors'][] = "Group name '{$groupName}' contains invalid characters";
				continue;
			}

			$results['valid'][] = $groupName;
		}

		return $results;
	}//end validateGroups()

	/**
	 * Creates and configures required user groups for the software catalog
	 *
	 * This is the public method that creates user groups and returns status information.
	 *
	 * @return array Results of user group creation and configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function createAndConfigureUserGroups(): array {
		try {
			$this->logger->info('SettingsService: Starting user group creation and configuration');

			$result = [
				'success' => true,
				'message' => 'User groups configured successfully',
				'created' => [],
				'existing' => [],
				'total' => 0,
			];

			// Get the group manager.
			$groupManager = $this->groupManager;

			// Define the required groups (matching role-based system).
			$requiredGroups = [
				// Role-based user groups (exact match with ContactPersoon roles).
				'aanbod-beheerder' => 'Manages software offerings and catalog content',
				'gebruik-beheerder' => 'Manages software usage and procurement',
				'gebruik-raadpleger' => 'Views software usage and procurement data',
				'functioneel-beheerder' => 'Manages functional aspects of the system',
				'vng-raadpleger' => 'Views VNG-related information',
				'organisatie-beheerder' => 'Manages organization data and settings',

				// Plural form for organization contacts.
				'organisaties-beheerder' => 'Organization administrators (plural)',

				// Special groups (available for manual assignment).
				'ambtenaar' => 'Civil servants - available for manual assignment (no automatic assignment)',
				'software-catalog-users' => 'General software catalog users',

				// Super user groups.
				'software-catalog-admins' => 'Software catalog system administrators',
			];

			foreach ($requiredGroups as $groupId => $description) {
				$this->logger->debug("SettingsService: Processing group: {$groupId}");

				// Check if group already exists.
				if ($groupManager->groupExists($groupId) === true) {
					$result['existing'][] = $groupId;
					$this->logger->debug("SettingsService: Group {$groupId} already exists");
					continue;
				}

				// Create the group.
				$group = $groupManager->createGroup($groupId);
				if ($group !== null) {
					$result['created'][] = $groupId;
					$this->logger->info("SettingsService: Created user group: {$groupId}");
				} else {
					$this->logger->warning("SettingsService: Failed to create user group: {$groupId}");
					$result['success'] = false;
				}
			}

			$result['total'] = count($requiredGroups);

			// Update the configuration with only truly generic groups (not role-specific).
			// Role-specific groups are now assigned based on organization type.
			$this->setGenericUserGroups(
				groups: [
					'software-catalog-users',
				]
			);

			// No automatic organization admin groups - can be configured via settings.
			$this->setOrganizationAdminGroups(groups: []);

			$this->setSuperUserGroups(
				groups: [
					// Keep existing admin group.
					'admin',
					'software-catalog-admins',
				]
			);

			$createdCount = count($result['created']);
			$existingCount = count($result['existing']);

			if ($createdCount > 0) {
				$result['message'] = "Created {$createdCount} new groups, {$existingCount} already existed";
			} else {
				$result['message'] = "All {$existingCount} required groups already exist";
			}

			$this->logger->info(
				'SettingsService: User group creation and configuration completed',
				[
					'created_groups' => $result['created'],
					'existing_groups' => $result['existing'],
					'total_required' => $result['total'],
					'success' => $result['success'],
				]
			);

			return $result;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to create and configure user groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to create user groups: ' . $e->getMessage(),
				'created' => [],
				'existing' => [],
				'total' => 0,
				'error' => $e->getMessage(),
			];
		}//end try
	}//end createAndConfigureUserGroups()

	/**
	 * Creates required user groups for the software catalog
	 *
	 * This method creates the default user groups needed for proper operation:
	 * - Generic user groups for general access
	 * - Organization admin groups for managing organizations
	 * - Super user groups for system administration
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If group creation fails
	 */
	private function createRequiredUserGroups(): void {
		try {
			$this->logger->info('Starting creation of required user groups');

			// Get the group manager.
			$groupManager = $this->groupManager;

			// Define the required groups (matching role-based system).
			$requiredGroups = [
				// Role-based user groups (exact match with ContactPersoon roles).
				'aanbod-beheerder' => 'Manages software offerings and catalog content',
				'gebruik-beheerder' => 'Manages software usage and procurement',
				'gebruik-raadpleger' => 'Views software usage and procurement data',
				'functioneel-beheerder' => 'Manages functional aspects of the system',
				'vng-raadpleger' => 'Views VNG-related information',
				'organisatie-beheerder' => 'Manages organization data and settings',

				// Plural form for organization contacts.
				'organisaties-beheerder' => 'Organization administrators (plural)',

				// Special groups (available for manual assignment).
				'ambtenaar' => 'Civil servants - available for manual assignment (no automatic assignment)',
				'software-catalog-users' => 'General software catalog users',

				// Super user groups.
				'software-catalog-admins' => 'Software catalog system administrators',
			];

			$createdGroups = [];
			$existingGroups = [];

			foreach ($requiredGroups as $groupId => $description) {
				$this->logger->debug("Processing group: {$groupId}");

				// Check if group already exists.
				if ($groupManager->groupExists($groupId) === true) {
					$existingGroups[] = $groupId;
					$this->logger->debug("Group {$groupId} already exists, skipping");
					continue;
				}

				// Create the group.
				$group = $groupManager->createGroup($groupId);
				if ($group !== null) {
					$createdGroups[] = $groupId;
					$this->logger->info("Created user group: {$groupId}");
				} else {
					$this->logger->warning("Failed to create user group: {$groupId}");
				}
			}

			// Update the configuration with only truly generic groups (not role-specific).
			// Role-specific groups are now assigned based on organization type, not as generic groups.
			$this->setGenericUserGroups(
				groups: [
					'software-catalog-users',
				]
			);

			// No automatic organization admin groups - can be configured via settings.
			$this->setOrganizationAdminGroups(groups: []);

			$this->setSuperUserGroups(
				groups: [
					// Keep existing admin group.
					'admin',
					'software-catalog-admins',
				]
			);

			$this->logger->info(
				'User group creation completed',
				[
					'created_groups' => $createdGroups,
					'existing_groups' => $existingGroups,
					'total_required' => count($requiredGroups),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to create required user groups: ' . $e->getMessage(),
				[
					'exception' => $e,
				]
			);
			throw new \RuntimeException('Failed to create required user groups: ' . $e->getMessage());
		}//end try
	}//end createRequiredUserGroups()

	/**
	 * Gets all available groups with their information
	 *
	 * @return array Array of group information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllGroups(): array {
		$groups = [];

		// Get group manager if possible.
		if ($this->appManager->isInstalled('user_management') === true) {
			try {
				$groupManager = $this->groupManager;
				$allGroups = $groupManager->search('');

				foreach ($allGroups as $group) {
					$groups[] = [
						'id' => $group->getGID(),
						'displayName' => $group->getDisplayName(),
						'memberCount' => count($group->getUsers()),
						'isGeneric' => in_array($group->getGID(), $this->getGenericUserGroups()) === true,
					];
				}
			} catch (\Exception $e) {
				$this->logger->error('Failed to get all groups: ' . $e->getMessage());
			}
		}

		return $groups;
	}//end getAllGroups()

	/**
	 * The email settings that carry a secret value.
	 *
	 * Single source of truth for three call sites that must agree, and previously
	 * did not: the two GET responses redact these, and updateEmailSettings() skips
	 * a value that comes back as the mask so a save cannot overwrite the real
	 * secret with the placeholder. Adding a provider means adding its secret here
	 * — not to a fourth private list.
	 *
	 * @var array<int, string>
	 */
	public const SECRET_EMAIL_FIELDS = [
		'smtpPassword',
		'sendgridApiKey',
		'mailgunApiKey',
		'postmarkApiKey',
		'sesSecretKey',
		'mailjetSecretKey',
	];

	/**
	 * The placeholder a redacted secret is rendered as.
	 *
	 * @var string
	 */
	public const SECRET_MASK = '••••••••';

	/**
	 * Redact every secret in an email-settings array for an HTTP response.
	 *
	 * `getEmailSettings()` itself must keep returning the REAL values — SymfonyEmailService
	 * reads it to actually send mail — so redaction belongs at the response boundary, not
	 * in the getter. A set secret becomes the mask; an unset one stays empty, so the UI can
	 * still tell "configured" from "not configured" without ever receiving the value.
	 *
	 * @param array<string, mixed> $settings Raw settings from getEmailSettings().
	 *
	 * @return array<string, mixed> The settings, safe to return over HTTP.
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function redactEmailSecrets(array $settings): array {
		foreach (self::SECRET_EMAIL_FIELDS as $field) {
			if (array_key_exists($field, $settings) === false) {
				continue;
			}

			$masked = '';
			if (empty($settings[$field]) === false) {
				$masked = self::SECRET_MASK;
			}

			$settings[$field] = $masked;
		}

		return $settings;
	}//end redactEmailSecrets()

	/**
	 * Gets email configuration settings
	 *
	 * WARNING: returns REAL secret values — it is what SymfonyEmailService uses to send
	 * mail. Never hand the result straight to an HTTP response; run it through
	 * redactEmailSecrets() first.
	 *
	 * @return array Email settings configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailSettings(): array {
		$this->logger->debug('Stackiq: Loading email settings from configuration');

		$app = $this->appName;
		$settings = [
			'enabled' => $this->config->getValueString(
				$app,
				'email_enabled',
				'false'
			) === 'true',
			'senderEmail' => $this->config->getValueString(
				$app,
				'sender_email',
				'noreply@softwarecatalogus.nl'
			),
			'senderName' => $this->config->getValueString(
				$app,
				'sender_name',
				'Software Catalogus'
			),
			'testReceiverOverride' => $this->config->getValueString(
				$app,
				'test_receiver_override',
				''
			),
			'organizationRegistrationEnabled' => $this->config->getValueString(
				$app,
				'email_org_registration_enabled',
				'true'
			) === 'true',
			'organizationActivationEnabled' => $this->config->getValueString(
				$app,
				'email_org_activation_enabled',
				'true'
			) === 'true',
			'userCreationEnabled' => $this->config->getValueString(
				$app,
				'email_user_creation_enabled',
				'true'
			) === 'true',
			'userOrganisationEnabled' => $this->config->getValueString(
				$app,
				'email_user_organisation_enabled',
				'true'
			) === 'true',

			// Symfony Mailer transport configuration.
			'transportType' => $this->config->getValueString(
				$app,
				'email_transport_type',
				'smtp'
			),

			// SMTP configuration.
			'smtpHost' => $this->config->getValueString(
				$app,
				'email_smtp_host',
				'localhost'
			),
			'smtpPort' => (int)$this->config->getValueString(
				$app,
				'email_smtp_port',
				'587'
			),
			'smtpEncryption' => $this->config->getValueString(
				$app,
				'email_smtp_encryption',
				'tls'
			),
			'smtpUsername' => $this->config->getValueString(
				$app,
				'email_smtp_username',
				''
			),
			'smtpPassword' => $this->config->getValueString(
				$app,
				'email_smtp_password',
				''
			),

			// SendGrid configuration.
			'sendgridApiKey' => $this->config->getValueString(
				$app,
				'email_sendgrid_api_key',
				''
			),

			// Mailgun configuration.
			'mailgunApiKey' => $this->config->getValueString(
				$app,
				'email_mailgun_api_key',
				''
			),
			'mailgunDomain' => $this->config->getValueString(
				$app,
				'email_mailgun_domain',
				''
			),

			// Postmark configuration.
			'postmarkApiKey' => $this->config->getValueString(
				$app,
				'email_postmark_api_key',
				''
			),

			// Amazon SES configuration.
			'sesAccessKey' => $this->config->getValueString(
				$app,
				'email_ses_access_key',
				''
			),
			'sesSecretKey' => $this->config->getValueString(
				$app,
				'email_ses_secret_key',
				''
			),
			'sesRegion' => $this->config->getValueString(
				$app,
				'email_ses_region',
				'us-east-1'
			),

			// Mailjet configuration.
			'mailjetApiKey' => $this->config->getValueString(
				$app,
				'email_mailjet_api_key',
				''
			),
			'mailjetSecretKey' => $this->config->getValueString(
				$app,
				'email_mailjet_secret_key',
				''
			),

			// Templates.
			'templates' => [
				'organization_registration' => $this->getEmailTemplate(templateName: 'organization_registration'),
				'organization_activation' => $this->getEmailTemplate(templateName: 'organization_activation'),
				'user_creation' => $this->getEmailTemplate(templateName: 'user_creation'),
			],
		];

		$this->logger->info(
			'Stackiq: Email settings loaded from configuration',
			[
				'enabled' => $settings['enabled'],
				'transport_type' => $settings['transportType'],
				'sender_email' => $settings['senderEmail'],
				'has_mailjet_api_key' => empty($settings['mailjetApiKey']) === false,
				'mailjet_api_key_length' => strlen($settings['mailjetApiKey']),
				'has_mailjet_secret_key' => empty($settings['mailjetSecretKey']) === false,
				'mailjet_secret_key_length' => strlen($settings['mailjetSecretKey']),
				'test_receiver_override' => $settings['testReceiverOverride'],
			]
		);

		return $settings;
	}//end getEmailSettings()

	/**
	 * Updates email configuration settings
	 *
	 * @param array $emailSettings Email settings to update
	 *
	 * @return array Updated email settings
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailSettings(array $emailSettings): array {
		$allowedSettings = [
			'enabled' => 'email_enabled',
			'senderEmail' => 'sender_email',
			'senderName' => 'sender_name',
			'testReceiverOverride' => 'test_receiver_override',
			'organizationRegistrationEnabled' => 'email_org_registration_enabled',
			'organizationActivationEnabled' => 'email_org_activation_enabled',
			'userCreationEnabled' => 'email_user_creation_enabled',
			'userOrganisationEnabled' => 'email_user_organisation_enabled',

			// Symfony Mailer transport configuration.
			'transportType' => 'email_transport_type',

			// SMTP configuration.
			'smtpHost' => 'email_smtp_host',
			'smtpPort' => 'email_smtp_port',
			'smtpEncryption' => 'email_smtp_encryption',
			'smtpUsername' => 'email_smtp_username',
			'smtpPassword' => 'email_smtp_password',

			// SendGrid configuration.
			'sendgridApiKey' => 'email_sendgrid_api_key',

			// Mailgun configuration.
			'mailgunApiKey' => 'email_mailgun_api_key',
			'mailgunDomain' => 'email_mailgun_domain',

			// Postmark configuration.
			'postmarkApiKey' => 'email_postmark_api_key',

			// Amazon SES configuration.
			'sesAccessKey' => 'email_ses_access_key',
			'sesSecretKey' => 'email_ses_secret_key',
			'sesRegion' => 'email_ses_region',

			// Mailjet configuration.
			'mailjetApiKey' => 'email_mailjet_api_key',
			'mailjetSecretKey' => 'email_mailjet_secret_key',
		];
		$updatedSettings = [];

		foreach ($allowedSettings as $settingKey => $configKey) {
			if (array_key_exists($settingKey, $emailSettings) === true) {
				$value = $emailSettings[$settingKey];

				// Skip the masked placeholder — the client is echoing back the redacted value;
				// preserve the real stored secret. Keyed off the same constant the GET responses
				// redact with, so a new provider cannot be masked on read but clobbered on write.
				if (in_array($settingKey, self::SECRET_EMAIL_FIELDS, true) === true && $value === self::SECRET_MASK) {
					continue;
				}

				// Convert boolean values to strings.
				if (is_bool($value) === true) {
					if ($value === true) {
						$value = 'true';
					} else {
						$value = 'false';
					}
				}

				$this->config->setValueString($this->appName, $configKey, (string)$value);
				$updatedSettings[$settingKey] = $this->config->getValueString($this->appName, $configKey);
			}//end if
		}//end foreach

		$this->logger->info(
			'Email settings updated successfully',
			[
				'updatedKeys' => array_keys($updatedSettings),
			]
		);

		return $updatedSettings;
	}//end updateEmailSettings()

	/**
	 * Gets email template content for a specific template
	 *
	 * @param string $templateName The template name (organization_registration, organization_activation, user_creation)
	 *
	 * @return string The template content
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailTemplate(string $templateName): string {
		$configKey = "email_template_{$templateName}";
		$defaultTemplate = $this->getDefaultEmailTemplate(templateName: $templateName);

		return $this->config->getValueString($this->appName, $configKey, $defaultTemplate);
	}//end getEmailTemplate()

	/**
	 * Updates email template content
	 *
	 * @param string $templateName The template name
	 * @param string $templateContent The template content
	 *
	 * @return bool True if update was successful
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailTemplate(string $templateName, string $templateContent): bool {
		try {
			$configKey = "email_template_{$templateName}";
			$this->config->setValueString($this->appName, $configKey, $templateContent);

			$this->logger->info(
				'Email template updated successfully',
				[
					'templateName' => $templateName,
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update email template: ' . $e->getMessage(),
				[
					'templateName' => $templateName,
				]
			);
			return false;
		}//end try
	}//end updateEmailTemplate()

	/**
	 * Gets default email template content
	 *
	 * @param string $templateName The template name
	 *
	 * @return string Default template content
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getDefaultEmailTemplate(string $templateName): string {
		$templates = [
			'organization_registration' => '
<h1>Welkom bij de Software Catalogus!</h1>
<p>Beste {{ organization.name }},</p>
<p>Hartelijk welkom bij de Software Catalogus! Uw organisatie is succesvol geregistreerd.</p>
<p>Met de Software Catalogus kunt u:</p>
<ul>
    <li>Uw software overzichtelijk beheren</li>
    <li>Software delen met andere organisaties</li>
    <li>Ontdekken welke software andere organisaties gebruiken</li>
</ul>
<p>Uw organisatie heeft de status "{{ organization.beoordeling }}" en kan nu gebruik maken van alle functionaliteiten.</p>
<p>Heeft u vragen? Neem dan contact met ons op.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
			'organization_activation' => '
<h1>Uw organisatie is geactiveerd!</h1>
<p>Beste {{ organization.name }},</p>
<p>Goed nieuws! Uw organisatie is zojuist geactiveerd in de Software Catalogus.</p>
<p>Dit betekent dat u nu volledig kunt deelnemen aan:</p>
<ul>
    <li>Het delen van software informatie</li>
    <li>Samenwerking met andere organisaties</li>
    <li>Toegang tot alle catalogus functionaliteiten</li>
</ul>
<p>Status: {{ organization.beoordeling }}</p>
<p>U kunt nu inloggen en gebruik maken van alle beschikbare functionaliteiten.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
			'user_creation' => '
<h1>Welkom {{ user.name }}!</h1>
<p>Beste {{ user.name }},</p>
<p>Er is een gebruikersaccount voor u aangemaakt in de Software Catalogus.</p>
<p>Uw accountgegevens:</p>
<ul>
    <li>E-mailadres: {{ user.email }}</li>
    <li>Gebruikersnaam: {{ user.username }}</li>
    <li>Organisatie: {{ user.organization.name if user.organization }}</li>
</ul>
<p>U kunt nu inloggen op het platform en gebruik maken van alle functionaliteiten.</p>
<p>Heeft u vragen over uw account? Neem dan contact met ons op.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
		];

		return $templates[$templateName] ?? '';
	}//end getDefaultEmailTemplate()

	/**
	 * Gets available email template variables for a specific template
	 *
	 * @param string $templateName The template name
	 *
	 * @return array Available template variables
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailTemplateVariables(string $templateName): array {
		$variables = [
			'organization_registration' => [
				'organization.name' => 'Organization name',
				'organization.beoordeling' => 'Organization status (e.g., Actief)',
				'organization.type' => 'Organization type (e.g., Leverancier)',
				'organization.website' => 'Organization website',
			],
			'organization_activation' => [
				'organization.name' => 'Organization name',
				'organization.beoordeling' => 'Organization status (e.g., Actief)',
				'organization.type' => 'Organization type',
				'organization.website' => 'Organization website',
			],
			'user_creation' => [
				'user.name' => 'User display name',
				'user.email' => 'User email address',
				'user.username' => 'Username',
				'user.organization.name' => 'Organization name (if applicable)',
			],
		];

		return $variables[$templateName] ?? [];
	}//end getEmailTemplateVariables()

	/**
	 * Gets debug information for settings
	 *
	 * @return array Debug information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getDebugInfo(): array {
		$debugInfo = [];

		try {
			// Get current configuration values.
			$debugInfo['configuration'] = [];
			$configKeys = [
				'amef_organization_source',
				'amef_organization_register',
				'amef_organization_schema',
				'voorzieningen_organisatie_source',
				'voorzieningen_organisatie_register',
				'voorzieningen_organisatie_schema',
				'voorzieningen_contactpersoon_source',
				'voorzieningen_contactpersoon_register',
				'voorzieningen_contactpersoon_schema',
				'voorzieningen_register',
				// Sync service expects this key.
				'organization_source',
				'organization_register',
				'organization_schema',
				'contact_source',
				'contact_register',
				'contact_schema',
			];

			foreach ($configKeys as $key) {
				$value = $this->config->getValueString($this->appName, $key, '');
				if (empty($value) === true) {
					$debugInfo['configuration'][$key] = '';
				} else {
					$debugInfo['configuration'][$key] = $value;
				}
			}

			// Get group configurations.
			$debugInfo['userGroups'] = [
				'generic' => $this->getGenericUserGroups(),
				'organizationAdmin' => $this->getOrganizationAdminGroups(),
				'superUser' => $this->getSuperUserGroups(),
			];

			// Get email settings (without sensitive data).
			$emailSettings = $this->getEmailSettings();
			unset($emailSettings['smtpPassword']);
			unset($emailSettings['sendgridApiKey']);
			unset($emailSettings['mailgunApiKey']);
			unset($emailSettings['postmarkApiKey']);
			unset($emailSettings['sesSecretKey']);
			unset($emailSettings['mailjetSecretKey']);
			$debugInfo['emailSettings'] = $emailSettings;

			// Get OpenRegister status.
			$debugInfo['openRegister'] = [
				'installed' => $this->isOpenRegisterInstalled(),
				'enabled' => $this->isOpenRegisterEnabled(),
				'availableRegisters' => [],
			];

			if ($debugInfo['openRegister']['installed'] === true && $debugInfo['openRegister']['enabled'] === true) {
				try {
					$registerService = $this->getRegisterService();
					$debugInfo['openRegister']['availableRegisters'] = $registerService->findAll();
				} catch (\Exception $e) {
					$debugInfo['openRegister']['error'] = $e->getMessage();
				}
			}
		} catch (\Exception $e) {
			$debugInfo['error'] = $e->getMessage();
		}//end try

		return $debugInfo;
	}//end getDebugInfo()

	/**
	 * Sends a test email
	 *
	 * @param string $email The email address to send to
	 * @param array $emailSettings The email settings to use
	 *
	 * @return array Result of the test email
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function sendTestEmail(string $email, array $emailSettings = []): array {
		// Validate email address first (business logic moved from controller).
		if (empty($email) === true) {
			$this->logger->warning('Stackiq: Test email request missing email address');
			return [
				'success' => false,
				'message' => 'Email address is required',
			];
		}

		$this->logger->info(
			'Stackiq: Starting sendTestEmail process',
			[
				'recipient' => $email,
				'has_email_settings' => empty($emailSettings) === false,
			]
		);

		try {
			// Ensure vendor autoloader is loaded.
			include_once __DIR__ . '/../../vendor/autoload.php';
			$this->logger->debug('Stackiq: Vendor autoloader loaded');

			// Use provided settings or fall back to stored settings.
			if (empty($emailSettings) === true) {
				$emailSettings = $this->getEmailSettings();
				$this->logger->info('Stackiq: Loaded email settings from storage');
			} else {
				$this->logger->info('Stackiq: Using provided email settings');
			}

			// Log the email configuration (without sensitive data).
			$this->logger->info(
				'Stackiq: Email configuration',
				[
					'enabled' => $emailSettings['enabled'] ?? false,
					'transport_type' => $emailSettings['transportType'] ?? 'unknown',
					'sender_email' => $emailSettings['senderEmail'] ?? 'not set',
					'sender_name' => $emailSettings['senderName'] ?? 'not set',
					'has_mailjet_api_key' => empty($emailSettings['mailjetApiKey']) === false,
					'has_mailjet_secret_key' => empty($emailSettings['mailjetSecretKey']) === false,
				]
			);

			// Check if email is enabled.
			if (($emailSettings['enabled'] ?? false) === false) {
				$this->logger->warning('Stackiq: Email notifications are disabled');
				return [
					'success' => false,
					'message' => 'Email notifications are disabled',
				];
			}

			// Use test receiver override if configured.
			$recipient = $emailSettings['testReceiverOverride'] ?? $email;
			$this->logger->info(
				'Stackiq: Final recipient determined',
				[
					'original_recipient' => $email,
					'final_recipient' => $recipient,
					'using_override' => empty($emailSettings['testReceiverOverride']) === false,
				]
			);

			// Create transport based on configuration.
			$this->logger->info('Stackiq: Creating email transport');
			$transport = $this->createEmailTransport(emailSettings: $emailSettings);
			$this->logger->info('Stackiq: Email transport created successfully');

			$mailer = new Mailer($transport);
			$this->logger->info('Stackiq: Mailer instance created');

			// Create test email.
			$senderEmail = $emailSettings['senderEmail'] ?? 'noreply@softwarecatalogus.nl';
			$senderName = $emailSettings['senderName'] ?? 'Software Catalogus';
			$transportType = $emailSettings['transportType'] ?? 'smtp';

			$this->logger->info(
				'Stackiq: Creating email message',
				[
					'sender_email' => $senderEmail,
					'sender_name' => $senderName,
					'transport_type' => $transportType,
					'recipient' => $recipient,
				]
			);

			$email = (new Email())
				->from(new Address($senderEmail, $senderName))
				->to($recipient)
				->subject('Software Catalogus - Test Email')
				->html(
					'
                    <h1>Test Email - Software Catalogus</h1>
                    <p>Dit is een test email van de Software Catalogus.</p>
                    <p>Als u deze email ontvangt, werkt het email systeem correct.</p>
                    <p><strong>Transport Type:</strong> ' . htmlspecialchars($transportType) . '</p>
                    <p><strong>Datum:</strong> ' . date('Y-m-d H:i:s') . '</p>
                    <p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
                '
				);

			$this->logger->info('Stackiq: Email message created, attempting to send');

			// Send the email.
			$mailer->send($email);

			$this->logger->info(
				'Stackiq: Email sent successfully via Symfony Mailer',
				[
					'recipient' => $recipient,
					'transport' => $transportType,
					'sender' => $senderEmail,
				]
			);

			return [
				'success' => true,
				'message' => "Test email sent successfully to {$recipient} via {$transportType}",
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: Failed to send test email',
				[
					'recipient' => $email,
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
					'exception_code' => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to send test email: ' . $e->getMessage(),
			];
		}//end try
	}//end sendTestEmail()

	/**
	 * Test email connection without sending an actual email
	 *
	 * @param array $emailSettings The email settings to test
	 *
	 * @return array Result of the connection test
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function testEmailConnection(array $emailSettings = []): array {
		$this->logger->info(
			'Stackiq: Starting email connection test',
			[
				'has_email_settings' => empty($emailSettings) === false,
			]
		);

		try {
			// Ensure vendor autoloader is loaded.
			include_once __DIR__ . '/../../vendor/autoload.php';
			$this->logger->debug('Stackiq: Vendor autoloader loaded');

			// Use provided settings or fall back to stored settings.
			if (empty($emailSettings) === true) {
				$emailSettings = $this->getEmailSettings();
				$this->logger->info('Stackiq: Loaded email settings from storage');
			} else {
				$this->logger->info('Stackiq: Using provided email settings');
			}

			// Log the email configuration (without sensitive data).
			$this->logger->info(
				'Stackiq: Email configuration for connection test',
				[
					'enabled' => $emailSettings['enabled'] ?? false,
					'transport_type' => $emailSettings['transportType'] ?? 'unknown',
					'sender_email' => $emailSettings['senderEmail'] ?? 'not set',
					'sender_name' => $emailSettings['senderName'] ?? 'not set',
					'has_credentials' => $this->hasValidCredentials(emailSettings: $emailSettings),
				]
			);

			// Check if email is enabled.
			if (($emailSettings['enabled'] ?? false) === false) {
				$this->logger->warning('Stackiq: Email notifications are disabled');
				return [
					'success' => false,
					'message' => 'Email notifications are disabled',
				];
			}

			// Validate basic settings.
			$transportType = $emailSettings['transportType'] ?? 'smtp';
			$senderEmail = $emailSettings['senderEmail'] ?? '';

			if (empty($senderEmail) === true) {
				return [
					'success' => false,
					'message' => 'Sender email address is required',
				];
			}

			// Create transport based on configuration (this tests the connection).
			$this->logger->info('Stackiq: Creating email transport for connection test');
			$transport = $this->createEmailTransport(emailSettings: $emailSettings);
			$this->logger->info('Stackiq: Email transport created successfully');

			// Test the connection by creating a mailer instance.
			$mailer = new Mailer($transport);
			$this->logger->info('Stackiq: Mailer instance created for connection test');

			// For some transports, we can test the connection more directly.
			$connectionDetails = $this->getConnectionDetails(emailSettings: $emailSettings);

			$this->logger->info(
				'Stackiq: Email connection test completed successfully',
				[
					'transport' => $transportType,
					'sender' => $senderEmail,
				]
			);

			return [
				'success' => true,
				'message' => "Email connection test successful for {$transportType}",
				'details' => $connectionDetails,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: Email connection test failed',
				[
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
					'exception_code' => $e->getCode(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return [
				'success' => false,
				'message' => 'Email connection test failed: ' . $e->getMessage(),
			];
		}//end try
	}//end testEmailConnection()

	/**
	 * Check if email settings have valid credentials for the transport type
	 *
	 * @param array $emailSettings Email settings.
	 *
	 * @return bool True if credentials are present.
	 */
	private function hasValidCredentials(array $emailSettings): bool {
		$transportType = $emailSettings['transportType'] ?? 'smtp';

		switch ($transportType) {
			case 'smtp':
				return empty($emailSettings['smtpHost']) === false && empty($emailSettings['smtpPort']) === false;
			case 'mailjet':
				return empty($emailSettings['mailjetApiKey']) === false
					&& empty($emailSettings['mailjetSecretKey']) === false;
			case 'sendgrid':
				return empty($emailSettings['sendgridApiKey']) === false;
			case 'mailgun':
				return empty($emailSettings['mailgunApiKey']) === false && empty($emailSettings['mailgunDomain']) === false;
			case 'postmark':
				return empty($emailSettings['postmarkApiKey']) === false;
			case 'ses':
				return empty($emailSettings['sesAccessKey']) === false && empty($emailSettings['sesSecretKey']) === false;
			default:
				return false;
		}
	}//end hasValidCredentials()

	/**
	 * Get connection details for the email transport
	 *
	 * @param array $emailSettings Email settings.
	 *
	 * @return array Connection details.
	 */
	private function getConnectionDetails(array $emailSettings): array {
		$transportType = $emailSettings['transportType'] ?? 'smtp';

		switch ($transportType) {
			case 'smtp':
				if (empty($emailSettings['smtpUsername']) === false) {
					$usernameValue = 'configured';
				} else {
					$usernameValue = 'none';
				}
				return [
					'type' => 'SMTP',
					'host' => $emailSettings['smtpHost'] ?? '',
					'port' => $emailSettings['smtpPort'] ?? '',
					'encryption' => $emailSettings['smtpEncryption'] ?? 'none',
					'username' => $usernameValue,
				];
			case 'mailjet':
				return [
					'type' => 'Mailjet API',
					'has_api_key' => empty($emailSettings['mailjetApiKey']) === false,
					'has_secret_key' => empty($emailSettings['mailjetSecretKey']) === false,
				];
			case 'sendgrid':
				return [
					'type' => 'SendGrid API',
					'has_api_key' => empty($emailSettings['sendgridApiKey']) === false,
				];
			case 'mailgun':
				return [
					'type' => 'Mailgun API',
					'has_api_key' => empty($emailSettings['mailgunApiKey']) === false,
					'domein' => $emailSettings['mailgunDomain'] ?? '',
				];
			case 'postmark':
				return [
					'type' => 'Postmark API',
					'has_api_key' => empty($emailSettings['postmarkApiKey']) === false,
				];
			case 'ses':
				return [
					'type' => 'Amazon SES',
					'has_access_key' => empty($emailSettings['sesAccessKey']) === false,
					'has_secret_key' => empty($emailSettings['sesSecretKey']) === false,
					'region' => $emailSettings['sesRegion'] ?? 'us-east-1',
				];
			default:
				return ['type' => $transportType];
		}//end switch
	}//end getConnectionDetails()

	/**
	 * Creates an email transport based on configuration
	 *
	 * @param array $emailSettings Email settings.
	 *
	 * @return \Symfony\Component\Mailer\Transport\TransportInterface
	 *
	 * @throws \Exception If transport configuration is invalid.
	 */
	private function createEmailTransport(array $emailSettings): \Symfony\Component\Mailer\Transport\TransportInterface {
		$transportType = $emailSettings['transportType'] ?? 'smtp';

		$this->logger->info(
			'Stackiq: Creating transport',
			[
				'transport_type' => $transportType,
			]
		);

		switch ($transportType) {
			case 'mailjet':
				$this->logger->info('Stackiq: Creating Mailjet transport');
				return $this->createMailjetTransport(settings: $emailSettings);
			case 'smtp':
				$this->logger->info('Stackiq: Creating SMTP transport');
				return $this->createSmtpTransport(settings: $emailSettings);
			default:
				$this->logger->error(
					'Stackiq: Unsupported transport type',
					[
						'transport_type' => $transportType,
					]
				);
				throw new \InvalidArgumentException("Unsupported transport type: {$transportType}");
		}
	}//end createEmailTransport()

	/**
	 * Creates a Mailjet transport
	 *
	 * @param array $settings Email settings.
	 *
	 * @return \Symfony\Component\Mailer\Transport\TransportInterface
	 */
	private function createMailjetTransport(array $settings): \Symfony\Component\Mailer\Transport\TransportInterface {
		$apiKey = $settings['mailjetApiKey'] ?? '';
		$secretKey = $settings['mailjetSecretKey'] ?? '';

		$this->logger->info(
			'Stackiq: Mailjet transport configuration',
			[
				'has_api_key' => empty($apiKey) === false,
				'api_key_length' => strlen($apiKey),
				'has_secret_key' => empty($secretKey) === false,
				'secret_key_length' => strlen($secretKey),
			]
		);

		if (empty($apiKey) === true || empty($secretKey) === true) {
			$this->logger->error(
				'Stackiq: Mailjet API key and secret key are required',
				[
					'api_key_empty' => empty($apiKey) === true,
					'secret_key_empty' => empty($secretKey) === true,
				]
			);
			throw new \InvalidArgumentException('Mailjet API key and secret key are required');
		}

		$dsn = sprintf(
			'mailjet+api://%s:%s@default',
			urlencode($apiKey),
			urlencode($secretKey)
		);

		$this->logger->info(
			'Stackiq: Creating Mailjet transport with DSN',
			[
				'dsn_pattern' => 'mailjet+api://***:***@default',
			]
		);

		try {
			$transport = Transport::fromDsn($dsn);
			$this->logger->info(
				'Stackiq: Mailjet transport created successfully',
				[
					'transport_class' => get_class($transport),
				]
			);
			return $transport;
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: Failed to create Mailjet transport',
				[
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
				]
			);
			throw $e;
		}
	}//end createMailjetTransport()

	/**
	 * Creates an SMTP transport
	 *
	 * @param array $settings Email settings.
	 *
	 * @return \Symfony\Component\Mailer\Transport\TransportInterface
	 */
	private function createSmtpTransport(array $settings): \Symfony\Component\Mailer\Transport\TransportInterface {
		$host = $settings['smtpHost'] ?? 'localhost';
		$port = $settings['smtpPort'] ?? 587;
		$encryption = $settings['smtpEncryption'] ?? 'tls';
		$username = $settings['smtpUsername'] ?? '';
		$password = $settings['smtpPassword'] ?? '';

		$this->logger->info(
			'Stackiq: SMTP transport configuration',
			[
				'host' => $host,
				'port' => $port,
				'encryption' => $encryption,
				'has_username' => empty($username) === false,
				'has_password' => empty($password) === false,
			]
		);

		$dsn = sprintf(
			'smtp://%s:%s@%s:%d',
			rawurlencode($username),
			rawurlencode($password),
			$host,
			$port
		);

		if ($encryption !== false && $encryption !== 'none') {
			$dsn .= '?encryption=' . $encryption;
		}

		if (empty($encryption) === false && $encryption !== 'none') {
			$encSuffix = '?encryption=' . $encryption;
		} else {
			$encSuffix = '';
		}

		$dsnPattern = sprintf('smtp://***:***@%s:%d%s', $host, $port, $encSuffix);

		$this->logger->info(
			'Stackiq: Creating SMTP transport with DSN',
			[
				'dsn_pattern' => $dsnPattern,
			]
		);

		try {
			$transport = Transport::fromDsn($dsn);
			$this->logger->info(
				'Stackiq: SMTP transport created successfully',
				[
					'transport_class' => get_class($transport),
				]
			);
			return $transport;
		} catch (\Exception $e) {
			$this->logger->error(
				'Stackiq: Failed to create SMTP transport',
				[
					'exception_class' => get_class($e),
					'exception_message' => $e->getMessage(),
				]
			);
			throw $e;
		}
	}//end createSmtpTransport()

	/**
	 * Whether initialize() should attempt loadSettings().
	 *
	 * ALWAYS true (register-import-reliability). This previously compared
	 * this app's own semantic version (`appManager->getAppVersion()`, e.g.
	 * "0.2.17") against `ConfigurationService::getConfiguredAppVersion()` —
	 * but that value is not an app semver at all: it is exactly the
	 * register-content version string this same service computes and
	 * passes as the `version` argument to `importFromApp()` (e.g.
	 * "2.3.1+frag.9003c029" — see computeConfigVersion()). Those are two
	 * unrelated versioning schemes sharing one stored slot.
	 * `version_compare("0.2.17", "2.3.1+frag.9003c029", ">")` evaluates to
	 * `false` (verified) because the leading numeral of an app semver here
	 * (0) is always less than the leading numeral of a register-content
	 * version (2). Once any import has ever stored such a value, this
	 * comparison could never return true again for any future app
	 * version bump — permanently preventing loadSettings() from being
	 * invoked, regardless of subsequent register changes. This is the
	 * confirmed mechanism behind the live evidence's "versions DID differ
	 * ... yet nothing imported and no import log line appeared": the
	 * "Attempting to import" log line in loadSettings() never fired
	 * because loadSettings() was never entered.
	 *
	 * loadSettings() is only ever reached from an explicit admin-triggered
	 * controller action or the install/upgrade repair step
	 * (InitializeSettings::run(), which has its own
	 * last_initialized_version gate against repeated runs within the same
	 * app version) — never from a per-request code path — so always
	 * attempting it here is cheap (a couple of file reads plus md5()). The
	 * actual, potentially expensive, schema/register write remains gated
	 * by importFromApp()'s own comparison of two like-for-like
	 * content-derived versions.
	 *
	 * @return bool Always true — see above.
	 *
	 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
	 */
	private function shouldLoadSettings(): bool {
		return true;
	}//end shouldLoadSettings()

	/**
	 * Get version information for the app and configuration.
	 *
	 * This method returns version information including the current app version
	 * and the stored configuration version in OpenRegister.
	 *
	 * @return array Version information with app and configuration versions.
	 * @throws \RuntimeException If version retrieval fails.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVersionInfo(): array {
		try {
			// Get the current app version.
			$currentAppVersion = $this->appManager->getAppVersion(\OCA\Stackiq\AppInfo\Application::APP_ID);

			$this->logger->debug(
				'SettingsService: Getting version information',
				[
					'current_app_version' => $currentAppVersion,
				]
			);

			// Get the configuration service to check stored version.
			$configurationService = $this->getConfigurationService();
			$storedConfigVersion = null;

			try {
				$appId = \OCA\Stackiq\AppInfo\Application::APP_ID;
				$storedConfigVersion = $configurationService->getConfiguredAppVersion($appId);
			} catch (\Exception $e) {
				$this->logger->warning(
					'SettingsService: Could not retrieve stored configuration version',
					[
						'exception_message' => $e->getMessage(),
					]
				);
				// Continue with null stored version.
			}

			// Determine if versions match.
			$versionsMatch = $storedConfigVersion !== null
						   && version_compare($currentAppVersion, $storedConfigVersion, '=');

			$needsUpdate = $storedConfigVersion === null
						  || version_compare($currentAppVersion, $storedConfigVersion, '>');

			// Check OpenRegister status.
			$openRegisterInstalled = $this->isOpenRegisterInstalled();
			$openRegisterEnabled = $openRegisterInstalled && $this->isOpenRegisterEnabled();

			if ($storedConfigVersion !== null) {
				$versionComparisonValue = version_compare($currentAppVersion, $storedConfigVersion);
			} else {
				$versionComparisonValue = null;
			}

			$versionInfo = [
				'appName' => 'Stackiq',
				'appVersion' => $currentAppVersion,
				'configuredVersion' => $storedConfigVersion,
				'versionsMatch' => $versionsMatch,
				'needsUpdate' => $needsUpdate,
				'versionComparison' => $versionComparisonValue,
				'isFullyConfigured' => $this->isFullyConfigured(),
				'autoConfigCompleted' => $this->config->getValueString(
					$this->appName,
					'auto_config_completed',
					'false'
				) === 'true',
				'openRegisterInstalled' => $openRegisterInstalled,
				'openRegisterEnabled' => $openRegisterEnabled,
			];

			$this->logger->info('SettingsService: Version information compiled', $versionInfo);

			return $versionInfo;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get version information',
				[
					'exception' => $e,
				]
			);
			throw new \RuntimeException('Failed to get version information: ' . $e->getMessage());
		}//end try
	}//end getVersionInfo()

	/**
	 * Forces a complete configuration update regardless of version checks
	 *
	 * This method forces a complete reconfiguration by resetting all relevant
	 * flags and configurations, then performs import and auto-configuration.
	 *
	 * @return array The force update results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function forceUpdate(): array {
		try {
			$this->logger->info('SettingsService: Starting force update');

			// Reset auto-configuration flag.
			$this->config->setValueString($this->appName, 'auto_config_completed', 'false');

			// Perform forced import.
			$importResult = $this->manualImport(forceImport: true);

			if ($importResult['success'] === false) {
				return [
					'success' => false,
					'message' => 'Force update failed during import: ' . ($importResult['message'] ?? 'Unknown error'),
					'importResult' => $importResult,
				];
			}

			// Verify configuration after force update.
			$finalVersionInfo = $this->getVersionInfo();
			$finalConfigStatus = $this->getConfigurationStatus();

			// For force update, if import succeeded, consider it successful.
			// Version matching is less critical since we forced the update.
			$success = $importResult['success']
				&& ($finalVersionInfo['isFullyConfigured'] !== false
				|| $finalVersionInfo['versionsMatch'] === true);

			$this->logger->info(
				'SettingsService: Force update completed',
				[
					'success' => $success,
					'import_success' => $importResult['success'],
					'final_version_info' => $finalVersionInfo,
					'final_config_status' => $finalConfigStatus,
				]
			);

			// Return concise response to avoid serialization issues with large nested structures.
			if ($success === true) {
				$messageValue = 'Force update completed successfully';
			} else {
				$messageValue = 'Force update completed but configuration needs attention';
			}

			return [
				'success' => $success,
				'message' => $messageValue,
				'importSuccess' => $importResult['success'] ?? false,
				'importMessage' => $importResult['message'] ?? '',
				'finalVersionInfo' => [
					'appVersion' => $finalVersionInfo['appVersion'] ?? null,
					'configuredVersion' => $finalVersionInfo['configuredVersion'] ?? null,
					'versionsMatch' => $finalVersionInfo['versionsMatch'] ?? false,
					'needsUpdate' => $finalVersionInfo['needsUpdate'] ?? false,
					'isFullyConfigured' => $finalVersionInfo['isFullyConfigured'] ?? false,
				],
				'finalConfigStatus' => $finalConfigStatus,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Force update failed',
				[
					'exception_message' => $e->getMessage(),
					'exception' => $e,
				]
			);
			return [
				'success' => false,
				'message' => 'Force update failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}//end try
	}//end forceUpdate()

	/**
	 * Resets the auto-configuration to allow it to run again
	 *
	 * This method clears the auto-configuration completion flag and
	 * optionally resets schema/register configurations for testing.
	 *
	 * @param bool $resetConfiguration Whether to also clear schema/register settings
	 *
	 * @return array The reset results
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $resetConfiguration is a simple scope toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function resetAutoConfiguration(bool $resetConfiguration = false): array {
		try {
			$this->logger->info(
				'Resetting auto-configuration',
				[
					'reset_configuration' => $resetConfiguration,
				]
			);

			// Reset the auto-configuration completion flag.
			$this->config->setValueString($this->appName, 'auto_config_completed', 'false');

			$resetItems = ['auto_config_completed_flag'];

			if ($resetConfiguration === true) {
				// Reset schema and register configurations.
				$configKeysToReset = [
					'voorzieningen_organisatie_source',
					'voorzieningen_organisatie_register',
					'voorzieningen_organisatie_schema',
					'voorzieningen_contactpersoon_source',
					'voorzieningen_contactpersoon_register',
					'voorzieningen_contactpersoon_schema',
					'organization_source',
					'organization_register',
					'organization_schema',
					'contact_source',
					'contact_register',
					'contact_schema',
				];

				foreach ($configKeysToReset as $key) {
					$this->config->setValueString($this->appName, $key, '');
				}

				$resetItems[] = 'schema_register_configurations';
			}//end if

			$this->logger->info(
				'Auto-configuration reset completed',
				[
					'reset_items' => $resetItems,
				]
			);

			return [
				'success' => true,
				'message' => 'Auto-configuration reset successfully',
				'reset_items' => $resetItems,
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to reset auto-configuration: ' . $e->getMessage());
			return [
				'success' => false,
				'message' => 'Failed to reset auto-configuration: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}//end try
	}//end resetAutoConfiguration()

	/**
	 * Manually trigger configuration import from JSON.
	 *
	 * This method allows system administrators to manually trigger the import
	 * process, bypassing version checks.
	 *
	 * @param bool $forceImport Whether to force import regardless of version.
	 *
	 * @return array The import results with success/error information.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forceImport is a simple re-import toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function manualImport(bool $forceImport = false): array {
		try {
			$this->logger->info(
				'SettingsService: Starting manual import',
				[
					'force_import' => $forceImport,
				]
			);

			// Get version info first.
			$versionInfo = $this->getVersionInfo();

			$this->logger->info('SettingsService: Pre-import version info', $versionInfo);

			// Check if import is needed (unless forced).
			if ($forceImport === null
				&& $versionInfo['versionsMatch'] === true
				&& $versionInfo['isFullyConfigured'] === true
			) {
				$this->logger->info('SettingsService: Import not needed - versions match and fully configured');
				return [
					'success' => false,
					'message' => 'Configuration is already up to date. Use force import if you want to reimport.',
					'versionInfo' => $versionInfo,
				];
			}

			// If force import is requested or auto-config not completed, reset auto-configuration flag.
			if ($forceImport === true || $versionInfo['autoConfigCompleted'] === false) {
				$this->config->setValueString($this->appName, 'auto_config_completed', 'false');
				if ($forceImport === true) {
					$reasonValue = 'force_import_requested';
				} else {
					$reasonValue = 'auto_config_not_completed';
				}

				$this->logger->info(
					'SettingsService: Reset auto-configuration flag',
					[
						'reason' => $reasonValue,
					]
				);
			}

			// Perform the import.
			$this->logger->info('SettingsService: Starting settings import');
			$importResult = $this->loadSettings(force: $forceImport);
			$this->logger->info(
				'SettingsService: Settings import completed',
				[
					'import_result' => $importResult,
				]
			);

			// Auto-configure after successful import.
			$autoConfigResult = null;
			try {
				$this->logger->info('SettingsService: Starting auto-configuration after import');
				$autoConfigResult = $this->autoConfigureAfterImport();
				if (empty($autoConfigResult) === false) {
					$this->logger->info('SettingsService: Updating settings with auto-configuration result');
					$this->updateSettings(data: $autoConfigResult);
					$this->logger->info(
						'SettingsService: Auto-configuration completed after import',
						[
							'configuration' => array_keys($autoConfigResult),
						]
					);
				} else {
					$this->logger->info('SettingsService: Auto-configuration yielded no results');
				}
			} catch (\Exception $e) {
				$this->logger->warning(
					'SettingsService: Auto-configuration failed after import',
					[
						'exception_message' => $e->getMessage(),
						'exception' => $e,
					]
				);
				// Don't fail the entire import if auto-configuration fails.
			}//end try

			// Wait a moment for any async operations to complete.
			usleep(100000);
			// 0.1 seconds.
			// Get updated version info - this should now reflect the changes.
			$this->logger->info('SettingsService: Getting updated version info after import');
			$updatedVersionInfo = $this->getVersionInfo();
			$this->logger->info('SettingsService: Post-import version info', $updatedVersionInfo);

			$message = 'Configuration imported successfully';
			if (empty($autoConfigResult) === false) {
				$message .= ' and auto-configured';
			}

			if ($forceImport === true) {
				$message .= ' (forced import)';
			}

			return [
				'success' => true,
				'message' => $message,
				'importResult' => $importResult,
				'autoConfigResult' => $autoConfigResult,
				'versionInfo' => $updatedVersionInfo,
				'configurationStatus' => $this->getConfigurationStatus(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Manual import failed',
				[
					'exception_message' => $e->getMessage(),
					'exception' => $e,
				]
			);
			return [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
				'versionInfo' => $this->getVersionInfo(),
			];
		}//end try
	}//end manualImport()

	/**
	 * Perform consolidated auto-configuration with clean separation of concerns
	 *
	 * This method orchestrates the complete auto-configuration process:
	 * 1. Configuration file loading
	 * 2. Voorzieningen register configuration
	 * 3. AMEF register configuration
	 * 4. User groups configuration
	 *
	 * @param bool $force Whether to force configuration loading.
	 *
	 * @return array Consolidated configuration results
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is a simple re-import toggle
	 * @spec                                        openspec/specs/settings-service/spec.md
	 */
	public function performConsolidatedAutoConfiguration(bool $force = false): array {
		$this->logger->info(
			'SettingsService: Starting consolidated auto-configuration',
			[
				'force' => $force,
			]
		);

		$results = [
			'success' => true,
			'message' => 'Auto-configuration completed successfully',
			'steps' => [],
			'errors' => [],
			'timestamp' => time(),
			'force' => $force,
		];

		// Step 1: Load configuration files.
		$this->logger->info('SettingsService: Step 1 - Loading configuration');
		$configResult = $this->loadConfiguration(force: $force);
		$results['steps']['configurationLoad'] = $configResult;
		$this->addStepResult(results: $results, stepResult: $configResult, stepName: 'Configuration loading');

		// Step 2: Configure Voorzieningen (Dutch register system).
		$this->logger->info('SettingsService: Step 2 - Configuring Voorzieningen');
		$voorzieningenResult = $this->configureVoorzieningen();
		$results['steps']['voorzieningenConfiguration'] = $voorzieningenResult;
		$this->addStepResult(results: $results, stepResult: $voorzieningenResult, stepName: 'Voorzieningen configuration');

		// Step 3: Configure AMEF (ArchiMate/English register system).
		$this->logger->info('SettingsService: Step 3 - Configuring AMEF');
		$amefResult = $this->configureAmef();
		$results['steps']['amefConfiguration'] = $amefResult;
		$this->addStepResult(results: $results, stepResult: $amefResult, stepName: 'AMEF configuration');

		// Step 4: Configure User Groups.
		$this->logger->info('SettingsService: Step 4 - Configuring User Groups');
		$groupsResult = $this->configureGroups();
		$results['steps']['groupsConfiguration'] = $groupsResult;
		$this->addStepResult(results: $results, stepResult: $groupsResult, stepName: 'User groups configuration');

		// Determine overall success.
		$results['success'] = empty($results['errors']) === true;
		if ($results['success'] === false) {
			$results['message'] = 'Auto-configuration completed with some issues';
		}

		$this->logger->info(
			'SettingsService: Consolidated auto-configuration completed',
			[
				'success' => $results['success'],
				'errors_count' => count($results['errors']),
			]
		);

		return $results;
	}//end performConsolidatedAutoConfiguration()

	/**
	 * Load configuration files
	 *
	 * @param bool $force Whether to force reload regardless of version.
	 *
	 * @return array Configuration loading result
	 */
	private function loadConfiguration(bool $force): array {
		try {
			$importResult = $this->manualImport(forceImport: $force);

			return [
				'success' => $importResult['success'],
				'message' => $importResult['message'] ?? 'Configuration loaded',
				'details' => $importResult,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Configuration loading failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}
	}//end loadConfiguration()

	/**
	 * Configure Voorzieningen register and schemas
	 *
	 * @return array Voorzieningen configuration result
	 */
	private function configureVoorzieningen(): array {
		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [
					'success' => false,
					'message' => 'OpenRegister service not available',
				];
			}

			try {
				$registerService = $this->getRegisterService();
				$registers = $registerService->findAll();
			} catch (\TypeError|\Exception $e) {
				$this->logger->warning(
					'OpenRegister RegisterService->findAll() failed in configureVoorzieningen',
					[
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
					]
				);
				return [
					'success' => false,
					'message' => 'Failed to retrieve registers: ' . $e->getMessage(),
				];
			}

			if (empty($registers) === true) {
				return [
					'success' => false,
					'message' => 'No registers available',
				];
			}

			// Get schema mapper to fetch schema details if needed.
			$schemaMapper = null;
			try {
				$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
			} catch (\Exception $e) {
				$this->logger->warning(
					'SchemaMapper not available for Voorzieningen detection',
					['error' => $e->getMessage()]
				);
			}

			// Find the catalog register by slug OR by presence of expected schema slugs.
			// The slug moved `voorzieningen` -> `stackiq`; MigrateRegisterSlug renames
			// the row before this ever runs, and the schema-slug fallback below still
			// finds the register on an install where that rename was refused.
			$targetRegister = null;
			$expectedSlugs = [
				'sector',
				'suite',
				'catalogService',
				'vulnerability',
				'contactPerson',
				'organization',
				'usage',
				'catalogContract',
				'connection',
				'software-review',
				'module',
				'compliancy',
				'moduleversie',
				'moduleVersion',
			];

			foreach ($registers as $register) {
				// Convert Register entity to array if needed.
				if ($register instanceof \OCA\OpenRegister\Db\Register) {
					$register = $register->jsonSerialize();
				}

				$slug = strtolower($register['slug'] ?? '');
				if ($slug === 'stackiq') {
					// Fetch full schema details for the register.
					$schemas = $register['schemas'] ?? [];
					$schemaDetails = [];
					foreach ($schemas as $schema) {
						if (is_array($schema) === true && isset($schema['slug']) === true) {
							// Schema is already a full object.
							$schemaDetails[] = $schema;
						} elseif ((is_int($schema) === true || is_numeric($schema) === true) && $schemaMapper !== null) {
							// Schema is an ID - fetch details using SchemaMapper.
							try {
								$schemaEntity = $schemaMapper->find((int)$schema);
								if ($schemaEntity !== null) {
									$schemaDetails[] = $schemaEntity->jsonSerialize();
								}
							} catch (\Exception $e) {
								$this->logger->warning(
									'Failed to fetch schema details',
									['schemaId' => $schema, 'error' => $e->getMessage()]
								);
							}
						}
					}

					$register['schemas'] = $schemaDetails;
					$targetRegister = $register;
					break;
				}//end if

				// Heuristic: count matching schemas.
				$schemaSlugs = [];
				foreach (($register['schemas'] ?? []) as $schema) {
					if (is_array($schema) === true && isset($schema['slug']) === true) {
						$schemaSlugs[] = strtolower($schema['slug']);
					} elseif ((is_int($schema) === true || is_numeric($schema) === true) && $schemaMapper !== null) {
						try {
							$schemaEntity = $schemaMapper->find((int)$schema);
							if ($schemaEntity !== null) {
								$schemaArray = $schemaEntity->jsonSerialize();
								$schemaSlugs[] = strtolower($schemaArray['slug'] ?? '');
							}
						} catch (\Exception $e) {
							// Skip schemas that can't be fetched.
						}
					}
				}

				$matches = array_intersect($expectedSlugs, $schemaSlugs);
				if (count($matches) >= 6) {
					// Good confidence.
					// Fetch full schema details.
					$schemas = $register['schemas'] ?? [];
					$schemaDetails = [];
					foreach ($schemas as $schema) {
						if (is_array($schema) === true && isset($schema['slug']) === true) {
							$schemaDetails[] = $schema;
						} elseif ((is_int($schema) === true || is_numeric($schema) === true) && $schemaMapper !== null) {
							try {
								$schemaEntity = $schemaMapper->find((int)$schema);
								if ($schemaEntity !== null) {
									$schemaDetails[] = $schemaEntity->jsonSerialize();
								}
							} catch (\Exception $e) {
								// Skip.
							}
						}
					}

					$register['schemas'] = $schemaDetails;
					$targetRegister = $register;
				}//end if
			}//end foreach

			if ($targetRegister === null) {
				return [
					'success' => false,
					'message' => 'Voorzieningen register not found',
				];
			}

			// Map schema slugs to configuration keys based on actual register schemas.
			$slugToKey = [
				'organization' => 'organisatie_schema',
				'contactPerson' => 'contactpersoon_schema',
				'suite' => 'suite_schema',
				'catalogService' => 'dienst_schema',
				'vulnerability' => 'kwetsbaarheid_schema',
				'usage' => 'gebruik_schema',
				'catalogContract' => 'contract_schema',
				'connection' => 'koppeling_schema',
				'software-review' => 'beoordeeling_schema',
				'module' => 'module_schema',
				'compliancy' => 'compliancy_schema',
				'moduleversie' => 'moduleVersie_schema',
				// Handle both moduleversie and moduleVersie.
				'moduleVersion' => 'moduleVersie_schema',
				'sector' => 'sector_schema',
				'sbomComponent' => 'sbomComponent_schema',
			];

			$config = [ 'register' => (string)($targetRegister['id'] ?? '') ];

			$this->logger->info(
				'DEBUG: About to process schemas',
				[
					'register_id' => $targetRegister['id'],
					'schemas_count' => count($targetRegister['schemas'] ?? []),
					'slugToKey_map' => $slugToKey,
				]
			);

			foreach (($targetRegister['schemas'] ?? []) as $schema) {
				$originalSlug = $schema['slug'] ?? '';
				$lowercaseSlug = strtolower($originalSlug);

				$hasMappingOriginalValue = 'NO';
				if (isset($slugToKey[$originalSlug]) === true) {
					$hasMappingOriginalValue = 'YES';
				}

				$hasMappingLowercaseValue = 'NO';
				if (isset($slugToKey[$lowercaseSlug]) === true) {
					$hasMappingLowercaseValue = 'YES';
				}

				$this->logger->info(
					'DEBUG: Processing schema',
					[
						'original_slug' => $originalSlug,
						'lowercase_slug' => $lowercaseSlug,
						'schema_id' => $schema['id'] ?? 'NO_ID',
						'has_mapping_original' => $hasMappingOriginalValue,
						'has_mapping_lowercase' => $hasMappingLowercaseValue,
					]
				);

				$mappingKey = null;
				$usedSlug = null;

				// Try original case first, then lowercase.
				if (isset($slugToKey[$originalSlug]) === true) {
					$mappingKey = $slugToKey[$originalSlug];
					$usedSlug = $originalSlug;
				} elseif (isset($slugToKey[$lowercaseSlug]) === true) {
					$mappingKey = $slugToKey[$lowercaseSlug];
					$usedSlug = $lowercaseSlug;
				}

				if ($mappingKey !== null) {
					$config[$mappingKey] = (string)$schema['id'];
					$this->logger->info(
						'DEBUG: Mapped schema successfully',
						[
							'used_slug' => $usedSlug,
							'config_key' => $mappingKey,
							'schema_id' => $schema['id'],
						]
					);
				} else {
					$this->logger->debug(
						'DEBUG: No mapping found for schema slug',
						[
							'original_slug' => $originalSlug,
							'lowercase_slug' => $lowercaseSlug,
						]
					);
				}
			}//end foreach

			$this->logger->info('DEBUG: Final config before persist', ['config' => $config]);

			// Persist normalized config.
			$this->setVoorzieningenConfig(config: $config);

			return [
				'success' => true,
				'message' => 'Voorzieningen configured successfully',
				'configured' => $config,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Voorzieningen configuration failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}//end try
	}//end configureVoorzieningen()

	/**
	 * Configure AMEF register and schemas
	 *
	 * @return array AMEF configuration result
	 */
	private function configureAmef(): array {
		try {
			// Get available registers.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [
					'success' => false,
					'message' => 'OpenRegister service not available',
				];
			}

			try {
				$registerService = $this->getRegisterService();
				$registers = $registerService->findAll();
			} catch (\TypeError|\Exception $e) {
				$this->logger->warning(
					'OpenRegister RegisterService->findAll() failed in configureAmef',
					[
						'exception' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
					]
				);
				return [
					'success' => false,
					'message' => 'Failed to retrieve registers: ' . $e->getMessage(),
				];
			}

			if (empty($registers) === true) {
				return [
					'success' => false,
					'message' => 'No registers available',
				];
			}

			// Detect AMEF register by presence of core AMEF schemas (not by slug).
			$candidate = null;
			$amefCoreSlugs = ['model', 'element', 'relation', 'view', 'organization', 'property', 'property-definition'];

			// Convert all registers to arrays first.
			$registers = array_map(
				function ($register) {
					if (($register instanceof \OCA\OpenRegister\Db\Register)) {
						return $register->jsonSerialize();
					}

					return (array)$register;
				},
				$registers
			);

			// Collect all schema IDs for batch fetch.
			$allSchemaIds = [];
			foreach ($registers as $register) {
				foreach (($register['schemas'] ?? []) as $schema) {
					if (is_int($schema) === true || is_numeric($schema) === true) {
						$allSchemaIds[] = (int)$schema;
					}
				}
			}

			// Batch fetch all schemas in one query.
			$schemaMap = [];
			if (empty($allSchemaIds) === false) {
				try {
					$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
					$schemas = $schemaMapper->findMultipleOptimized(array_unique($allSchemaIds));
					foreach ($schemas as $schema) {
						$schemaMap[$schema->getId()] = $schema->jsonSerialize();
					}
				} catch (\Exception $e) {
					$this->logger->warning('SchemaMapper not available for AMEF detection', ['error' => $e->getMessage()]);
				}
			}

			foreach ($registers as $register) {
				// Handle schemas - they might be IDs (integers) or full objects.
				$schemas = $register['schemas'] ?? [];
				$schemaSlugs = [];
				$schemaDetails = [];

				foreach ($schemas as $schema) {
					if (is_array($schema) === true && isset($schema['slug']) === true) {
						// Schema is already a full object.
						$schemaSlugs[] = strtolower($schema['slug']);
						$schemaDetails[] = $schema;
					} elseif (is_int($schema) === true || is_numeric($schema) === true) {
						// Schema is an ID - get from pre-fetched map.
						if (isset($schemaMap[(int)$schema]) === true) {
							$schemaArray = $schemaMap[(int)$schema];
							$schemaSlugs[] = strtolower($schemaArray['slug'] ?? '');
							$schemaDetails[] = $schemaArray;
						}
					}
				}

				// Store schema details back for later use.
				$register['schemas'] = $schemaDetails;

				$matches = array_intersect($amefCoreSlugs, $schemaSlugs);
				if (count($matches) >= 3) {
					// Threshold: at least model + 2 others.
					$candidate = $register;
					// Prefer the register with most matches.
					if (isset($bestCount) === false || count($matches) > $bestCount) {
						$best = $register;
						$bestCount = count($matches);
					}
				}
			}//end foreach

			$targetRegister = $candidate;
			if (isset($best) === true) {
				$targetRegister = $best;
			}

			if ($targetRegister === null) {
				return [
					'success' => false,
					'message' => 'AMEF register not found',
				];
			}

			$config = [
				'register' => (string)($targetRegister['id'] ?? ''),
				// Initialize all known keys with empty strings to provide a stable shape.
				'organization_schema' => '',
				'element_schema' => '',
				'relation_schema' => '',
				'view_schema' => '',
				'model_schema' => '',
				'property_definition_schema' => '',
			];

			foreach (($targetRegister['schemas'] ?? []) as $schema) {
				$slug = strtolower($schema['slug'] ?? '');
				$allowed = ['organization','element','relation','view','model','property-definition'];
				if (in_array($slug, $allowed, true) === true) {
					// Handle property-definition schema with underscore in config key.
					$configKey = $slug . '_schema';
					if ($slug === 'property-definition') {
						$configKey = 'property_definition_schema';
					}

					$config[$configKey] = (string)$schema['id'];
				}
			}

			// Persist consolidated AMEF config JSON.
			$this->setAmefConfig(config: $config);

			return [
				'success' => true,
				'message' => 'AMEF configuration completed successfully',
				'configured' => $config,
				'errors' => [],
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'AMEF configuration failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}//end try
	}//end configureAmef()

	/**
	 * Configure required user groups
	 *
	 * @return array User groups configuration result
	 */
	private function configureGroups(): array {
		try {
			// Call the method to create required user groups.
			$result = $this->createAndConfigureUserGroups();

			return [
				'success' => $result['success'],
				'message' => $result['message'],
				'created' => $result['created'] ?? [],
				'existing' => $result['existing'] ?? [],
				'total' => $result['total'] ?? 0,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'User groups configuration failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}
	}//end configureGroups()

	/**
	 * Add step result to overall results and handle errors
	 *
	 * @param array $results The results array (passed by reference).
	 * @param array $stepResult The result of a configuration step.
	 * @param string $stepName The name of the step for error reporting.
	 *
	 * @return void
	 */
	private function addStepResult(array &$results, array $stepResult, string $stepName): void {
		if ($stepResult['success'] === false) {
			$results['errors'][] = $stepName . ' failed: ' . ($stepResult['message'] ?? 'Unknown error');
			$this->logger->warning(
				"SettingsService: {$stepName} failed",
				[
					'error' => $stepResult['message'] ?? 'Unknown error',
				]
			);
		} else {
			$this->logger->info("SettingsService: {$stepName} successful");
		}
	}//end addStepResult()

	/**
	 * Get consolidated configuration as JSON objects
	 *
	 * @return array The consolidated configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getConsolidatedConfiguration(): array {
		// Get email config and include templates.
		$emailConfig = $this->getEmailConfig();
		$emailConfig['templates'] = [
			'organization_registration' => $this->getEmailTemplate(templateName: 'organization_registration'),
			'organization_activation' => $this->getEmailTemplate(templateName: 'organization_activation'),
			'user_creation' => $this->getEmailTemplate(templateName: 'user_creation'),
		];

		// Get Voorzieningen and AMEF configs (without object counts for performance).
		$voorzieningenConfig = $this->getVoorzieningenConfig();
		$amefConfig = $this->getAmefConfig();

		return [
			'voorzieningen' => $voorzieningenConfig,
			'amef' => $amefConfig,
			'email' => $emailConfig,
			'archimate' => $this->getArchiMateStatus(),
			'userGroups' => [
				'generic' => $this->getGenericUserGroups(),
				'organizationAdmin' => $this->getOrganizationAdminGroups(),
				'superUser' => $this->getSuperUserGroups(),
			],
		];
	}//end getConsolidatedConfiguration()

	/**
	 * Get Voorzieningen configuration as JSON object
	 *
	 * @return array The voorzieningen configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenConfig(): array {
		$config = $this->config->getValueString($this->appName, 'voorzieningen_config', '{}');
		$decoded = json_decode($config, true);

		// Backward compatibility: build minimal structure from legacy scalar keys.
		if (is_array($decoded) === false) {
			$decoded = [
				'register' => $this->config->getValueString(
					$this->appName,
					'voorzieningen_register',
					''
				),
				'organisatie_schema' => $this->config->getValueString(
					$this->appName,
					'voorzieningen_organisatie_schema',
					''
				),
				'contactpersoon_schema' => $this->config->getValueString(
					$this->appName,
					'voorzieningen_contactpersoon_schema',
					''
				),
			];
		}

		// Normalize to the new, clean structure: no *_source or *_register keys,.
		// include all known schema keys, and accept legacy 'voorzieningen_*_schema' fallbacks.
		return $this->normalizeVoorzieningenConfig(input: $decoded);
	}//end getVoorzieningenConfig()

	/**
	 * Set Voorzieningen configuration as JSON object
	 *
	 * @param array $config The voorzieningen configuration.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setVoorzieningenConfig(array $config): void {
		// Clear cache since voorzieningen config affects schema and register IDs.
		$this->clearConfigurationCache();

		// Persist only normalized structure.
		$normalized = $this->normalizeVoorzieningenConfig(input: $config);
		$jsonConfig = json_encode($normalized, JSON_PRETTY_PRINT);
		$this->config->setValueString($this->appName, 'voorzieningen_config', $jsonConfig);
	}//end setVoorzieningenConfig()

	/**
	 * Normalize voorzieningen configuration to the new, clean format.
	 * - Keep only 'register' and individual '*_schema' keys
	 * - Drop any '*_source' and '*_register' keys
	 * - Ensure all known schema keys are present (null if missing)
	 *
	 * @param array $input Raw/legacy configuration.
	 *
	 * @return array Normalized configuration.
	 */
	private function normalizeVoorzieningenConfig(array $input): array {
		$normalized = [];

		// Register id.
		if (isset($input['register']) === true) {
			$normalized['register'] = (string)$input['register'];
		} else {
			$normalized['register'] = '';
		}

		// Known schema keys to support - updated to match actual schemas from register.
		$schemaKeys = [
			'organisatie_schema',
			'contactpersoon_schema',
			'suite_schema',
			'dienst_schema',
			'kwetsbaarheid_schema',
			'gebruik_schema',
			'contract_schema',
			'koppeling_schema',
			'beoordeeling_schema',
			'module_schema',
			'compliancy_schema',
			'moduleVersie_schema',
			'sector_schema',
			'sbomComponent_schema',
		];

		// Copy any present schema keys; ignore sources/registers.
		foreach ($schemaKeys as $key) {
			if (array_key_exists($key, $input) === true) {
				if ($input[$key] === null) {
					$normalized[$key] = '';
				} else {
					$normalized[$key] = (string)$input[$key];
				}
			} else {
				// Accept legacy keys that might be nested under 'voorzieningen_*_schema'.
				$normalized[$key] = '';
			}
		}

		return $normalized;
	}//end normalizeVoorzieningenConfig()

	/**
	 * Gets AMEF configuration using ArchiMateService to avoid code duplication
	 *
	 * This method delegates to ArchiMateService's getAmefConfig method
	 * to ensure consistency and avoid code duplication.
	 *
	 * @return array The AMEF configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAmefConfig(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			// Use reflection to access the private getAmefConfig method.
			// setAccessible() is unnecessary on PHP 8.1+ — private methods are
			// already invokable via reflection without it.
			$reflection = new \ReflectionClass($archiMateService);
			$method = $reflection->getMethod('getAmefConfig');

			return $method->invoke($archiMateService);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get AMEF config from ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$config = $this->config->getValueString($this->appName, 'amef_config', '{}');
			$decoded = json_decode($config, true);

			if (is_array($decoded) === false) {
				// Fallback to individual config values for backward compatibility.
				$decoded = [
					'register_id' => $this->config->getValueString(
						$this->appName,
						'amef_register_id',
						''
					),
					'organizations_schema' => $this->config->getValueString(
						$this->appName,
						'amef_organizations_schema',
						''
					),
					'elements_schema' => $this->config->getValueString(
						$this->appName,
						'amef_elements_schema',
						''
					),
					'relationships_schema' => $this->config->getValueString(
						$this->appName,
						'amef_relationships_schema',
						''
					),
					'views_schema' => $this->config->getValueString(
						$this->appName,
						'amef_views_schema',
						''
					),
					'models_schema' => $this->config->getValueString(
						$this->appName,
						'amef_models_schema',
						''
					),
				];
			}//end if

			return $decoded;
		}//end try
	}//end getAmefConfig()

	/**
	 * Set AMEF configuration as JSON object
	 *
	 * @param array $config The AMEF configuration.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setAmefConfig(array $config): void {
		$jsonConfig = json_encode($config, JSON_PRETTY_PRINT);
		$this->config->setValueString($this->appName, 'amef_config', $jsonConfig);

		// Clear configuration cache when AMEF config is updated.
		$this->clearConfigurationCache();

		$this->logger->debug(
			'SettingsService: AMEF configuration updated and cache cleared',
			[
				'config_keys' => array_keys($config),
				'cache_cleared' => true,
			]
		);
	}//end setAmefConfig()

	/**
	 * Get Email configuration as JSON object
	 *
	 * @return array The email configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailConfig(): array {
		$config = $this->config->getValueString($this->appName, 'email_config', '{}');
		$decoded = json_decode($config, true);

		if (is_array($decoded) === false) {
			// Fallback to individual config values for backward compatibility.
			$decoded = [
				'enabled' => $this->config->getValueString($this->appName, 'email_enabled', 'false') === 'true',
				'transport_type' => $this->config->getValueString($this->appName, 'email_transport_type', 'smtp'),
				'smtp_host' => $this->config->getValueString($this->appName, 'email_smtp_host', ''),
				'smtp_port' => $this->config->getValueString($this->appName, 'email_smtp_port', '587'),
				'smtp_username' => $this->config->getValueString($this->appName, 'email_smtp_username', ''),
				'smtp_password' => $this->config->getValueString($this->appName, 'email_smtp_password', ''),
				'smtp_encryption' => $this->config->getValueString($this->appName, 'email_smtp_encryption', 'tls'),
				'sender_email' => $this->config->getValueString($this->appName, 'sender_email', ''),
				'sender_name' => $this->config->getValueString($this->appName, 'sender_name', ''),
				'mailjet_api_key' => $this->config->getValueString($this->appName, 'email_mailjet_api_key', ''),
				'mailjet_secret_key' => $this->config->getValueString($this->appName, 'email_mailjet_secret_key', ''),
			];
		}

		return $decoded;
	}//end getEmailConfig()

	/**
	 * Set Email configuration as JSON object
	 *
	 * @param array $config The email configuration.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setEmailConfig(array $config): void {
		// Email config doesn't typically affect schema/register IDs, but clear cache for consistency.
		$this->clearConfigurationCache();

		$jsonConfig = json_encode($config, JSON_PRETTY_PRINT);
		$this->config->setValueString($this->appName, 'email_config', $jsonConfig);
	}//end setEmailConfig()

	/**
	 * Get ArchiMate import/export status and AMEF object counts
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return array The ArchiMate status with object counts
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getArchiMateStatus(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->getArchiMateStatus();
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get ArchiMate status from ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$importStatus = $this->config->getValueString($this->appName, 'archimate_import_status', '{}');
			$exportStatus = $this->config->getValueString($this->appName, 'archimate_export_status', '{}');

			$importDecoded = json_decode($importStatus, true);
			$exportDecoded = json_decode($exportStatus, true);

			// Get AMEF object counts.
			$amefObjectCounts = $this->getAmefObjectCounts();

			$importValue = [];
			if (is_array($importDecoded) === true) {
				$importValue = $importDecoded;
			}

			$exportValue = [];
			if (is_array($exportDecoded) === true) {
				$exportValue = $exportDecoded;
			}

			return [
				'import' => $importValue,
				'export' => $exportValue,
				'totalElementObjects' => $amefObjectCounts['totalElementObjects'],
				'totalOrganizationObjects' => $amefObjectCounts['totalOrganizationObjects'],
				'totalViewObjects' => $amefObjectCounts['totalViewObjects'],
				'totalRelationshipsObjects' => $amefObjectCounts['totalRelationshipsObjects'],
				'totalModelObjects' => $amefObjectCounts['totalModelObjects'],
			];
		}//end try
	}//end getArchiMateStatus()

	/**
	 * Get Voorzieningen object counts for statistics
	 *
	 * @return array Object counts for Voorzieningen schemas
	 */
	private function getVoorzieningenObjectCounts(): array {
		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [
					'totalOrganisatieObjects' => 0,
					'totalContactpersoonObjects' => 0,
					'totalVoorzieningObjects' => 0,
					'totalVoorzieningAanbodObjects' => 0,
					'totalVoorzieningVersieObjects' => 0,
					'totalKwetsbaarheidObjects' => 0,
					'totalContractObjects' => 0,
					'totalStandaardObjects' => 0,
					'totalReviewObjects' => 0,
					'totalKoppelingObjects' => 0,
					'totalBeoordeelingObjects' => 0,
					'totalVoorzieningModuleObjects' => 0,
					'totalVerklaringObjects' => 0,
					'totalKoppelingGebruikObjects' => 0,
					'totalCompliancyObjects' => 0,
					'totalModuleGebruikObjects' => 0,
					'totalModuleVersieObjects' => 0,
					'totalSectorObjects' => 0,
					'totalGebruikObjects' => 0,
				];
			}//end if

			$voorzieningenConfig = $this->getVoorzieningenConfig();
			$registerId = $voorzieningenConfig['register'] ?? null;

			// Define all schema mappings.
			$schemaMappings = [
				'organisatie_schema' => 'totalOrganisatieObjects',
				'contactpersoon_schema' => 'totalContactpersoonObjects',
				'voorziening_schema' => 'totalVoorzieningObjects',
				'voorziening_aanbod_schema' => 'totalVoorzieningAanbodObjects',
				'voorziening_versie_schema' => 'totalVoorzieningVersieObjects',
				'kwetsbaarheid_schema' => 'totalKwetsbaarheidObjects',
				'contract_schema' => 'totalContractObjects',
				'standaard_schema' => 'totalStandaardObjects',
				'review_schema' => 'totalReviewObjects',
				'koppeling_schema' => 'totalKoppelingObjects',
				'beoordeeling_schema' => 'totalBeoordeelingObjects',
				'module_schema' => 'totalVoorzieningModuleObjects',
				'verklaring_schema' => 'totalVerklaringObjects',
				'koppeling_gebruik_schema' => 'totalKoppelingGebruikObjects',
				'compliancy_schema' => 'totalCompliancyObjects',
				'module_gebruik_schema' => 'totalModuleGebruikObjects',
				'module_versie_schema' => 'totalModuleVersieObjects',
				'sector_schema' => 'totalSectorObjects',
				'gebruik_schema' => 'totalGebruikObjects',
			];

			$counts = [];

			// Initialize all counts to 0.
			foreach ($schemaMappings as $key => $countKey) {
				$counts[$countKey] = 0;
			}

			// Count objects for each configured schema.
			foreach ($schemaMappings as $configKey => $countKey) {
				$schemaId = $voorzieningenConfig[$configKey] ?? null;

				if ($registerId !== false && $schemaId === true) {
					try {
						$query = [
							'@self' => [
								'register' => (int)$registerId,
								'schema' => (int)$schemaId,
							],
						];
						// A true SQL COUNT via OpenRegister's countSearchObjects()
						// — NOT count(searchObjects()), which would either
						// hydrate the entire register into memory (unbounded)
						// or, if bounded with `_limit`, silently under-report
						// the total for a register larger than the limit.
						$counts[$countKey] = $objectService->countSearchObjects($query);
					} catch (\Exception $e) {
						$this->logger->warning("Failed to get {$configKey} count", ['error' => $e->getMessage()]);
					}
				}
			}//end foreach

			$this->logger->debug('SettingsService: Retrieved Voorzieningen object counts', $counts);

			return $counts;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get Voorzieningen object counts',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'totalOrganisatieObjects' => 0,
				'totalContactpersoonObjects' => 0,
				'totalVoorzieningObjects' => 0,
				'totalVoorzieningAanbodObjects' => 0,
				'totalVoorzieningVersieObjects' => 0,
				'totalKwetsbaarheidObjects' => 0,
				'totalContractObjects' => 0,
				'totalStandaardObjects' => 0,
				'totalReviewObjects' => 0,
				'totalKoppelingObjects' => 0,
				'totalBeoordeelingObjects' => 0,
				'totalVoorzieningModuleObjects' => 0,
				'totalVerklaringObjects' => 0,
				'totalKoppelingGebruikObjects' => 0,
				'totalCompliancyObjects' => 0,
				'totalModuleGebruikObjects' => 0,
				'totalModuleVersieObjects' => 0,
				'totalSectorObjects' => 0,
				'totalGebruikObjects' => 0,
			];
		}//end try
	}//end getVoorzieningenObjectCounts()

	/**
	 * Gets AMEF object counts for consolidated configuration
	 *
	 * This method retrieves counts of all AMEF object types using the ArchiMateService
	 * and returns them in a format suitable for the consolidated configuration.
	 *
	 * @return array AMEF object counts
	 */
	private function getAmefObjectCounts(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			// Get object counts using ArchiMateService methods.
			$elementObjects = $archiMateService->getElementObjects();
			$organizationObjects = $archiMateService->getOrganizationObjects();
			$viewObjects = $archiMateService->getViewObjects();
			$relationshipObjects = $archiMateService->getRelationshipObjects();
			$modelObjects = $archiMateService->getModelObjects();

			$this->logger->debug(
				'SettingsService: Retrieved AMEF object counts',
				[
					'elementObjects' => count($elementObjects),
					'organizationObjects' => count($organizationObjects),
					'viewObjects' => count($viewObjects),
					'relationshipObjects' => count($relationshipObjects),
					'modelObjects' => count($modelObjects),
				]
			);

			return [
				'totalElementObjects' => count($elementObjects),
				'totalOrganizationObjects' => count($organizationObjects),
				'totalViewObjects' => count($viewObjects),
				'totalRelationshipsObjects' => count($relationshipObjects),
				'totalModelObjects' => count($modelObjects),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get AMEF object counts',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Return zero counts on error to prevent API failures.
			return [
				'totalElementObjects' => 0,
				'totalOrganizationObjects' => 0,
				'totalViewObjects' => 0,
				'totalRelationshipsObjects' => 0,
				'totalModelObjects' => 0,
			];
		}//end try
	}//end getAmefObjectCounts()

	/**
	 * Set ArchiMate import status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @param array $status The import status.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setArchiMateImportStatus(array $status): void {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$archiMateService->setArchiMateImportStatus($status);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to set ArchiMate import status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$jsonStatus = json_encode($status, JSON_PRETTY_PRINT);
			$this->config->setValueString($this->appName, 'archimate_import_status', $jsonStatus);
		}
	}//end setArchiMateImportStatus()

	/**
	 * Set ArchiMate export status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @param array $status The export status.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setArchiMateExportStatus(array $status): void {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$archiMateService->setArchiMateExportStatus($status);
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to set ArchiMate export status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$jsonStatus = json_encode($status, JSON_PRETTY_PRINT);
			$this->config->setValueString($this->appName, 'archimate_export_status', $jsonStatus);
		}
	}//end setArchiMateExportStatus()

	/**
	 * Clear ArchiMate import status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return array Clear operation result
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearArchiMateImportStatus(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->clearArchiMateImportStatus();
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to clear ArchiMate import status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$this->config->deleteKey($this->appName, 'archimate_import_status');

			return [
				'cleared' => true,
				'process_killed' => false,
				'process_id' => null,
				'was_running' => false,
				'messages' => ['Import status cleared via fallback method'],
			];
		}//end try
	}//end clearArchiMateImportStatus()

	/**
	 * Force kill running ArchiMate import process and clear status
	 *
	 * This method delegates to ArchiMateService to handle process termination
	 * and status cleanup.
	 *
	 * @return array Kill operation result
	 * @deprecated Use cancelArchiMateImport() instead
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function killArchiMateImport(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->clearArchiMateImportStatus(true);
			// KillProcess = true.
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to kill ArchiMate import process via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to just clearing config if ArchiMateService is not available.
			$this->config->deleteKey($this->appName, 'archimate_import_status');

			return [
				'cleared' => true,
				'process_killed' => false,
				'process_id' => null,
				'was_running' => false,
				'messages' => ['Import status cleared via fallback method - could not kill process'],
			];
		}//end try
	}//end killArchiMateImport()

	/**
	 * Cancel a running ArchiMate import
	 *
	 * This method combines force clearing and process killing for a complete
	 * import cancellation. It delegates to ArchiMateService for the actual work.
	 *
	 * @return array Cancellation result with detailed status
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function cancelArchiMateImport(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			return $archiMateService->cancelArchiMateImport();
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to cancel ArchiMate import via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to just clearing config if ArchiMateService is not available.
			$this->config->deleteKey($this->appName, 'archimate_import_status');

			return [
				'cancelled' => true,
				'was_running' => false,
				'process_id' => null,
				'process_killed' => false,
				'status_cleared' => true,
				'cancellation_time' => date('Y-m-d H:i:s'),
				'messages' => ['Import status cleared via fallback method - ArchiMateService not available'],
			];
		}//end try
	}//end cancelArchiMateImport()

	/**
	 * Clear ArchiMate export status
	 *
	 * This method delegates to ArchiMateService to avoid code duplication
	 * and ensure consistency in ArchiMate status management.
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function clearArchiMateExportStatus(): void {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$archiMateService->clearArchiMateExportStatus();
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to clear ArchiMate export status via ArchiMateService',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			// Fallback to direct config access if ArchiMateService is not available.
			$this->config->deleteKey($this->appName, 'archimate_export_status');
		}
	}//end clearArchiMateExportStatus()

	/**
	 * Compact existing individual configuration values to JSON format
	 * This method reorganizes all the scattered config values into organized JSON objects
	 *
	 * @return array Compaction results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function compactToJsonConfiguration(): array {
		$results = [
			'success' => true,
			'migrated' => [],
			'errors' => [],
		];

		try {
			// 1. Migrate Voorzieningen configuration.
			$an = $this->appName;
			$voorzieningenConfig = [
				'register' => $this->config->getValueString(
					$an,
					'voorzieningen_register',
					''
				),
				'organisatie_schema' => $this->config->getValueString(
					$an,
					'voorzieningen_organisatie_schema',
					''
				),
				'contactpersoon_schema' => $this->config->getValueString(
					$an,
					'voorzieningen_contactpersoon_schema',
					''
				),
				'organisatie_source' => $this->config->getValueString(
					$an,
					'voorzieningen_organisatie_source',
					'openregister'
				),
				'contactpersoon_source' => $this->config->getValueString(
					$an,
					'voorzieningen_contactpersoon_source',
					'openregister'
				),
				'organisatie_register' => $this->config->getValueString(
					$an,
					'voorzieningen_organisatie_register',
					''
				),
				'contactpersoon_register' => $this->config->getValueString(
					$an,
					'voorzieningen_contactpersoon_register',
					''
				),
			];

			$this->setVoorzieningenConfig(config: $voorzieningenConfig);
			$results['migrated']['voorzieningen'] = $voorzieningenConfig;

			// 2. Migrate AMEF configuration.
			$amefConfig = [
				'register_id' => $this->config->getValueString(
					$an,
					'amef_register_id',
					''
				),
				'organizations_schema' => $this->config->getValueString(
					$an,
					'amef_organizations_schema',
					''
				),
				'elements_schema' => $this->config->getValueString(
					$an,
					'amef_elements_schema',
					''
				),
				'relationships_schema' => $this->config->getValueString(
					$an,
					'amef_relationships_schema',
					''
				),
				'views_schema' => $this->config->getValueString(
					$an,
					'amef_views_schema',
					''
				),
				'models_schema' => $this->config->getValueString(
					$an,
					'amef_models_schema',
					''
				),
				'organization_source' => $this->config->getValueString(
					$an,
					'amef_organization_source',
					'openregister'
				),
				'organization_register' => $this->config->getValueString(
					$an,
					'amef_organization_register',
					''
				),
				'organization_schema' => $this->config->getValueString(
					$an,
					'amef_organization_schema',
					''
				),
				// Note: Duplicated entries with typos kept for backward compatibility.
				'elementss_schema' => $this->config->getValueString(
					$an,
					'amef_elementss_schema',
					''
				),
				'organizationss_schema' => $this->config->getValueString(
					$an,
					'amef_organizationss_schema',
					''
				),
				'relationshipss_schema' => $this->config->getValueString(
					$an,
					'amef_relationshipss_schema',
					''
				),
			];

			$this->setAmefConfig(config: $amefConfig);
			$results['migrated']['amef'] = $amefConfig;

			// 3. Migrate Email configuration.
			$emailConfig = [
				'enabled' => $this->config->getValueString(
					$an,
					'email_enabled',
					'false'
				) === 'true',
				'transport_type' => $this->config->getValueString(
					$an,
					'email_transport_type',
					'smtp'
				),
				'smtp_host' => $this->config->getValueString(
					$an,
					'email_smtp_host',
					''
				),
				'smtp_port' => $this->config->getValueString(
					$an,
					'email_smtp_port',
					'587'
				),
				'smtp_username' => $this->config->getValueString(
					$an,
					'email_smtp_username',
					''
				),
				'smtp_password' => $this->config->getValueString(
					$an,
					'email_smtp_password',
					''
				),
				'smtp_encryption' => $this->config->getValueString(
					$an,
					'email_smtp_encryption',
					'tls'
				),
				'sender_email' => $this->config->getValueString(
					$an,
					'sender_email',
					''
				),
				'sender_name' => $this->config->getValueString(
					$an,
					'sender_name',
					''
				),
				'mailjet_api_key' => $this->config->getValueString(
					$an,
					'email_mailjet_api_key',
					''
				),
				'mailjet_secret_key' => $this->config->getValueString(
					$an,
					'email_mailjet_secret_key',
					''
				),
				'sendgrid_api_key' => $this->config->getValueString(
					$an,
					'email_sendgrid_api_key',
					''
				),
				'mailgun_api_key' => $this->config->getValueString(
					$an,
					'email_mailgun_api_key',
					''
				),
				'mailgun_domain' => $this->config->getValueString(
					$an,
					'email_mailgun_domain',
					''
				),
				'postmark_api_key' => $this->config->getValueString(
					$an,
					'email_postmark_api_key',
					''
				),
				'ses_access_key' => $this->config->getValueString(
					$an,
					'email_ses_access_key',
					''
				),
				'ses_secret_key' => $this->config->getValueString(
					$an,
					'email_ses_secret_key',
					''
				),
				'ses_region' => $this->config->getValueString(
					$an,
					'email_ses_region',
					'us-east-1'
				),
				'org_registration_enabled' => $this->config->getValueString(
					$an,
					'email_org_registration_enabled',
					'true'
				) === 'true',
				'org_activation_enabled' => $this->config->getValueString(
					$an,
					'email_org_activation_enabled',
					'true'
				) === 'true',
				'user_creation_enabled' => $this->config->getValueString(
					$an,
					'email_user_creation_enabled',
					'true'
				) === 'true',
				'test_receiver_override' => $this->config->getValueString(
					$an,
					'test_receiver_override',
					''
				),
			];

			$this->setEmailConfig(config: $emailConfig);
			$results['migrated']['email'] = $emailConfig;

			$this->logger->info(
				'Configuration compaction to JSON format completed successfully',
				[
					'compacted_sections' => array_keys($results['migrated']),
				]
			);
		} catch (\Exception $e) {
			$results['success'] = false;
			$results['errors'][] = 'Compaction failed: ' . $e->getMessage();
			$this->logger->error(
				'Configuration compaction to JSON format failed',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try

		return $results;
	}//end compactToJsonConfiguration()

	/**
	 * Clean up old individual configuration values after compaction
	 * This method removes the old scattered config values after successful compaction
	 *
	 * @return array Cleanup results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function cleanupOldConfiguration(): array {
		$results = [
			'success' => true,
			'cleaned' => [],
			'errors' => [],
		];

		try {
			// List of old configuration keys to remove.
			$oldKeys = [
				// Voorzieningen keys - old individual keys.
				'voorzieningen_register',
				'voorzieningen_organisatie_schema',
				'voorzieningen_contactpersoon_schema',
				'voorzieningen_gebruiker_schema',
				// Deprecated - no longer used.
				'voorzieningen_contactgegevens_schema',
				// Deprecated - no longer used.
				'voorzieningen_organisatie_source',
				'voorzieningen_contactpersoon_source',
				'voorzieningen_gebruiker_source',
				// Deprecated - no longer used.
				'voorzieningen_contactgegevens_source',
				// Deprecated - no longer used.
				'voorzieningen_organisatie_register',
				'voorzieningen_contactpersoon_register',
				'voorzieningen_gebruiker_register',
				// Deprecated - no longer used.
				'voorzieningen_contactgegevens_register',
				// Deprecated - no longer used.
				// Old Voorzieningen schema keys that no longer exist in register.
				'voorzieningen_voorziening_schema',
				'voorzieningen_voorziening_aanbod_schema',
				'voorzieningen_voorziening_versie_schema',
				'voorzieningen_standaard_schema',
				'voorzieningen_review_schema',
				'voorzieningen_voorziening_module_schema',
				'voorzieningen_verklaring_schema',
				'voorzieningen_koppeling_gebruik_schema',
				'voorzieningen_module_gebruik_schema',
				'voorzieningen_module_versie_schema',

				// AMEF keys - old individual keys.
				'amef_register_id',
				'amef_organizations_schema',
				'amef_elements_schema',
				'amef_relationships_schema',
				'amef_views_schema',
				'amef_models_schema',
				'amef_property_definitions_schema',
				'amef_organization_source',
				'amef_organization_register',
				'amef_organization_schema',
				'amef_elementss_schema',
				'amef_organizationss_schema',
				'amef_relationshipss_schema',

				// AMEF keys with hyphen format (old).
				'amef_property-definition_schema',
				'amef_extendview_schema',
				// No longer in register.
				// Email keys.
				'email_enabled',
				'email_transport_type',
				'email_smtp_host',
				'email_smtp_port',
				'email_smtp_username',
				'email_smtp_password',
				'email_smtp_encryption',
				'sender_email',
				'sender_name',
				'email_mailjet_api_key',
				'email_mailjet_secret_key',
				'email_sendgrid_api_key',
				'email_mailgun_api_key',
				'email_mailgun_domain',
				'email_postmark_api_key',
				'email_ses_access_key',
				'email_ses_secret_key',
				'email_ses_region',
				'email_org_registration_enabled',
				'email_org_activation_enabled',
				'email_user_creation_enabled',
				'test_receiver_override',
			];

			foreach ($oldKeys as $key) {
				try {
					$this->config->deleteKey($this->appName, $key);
					$results['cleaned'][] = $key;
				} catch (\Exception $e) {
					$results['errors'][] = "Failed to delete key '{$key}': " . $e->getMessage();
				}
			}

			$this->logger->info(
				'Old configuration cleanup completed',
				[
					'cleaned_keys' => count($results['cleaned']),
					'errors' => count($results['errors']),
				]
			);
		} catch (\Exception $e) {
			$results['success'] = false;
			$results['errors'][] = 'Cleanup failed: ' . $e->getMessage();
			$this->logger->error(
				'Old configuration cleanup failed',
				[
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $results;
	}//end cleanupOldConfiguration()

	// ======================================================.
	// CONTROLLER BUSINESS LOGIC METHODS.
	// ======================================================.

	/**
	 * Get all settings including user groups and email settings
	 * This aggregates all settings data for the main settings endpoint
	 *
	 * @return array Complete settings data
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllSettings(): array {
		try {
			// Provide only lightweight settings data. Section-specific data is.
			// available via focused endpoints for performance.
			$base = $this->getSettings();

			$versionInfo = $this->getVersionInfo();

			// Get voorzieningen config (lightweight - just reads from config storage).
			$voorzieningenConfig = $this->getVoorzieningenConfig();

			// Get amef config directly from config storage (avoid heavy ArchiMateService call).
			$amefConfigJson = $this->config->getValueString($this->appName, 'amef_config', '{}');
			$amefConfig = json_decode($amefConfigJson, true);
			if (is_array($amefConfig) === false) {
				$amefConfig = [
					'register' => $this->config->getValueString($this->appName, 'amef_register_id', ''),
					'organization_schema' => $this->config->getValueString($this->appName, 'amef_organizations_schema', ''),
					'element_schema' => $this->config->getValueString($this->appName, 'amef_elements_schema', ''),
					'relation_schema' => $this->config->getValueString($this->appName, 'amef_relationships_schema', ''),
					'view_schema' => $this->config->getValueString($this->appName, 'amef_views_schema', ''),
					'model_schema' => $this->config->getValueString($this->appName, 'amef_models_schema', ''),
				];
			}

			$result = [
				'availableRegisters' => $base['availableRegisters'] ?? [],
				'versionInfo' => $versionInfo,
				'voorzieningenConfig' => $voorzieningenConfig,
				'amefConfig' => $amefConfig,
				'timestamp' => time(),
			];

			return $result;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get all settings',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}//end try
	}//end getAllSettings()

	/**
	 * Get object counts statistics for all configured registers
	 *
	 * @return array Statistics for all registers with object counts
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectCountsStatistics(): array {
		try {
			$statistics = [
				'voorzieningen' => [],
				'amef' => [],
				'timestamp' => time(),
			];

			// Get Voorzieningen statistics.
			try {
				$voorzieningenConfig = $this->getVoorzieningenConfig();
				$voorzieningenCounts = $this->getVoorzieningenObjectCounts();

				$statistics['voorzieningen'] = [
					'config' => $voorzieningenConfig,
					'object_counts' => $voorzieningenCounts,
					'configured' => empty($voorzieningenConfig['register']) === false
						&& empty($voorzieningenConfig['organisatie_schema']) === false,
				];
			} catch (\Exception $e) {
				$this->logger->error('Failed to get Voorzieningen statistics', ['error' => $e->getMessage()]);
				$statistics['voorzieningen'] = [
					'config' => [],
					'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
					'configured' => false,
					'error' => $e->getMessage(),
				];
			}

			// Get AMEF statistics.
			try {
				$amefConfig = $this->getAmefConfig();
				$amefCounts = $this->getAmefObjectCounts();

				$statistics['amef'] = [
					'config' => $amefConfig,
					'object_counts' => $amefCounts,
					'configured' => empty($amefConfig['register_id']) === false
						&& empty($amefConfig['elements_schema']) === false,
				];
			} catch (\Exception $e) {
				$this->logger->error('Failed to get AMEF statistics', ['error' => $e->getMessage()]);
				$statistics['amef'] = [
					'config' => [],
					'object_counts' => [
						'totalElementObjects' => 0,
						'totalOrganizationObjects' => 0,
						'totalViewObjects' => 0,
						'totalRelationshipsObjects' => 0,
						'totalModelObjects' => 0,
					],
					'configured' => false,
					'error' => $e->getMessage(),
				];
			}//end try

			return $statistics;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get object counts statistics',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'voorzieningen' => [
					'config' => [],
					'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
					'configured' => false,
					'error' => $e->getMessage(),
				],
				'amef' => [
					'config' => [],
					'object_counts' => [
						'totalElementObjects' => 0,
						'totalOrganizationObjects' => 0,
						'totalViewObjects' => 0,
						'totalRelationshipsObjects' => 0,
						'totalModelObjects' => 0,
					],
					'configured' => false,
					'error' => $e->getMessage(),
				],
				'timestamp' => time(),
				'error' => $e->getMessage(),
			];
		}//end try
	}//end getObjectCountsStatistics()

	/**
	 * Get all email templates with error handling
	 * This handles template iteration and individual failures
	 *
	 * @return array All email templates
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllEmailTemplates(): array {
		$templateTypes = [
			'organization_registration',
			'organization_activation',
			'user_creation',
			'user_organisation',
		];
		$templates = [];

		foreach ($templateTypes as $templateName) {
			try {
				$templates[$templateName] = $this->getEmailTemplate(templateName: $templateName);
			} catch (\Exception $e) {
				$this->logger->warning("Failed to get template {$templateName}", ['error' => $e->getMessage()]);
				$templates[$templateName] = null;
			}
		}

		return $templates;
	}//end getAllEmailTemplates()

	/**
	 * Update generic user groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateGenericUserGroups(array $groups): array {
		try {
			$validation = $this->validateGroups(groups: $groups);

			if (empty($validation['invalid']) === false) {
				return [
					'success' => false,
					'message' => 'Invalid group names provided',
					'validation' => $validation,
				];
			}

			$this->setGenericUserGroups(groups: $validation['valid']);

			return [
				'success' => true,
				'message' => 'Generic user groups updated successfully',
				'groups' => $validation['valid'],
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to update generic user groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update generic user groups: ' . $e->getMessage(),
			];
		}//end try
	}//end updateGenericUserGroups()

	/**
	 * Update organization admin groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateOrganizationAdminGroups(array $groups): array {
		try {
			$validation = $this->validateGroups(groups: $groups);

			if (empty($validation['invalid']) === false) {
				return [
					'success' => false,
					'message' => 'Invalid group names provided',
					'validation' => $validation,
				];
			}

			$this->setOrganizationAdminGroups(groups: $validation['valid']);

			return [
				'success' => true,
				'message' => 'Organization admin groups updated successfully',
				'groups' => $validation['valid'],
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to update organization admin groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update organization admin groups: ' . $e->getMessage(),
			];
		}//end try
	}//end updateOrganizationAdminGroups()

	/**
	 * Update super user groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateSuperUserGroups(array $groups): array {
		try {
			$validation = $this->validateGroups(groups: $groups);

			if (empty($validation['invalid']) === false) {
				return [
					'success' => false,
					'message' => 'Invalid group names provided',
					'validation' => $validation,
				];
			}

			$this->setSuperUserGroups(groups: $validation['valid']);

			return [
				'success' => true,
				'message' => 'Super user groups updated successfully',
				'groups' => $validation['valid'],
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to update super user groups',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update super user groups: ' . $e->getMessage(),
			];
		}//end try
	}//end updateSuperUserGroups()

	// ======================================================.
	// FOCUSED ENDPOINT METHODS FOR PERFORMANCE OPTIMIZATION.
	// ======================================================.

	/**
	 * Get ArchiMate configuration only
	 *
	 * @return array ArchiMate configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getArchiMateConfig(): array {
		try {
			$config = $this->getAmefConfig();
			$status = $this->getArchiMateStatus();

			return [
				'success' => true,
				'config' => $config,
				'status' => $status,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get ArchiMate config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get ArchiMate config: ' . $e->getMessage(),
			];
		}//end try
	}//end getArchiMateConfig()

	/**
	 * Update ArchiMate configuration
	 *
	 * @param array $config ArchiMate configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateArchiMateConfig(array $config): array {
		try {
			$this->setAmefConfig(config: $config);

			return [
				'success' => true,
				'message' => 'ArchiMate configuration updated successfully',
				'config' => $this->getAmefConfig(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update ArchiMate config',
				[
					'exception' => $e->getMessage(),
					'config' => $config,
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update ArchiMate config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateArchiMateConfig()

	/**
	 * Get email configuration only
	 *
	 * @return array Email configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailConfigFocused(): array {
		try {
			// Redact before this leaves the process. getEmailSettings() returns the real
			// secrets (SymfonyEmailService needs them to send); this method's result goes
			// straight into an HTTP response, so the SMTP password and the SendGrid /
			// Mailgun / Postmark / SES / Mailjet keys must never ride along.
			$emailSettings = $this->redactEmailSecrets(settings: $this->getEmailSettings());
			$emailTemplates = $this->getAllEmailTemplates();

			return [
				'success' => true,
				'emailSettings' => $emailSettings,
				'emailTemplates' => $emailTemplates,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get email config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get email config: ' . $e->getMessage(),
			];
		}//end try
	}//end getEmailConfigFocused()

	/**
	 * Update email configuration
	 *
	 * @param array $config Email configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailConfig(array $config): array {
		try {
			// No isset($config) guard: it is a required, non-nullable parameter.
			$result = $this->updateEmailSettings(emailSettings: $config);
			if ($result['success'] === false) {
				return $result;
			}

			return [
				'success' => true,
				'message' => 'Email configuration updated successfully',
				'config' => $this->getEmailConfig(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update email config',
				[
					// Only the KEYS. The payload carries the SMTP password and the provider
					// API keys, so logging $config verbatim wrote them to nextcloud.log.
					'exception' => $e->getMessage(),
					'configKeys' => array_keys($config),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update email config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateEmailConfig()

	/**
	 * Get AMEF configuration only
	 *
	 * @return array AMEF configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAmefConfigFocused(): array {
		try {
			$config = $this->getAmefConfig();

			return [
				'success' => true,
				'config' => $config,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get AMEF config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get AMEF config: ' . $e->getMessage(),
			];
		}
	}//end getAmefConfigFocused()

	/**
	 * Update AMEF configuration
	 *
	 * @param array $config AMEF configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateAmefConfig(array $config): array {
		try {
			// Remove framework routing keys.
			unset($config['_route']);

			// Load existing config to allow merging.
			$existing = $this->getAmefConfig();
			// No is_array() fallback: getAmefConfig() is declared to return array.

			// Determine target register id.
			if (isset($config['register']) === true) {
				$targetRegisterId = (string)$config['register'];
			} else {
				$targetRegisterId = '';
				if (isset($existing['register']) === true) {
					$targetRegisterId = (string)$existing['register'];
				}
			}

			// If a register is provided, validate that provided schema ids belong to that register.
			// Only accept singular keys; ignore unknown keys silently.
			$allowedKeys = [
				'organization_schema',
				'element_schema',
				'relation_schema',
				'view_schema',
				'model_schema',
				'property_definition_schema',
			];

			$validated = [];
			if ($targetRegisterId !== '') {
				$objectService = $this->getObjectService();
				if ($objectService !== null) {
					// Build a set of schema ids for the chosen register.
					try {
						$registerService = $this->getRegisterService();
						$registers = $registerService->findAll();
						$schemaIdSet = [];
						foreach ($registers as $register) {
							$register = $register->jsonSerialize();
							if ((string)($register['id'] ?? '') === $targetRegisterId) {
								foreach (($register['schemas'] ?? []) as $schema) {
									if (is_array($schema) === true && isset($schema['id']) === true) {
										$schemaIdSet[(string)$schema['id']] = true;
									} else {
										$schemaIdSet[(string)$schema] = true;
									}
								}

								break;
							}
						}
					} catch (\TypeError|\Exception $e) {
						$this->logger->warning(
							'OpenRegister RegisterService->findAll() failed in updateAmefConfig',
							[
								'exception' => $e->getMessage(),
								'file' => $e->getFile(),
								'line' => $e->getLine(),
							]
						);
						// Continue with empty schema set which will cause validation to fail gracefully.
						$schemaIdSet = [];
					}//end try

					// Validate each provided schema id against the chosen register.
					foreach ($allowedKeys as $key) {
						if (array_key_exists($key, $config) === true) {
							$value = (string)$config[$key];
							if ($value !== '' && isset($schemaIdSet[$value]) === true) {
								$validated[$key] = $value;
							} else {
								// Skip invalid or cross-register ids.
								$this->logger->warning(
									'SettingsService: Ignored AMEF config key, invalid schema/register combo',
									[
										'key' => $key,
										'value' => $value,
										'register' => $targetRegisterId,
									]
								);
							}
						}
					}
				}//end if
			}//end if

			// Merge: keep register and any validated schema keys; drop unknowns.
			$merged = $existing;
			if ($targetRegisterId !== '') {
				$merged['register'] = $targetRegisterId;
			}

			foreach ($allowedKeys as $key) {
				if (array_key_exists($key, $validated) === true) {
					$merged[$key] = $validated[$key];
				} elseif (array_key_exists($key, $merged) === false) {
					// Ensure key presence with empty string for frontend mapping stability.
					$merged[$key] = '';
				}
			}

			$this->setAmefConfig(config: $merged);

			return [
				'success' => true,
				'message' => 'AMEF configuration updated successfully',
				'config' => $this->getAmefConfig(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update AMEF config',
				[
					'exception' => $e->getMessage(),
					'config' => $config,
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update AMEF config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateAmefConfig()

	/**
	 * Get Voorzieningen configuration only
	 *
	 * @return array Voorzieningen configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getVoorzieningenConfigFocused(): array {
		try {
			$config = $this->getVoorzieningenConfig();

			return [
				'success' => true,
				'config' => $config,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get Voorzieningen config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get Voorzieningen config: ' . $e->getMessage(),
			];
		}
	}//end getVoorzieningenConfigFocused()

	/**
	 * Update Voorzieningen configuration
	 *
	 * @param array $config Voorzieningen configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateVoorzieningenConfig(array $config): array {
		try {
			$this->setVoorzieningenConfig(config: $config);

			return [
				'success' => true,
				'message' => 'Voorzieningen configuration updated successfully',
				'config' => $this->getVoorzieningenConfig(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update Voorzieningen config',
				[
					'exception' => $e->getMessage(),
					'config' => $config,
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update Voorzieningen config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateVoorzieningenConfig()

	/**
	 * Get object counts only (lightweight)
	 *
	 * @return array Object counts for all registers
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectsCounts(): array {
		try {
			$counts = [
				'voorzieningen' => $this->getVoorzieningenObjectCounts(),
				'amef' => $this->getAmefObjectCounts(),
				'timestamp' => time(),
			];

			return [
				'success' => true,
				'counts' => $counts,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get object counts',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get object counts: ' . $e->getMessage(),
			];
		}//end try
	}//end getObjectsCounts()

	/**
	 * Get object statistics (full statistics with configuration)
	 *
	 * @return array Full object statistics
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getObjectsStatistics(): array {
		try {
			$statistics = $this->getObjectCountsStatistics();

			return [
				'success' => true,
				'statistics' => $statistics,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get object statistics',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get object statistics: ' . $e->getMessage(),
			];
		}
	}//end getObjectsStatistics()

	/**
	 * Get user groups configuration only
	 *
	 * @return array User groups configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getUserGroupsConfig(): array {
		try {
			$config = [
				'generic' => $this->getGenericUserGroups(),
				'organizationAdmin' => $this->getOrganizationAdminGroups(),
				'superUser' => $this->getSuperUserGroups(),
				'allGroups' => $this->getAllGroups(),
			];

			return [
				'success' => true,
				'config' => $config,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get user groups config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get user groups config: ' . $e->getMessage(),
			];
		}//end try
	}//end getUserGroupsConfig()

	/**
	 * Update user groups configuration
	 *
	 * @param array $config User groups configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateUserGroupsConfig(array $config): array {
		try {
			$results = [];

			if (isset($config['generic']) === true) {
				$results['generic'] = $this->updateGenericUserGroups(groups: $config['generic']);
			}

			if (isset($config['organizationAdmin']) === true) {
				$results['organizationAdmin'] = $this->updateOrganizationAdminGroups(groups: $config['organizationAdmin']);
			}

			if (isset($config['superUser']) === true) {
				$results['superUser'] = $this->updateSuperUserGroups(groups: $config['superUser']);
			}

			// Check if any updates failed.
			$failed = array_filter(
				$results,
				function ($result) {
					return $result['success'] === false;
				}
			);

			if (empty($failed) === false) {
				return [
					'success' => false,
					'message' => 'Some user group updates failed',
					'results' => $results,
				];
			}

			return [
				'success' => true,
				'message' => 'User groups configuration updated successfully',
				'config' => $this->getUserGroupsConfig(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update user groups config',
				[
					'exception' => $e->getMessage(),
					'config' => $config,
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update user groups config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateUserGroupsConfig()

	/**
	 * Get catalog location
	 *
	 * @return string The catalog location URL
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function getCatalogLocation(): string {
		return $this->config->getValueString($this->appName, 'catalog_location', '');
	}//end getCatalogLocation()

	/**
	 * Set catalog location
	 *
	 * @param string $location The catalog location URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function setCatalogLocation(string $location): void {
		$this->config->setValueString($this->appName, 'catalog_location', $location);
	}//end setCatalogLocation()

	/**
	 * High-performance sync of OpenRegister organisations to voorzieningen register
	 *
	 * Optimized for large-scale operations (1000+ organisations) using bulk operations.
	 * Uses OpenRegister's ultraFastBulkSave for maximum performance.
	 *
	 * @param array $options Configuration options:
	 *                       - batch_size: Number of organisations per batch (default: 500)
	 *                       - dry_run: Only check what would be created (default: false)
	 *
	 * @return array Sync results with performance metrics
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function syncOrganisationsToVoorzieningenOptimized(array $options = []): array {
		$startTime = microtime(true);
		$batchSize = $options['batch_size'] ?? 500;
		$isDryRun = $options['dry_run'] ?? false;

		try {
			$this->logger->info(
				'Starting optimized organisation sync',
				[
					'batch_size' => $batchSize,
					'dry_run' => $isDryRun,
				]
			);

			// 1. Validate prerequisites.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return ['success' => false, 'message' => 'OpenRegister service not available'];
			}

			$voorzieningenConfig = $this->getVoorzieningenConfig();
			if (empty($voorzieningenConfig['register']) === true
				|| empty($voorzieningenConfig['organisatie_schema']) === true
			) {
				return ['success' => false, 'message' => 'Voorzieningen register or organisatie schema not configured'];
			}

			$this->logger->debug(
				'Prerequisites validated',
				[
					'register_id' => $voorzieningenConfig['register'],
					'organisatie_schema_id' => $voorzieningenConfig['organisatie_schema'],
				]
			);

			// 2. BULK FETCH: Get all organisations in one query.
			$organisationMapper = $this->container->get(\OCA\OpenRegister\Db\OrganisationMapper::class);
			$allOrganisations = $organisationMapper->findAllWithUserCount();

			$this->logger->info(
				'Retrieved organisations from OpenRegister',
				[
					'total_organisations' => count($allOrganisations),
				]
			);

			// 3. BULK FETCH: Get existing organisaties in one query.
			$existingOrganisaties = $objectService->searchObjectsPaginated(
				query: [
					'@self' => [
						'register' => $voorzieningenConfig['register'],
						'schema' => $voorzieningenConfig['organisatie_schema'],
					],
					'_limit' => 10000,
					// Get all existing.
				],
				_rbac: false,
				_multitenancy: false
			);

			$this->logger->info(
				'Retrieved existing organisaties from voorzieningen register',
				[
					'existing_count' => count($existingOrganisaties['results'] ?? []),
				]
			);

			// 4. MEMORY-EFFICIENT: Build lookup set for existing UUIDs.
			// Now we can compare by UUID since we force UUIDs to match OpenRegister organisation UUIDs.
			$existingUuids = array_flip(
				array_map(
					function ($org) {
						if ($org instanceof \OCA\OpenRegister\Db\ObjectEntity) {
							return $org->getUuid() ?? '';
						}

						return $org['@self']['id'] ?? '';
					},
					$existingOrganisaties['results'] ?? []
				)
			);

			$this->logger->debug(
				'Deduplication analysis',
				[
					'existing_uuids_count' => count($existingUuids),
					'existing_uuids_sample' => array_slice(array_keys($existingUuids), 0, 3),
					'total_openregister_orgs' => count($allOrganisations),
				]
			);

			// 5. BATCH PREPARATION: Filter and prepare objects for bulk creation.
			$organisationsToCreate = [];
			$skippedCount = 0;
			foreach ($allOrganisations as $organisation) {
				$orgUuid = $organisation->getUuid();

				// DEBUG: Log first few comparisons.
				if (count($organisationsToCreate) < 3) {
					$this->logger->debug(
						'UUID comparison debug',
						[
							'openregister_uuid' => $orgUuid,
							'exists_in_voorzieningen' => isset($existingUuids[$orgUuid]) === true,
							'organisation_name' => $organisation->getName(),
						]
					);
				}

				// Skip if already exists (compare by UUID now that we force UUIDs).
				if (isset($existingUuids[$orgUuid]) === true) {
					$skippedCount++;
					continue;
				}

				// Prepare organisatie data with forced UUID.
				$statusValue = 'Inactief';
				if ($organisation->getActive() === true) {
					$statusValue = 'Active';
				}

				$organisationsToCreate[] = [
					'id' => $orgUuid,
					// Force the UUID to match OpenRegister organisation UUID.
					'@self' => [
						'id' => $orgUuid,
						// Also set in @self section for consistency.
						'uuid' => $orgUuid,
					],
					'name' => $organisation->getName(),
					'description' => $organisation->getDescription() ?? '',
					'type' => $this->determineOrganisationType(organisation: $organisation),
					'status' => $statusValue,
					'website' => '',
					'e-mailadres' => null,
					'telefoonnummer' => null,
					'oin' => '',
					'cbs' => '',
					'participants' => [],
					'contactpersonen' => [],
				];
			}//end foreach

			$results = [
				'total_organisations' => count($allOrganisations),
				'existing_count' => count($existingUuids),
				'to_create_count' => count($organisationsToCreate),
				'created_count' => 0,
				'failed_count' => 0,
				'batches_processed' => 0,
				'performance' => [],
			];

			$this->logger->info(
				'Organisation analysis completed',
				[
					'total' => $results['total_organisations'],
					'existing' => $results['existing_count'],
					'to_create' => $results['to_create_count'],
					'skipped_count' => $skippedCount,
					'deduplication_working' => $skippedCount > 0,
				]
			);

			if (empty($isDryRun) === false) {
				$results['message'] = "DRY RUN: Would create {$results['to_create_count']} organisations";
				return ['success' => true, 'results' => $results];
			}

			if (empty($organisationsToCreate) === true) {
				$results['message'] = 'All organisations already exist in voorzieningen register';
				return ['success' => true, 'results' => $results];
			}

			// 6. ULTRA-FAST BULK PROCESSING: Process in optimized batches.
			$objectService->setRegister($voorzieningenConfig['register']);
			$objectService->setSchema($voorzieningenConfig['organisatie_schema']);

			$batches = array_chunk($organisationsToCreate, $batchSize);

			foreach ($batches as $batchIndex => $batch) {
				$batchStartTime = microtime(true);

				try {
					$this->logger->debug(
						'Processing batch',
						[
							'batch' => $batchIndex + 1,
							'total_batches' => count($batches),
							'objects_in_batch' => count($batch),
						]
					);

					// BULK OPERATION: Create entire batch in single operation.
					$bulkResult = $objectService->saveObjects(
						objects: $batch,
						register: $voorzieningenConfig['register'],
						schema: $voorzieningenConfig['organisatie_schema'],
						_rbac: false,
						_multitenancy: false,
						validation: false,
						// Skip validation for performance.
						events: false
						// Skip events for performance.
					);

					$batchTime = microtime(true) - $batchStartTime;
					$objectsPerSecond = count($batch) / $batchTime;

					$results['created_count'] += $bulkResult['statistics']['saved'] ?? 0;
					$results['failed_count'] += $bulkResult['statistics']['errors'] ?? 0;
					$results['batches_processed']++;

					$results['performance'][] = [
						'batch' => $batchIndex + 1,
						'objects' => count($batch),
						'time_seconds' => round($batchTime, 3),
						'objects_per_second' => round($objectsPerSecond, 0),
					];

					$this->logger->info(
						'Bulk organisation sync batch completed',
						[
							'batch' => $batchIndex + 1,
							'total_batches' => count($batches),
							'objects_in_batch' => count($batch),
							'objects_per_second' => round($objectsPerSecond, 0),
						]
					);
				} catch (\Exception $e) {
					$results['failed_count'] += count($batch);
					$this->logger->error(
						'Bulk organisation sync batch failed',
						[
							'batch' => $batchIndex + 1,
							'error' => $e->getMessage(),
							'objects_in_batch' => count($batch),
						]
					);
				}//end try
			}//end foreach

			$totalTime = microtime(true) - $startTime;
			$overallPerformance = 0;
			if ($results['created_count'] > 0) {
				$overallPerformance = (int)round($results['created_count'] / max($totalTime, 0.001));
			}

			$estimatedImprovementValue = 'baseline';
			if ($overallPerformance > 10) {
				$estimatedImprovementValue = 'high';
			}

			$createdCount = $results['created_count'];
			$existingCount = $results['existing_count'];
			$failedCount = $results['failed_count'];
			$syncMessage = "Sync completed: {$createdCount} created, {$existingCount} existing, {$failedCount} failed";

			return [
				'success' => true,
				'message' => $syncMessage,
				'results' => array_merge(
					$results,
					[
						'total_time_seconds' => round($totalTime, 3),
						'overall_objects_per_second' => round($overallPerformance, 0),
						'estimated_improvement' => $estimatedImprovementValue,
					]
				),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Organisation sync failed',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			return [
				'success' => false,
				'message' => 'Organisation sync failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}//end try
	}//end syncOrganisationsToVoorzieningenOptimized()

	/**
	 * Determine organisation type based on organisation properties
	 *
	 * @param \OCA\OpenRegister\Db\Organisation $organisation The organisation entity.
	 *
	 * @return string The organisation type.
	 */
	private function determineOrganisationType(\OCA\OpenRegister\Db\Organisation $organisation): string {
		$name = strtolower($organisation->getName());

		if (strpos($name, 'gemeente') !== false) {
			return 'Municipality';
		}

		if (strpos($name, 'provincie') !== false) {
			return 'Provincie';
		}

		if (strpos($name, 'ministerie') !== false) {
			return 'Ministerie';
		} else {
			return 'Supplier';
			// Default.
		}
	}//end determineOrganisationType()

	// ======================================================.
	// CRONJOB CONFIGURATION METHODS.
	// ======================================================.

	/**
	 * Get all cronjob configurations.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 *
	 * @return array The cronjob configurations indexed by job name
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getCronjobConfig(): array {
		try {
			$configJson = $this->config->getValueString($this->appName, 'cronjob_config', '{}');
			$config = json_decode($configJson, true);

			if (is_array($config) === false) {
				$config = [];
			}

			// Define available cronjobs with their metadata.
			$availableCronjobs = $this->getAvailableCronjobs();

			// Merge stored config with defaults for each cronjob.
			$result = [];
			foreach ($availableCronjobs as $jobId => $jobMeta) {
				$result[$jobId] = [
					'id' => $jobId,
					'name' => $jobMeta['name'],
					'description' => $jobMeta['description'],
					'interval' => $jobMeta['interval'],
					'userId' => $config[$jobId]['userId'] ?? null,
					'organisationUuid' => $config[$jobId]['organisationUuid'] ?? null,
					'enabled' => $config[$jobId]['enabled'] ?? true,
				];
			}

			return [
				'success' => true,
				'cronjobs' => $result,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get cronjob config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get cronjob config: ' . $e->getMessage(),
				'cronjobs' => [],
			];
		}//end try
	}//end getCronjobConfig()

	/**
	 * Get list of available cronjobs with their metadata.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *
	 * @return array List of cronjob definitions
	 */
	private function getAvailableCronjobs(): array {
		return [
			'organization_contact_sync' => [
				'name' => 'Organization Contact Sync',
				'description' => 'Syncs organizations and contacts between Stackiq and OpenRegister.',
				'interval' => 300,
				// 5 minutes.
				'class' => 'OCA\\Stackiq\\BackgroundJob\\OrganizationContactSyncJob',
			],
		];
	}//end getAvailableCronjobs()

	/**
	 * Update cronjob configuration.
	 *
	 * @param array $data The cronjob configuration data.
	 *
	 * @return array Result of the update operation.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function updateCronjobConfig(array $data): array {
		try {
			// Get existing config.
			$configJson = $this->config->getValueString($this->appName, 'cronjob_config', '{}');
			$config = json_decode($configJson, true);

			if (is_array($config) === false) {
				$config = [];
			}

			// Update configuration for the specified cronjob.
			$jobId = $data['jobId'] ?? null;
			if ($jobId === null) {
				return [
					'success' => false,
					'message' => 'Job ID is required',
				];
			}

			// Validate that the job exists.
			$availableCronjobs = $this->getAvailableCronjobs();
			if (isset($availableCronjobs[$jobId]) === false) {
				return [
					'success' => false,
					'message' => 'Unknown cronjob: ' . $jobId,
				];
			}

			// Update the config for this job.
			$config[$jobId] = [
				'userId' => $data['userId'] ?? null,
				'organisationUuid' => $data['organisationUuid'] ?? null,
				'enabled' => $data['enabled'] ?? true,
			];

			// Save the updated config.
			$this->config->setValueString(
				$this->appName,
				'cronjob_config',
				json_encode($config, JSON_PRETTY_PRINT)
			);

			$this->logger->info(
				'Cronjob configuration updated',
				[
					'jobId' => $jobId,
					'userId' => $config[$jobId]['userId'],
					'organisationUuid' => $config[$jobId]['organisationUuid'],
				]
			);

			return [
				'success' => true,
				'message' => 'Cronjob configuration updated successfully',
				'config' => $config[$jobId],
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update cronjob config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update cronjob config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateCronjobConfig()

	/**
	 * Get cronjob context for a specific job.
	 *
	 * @param string $jobId The cronjob identifier.
	 *
	 * @return array|null The context configuration or null if not configured.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 * @spec       openspec/specs/settings-service/spec.md
	 */
	public function getCronjobContext(string $jobId): ?array {
		try {
			$configJson = $this->config->getValueString($this->appName, 'cronjob_config', '{}');
			$config = json_decode($configJson, true);

			if (is_array($config) === false || isset($config[$jobId]) === false) {
				return null;
			}

			$jobConfig = $config[$jobId];

			// Only return if both user and organisation are configured.
			if (empty($jobConfig['userId']) === true || empty($jobConfig['organisationUuid']) === true) {
				return null;
			}

			return [
				'userId' => $jobConfig['userId'],
				'organisationUuid' => $jobConfig['organisationUuid'],
				'enabled' => $jobConfig['enabled'] ?? true,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get cronjob context',
				[
					'jobId' => $jobId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end getCronjobContext()

	/**
	 * Get available users for cronjob configuration.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 *
	 * @return array List of users with id and display name
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAvailableUsersForCronjobs(): array {
		try {
			$userManager = $this->container->get(\OCP\IUserManager::class);
			$groupManager = $this->container->get(\OCP\IGroupManager::class);

			$users = [];

			// Get admin group users.
			$adminGroup = $groupManager->get('admin');
			if ($adminGroup !== null) {
				foreach ($adminGroup->getUsers() as $user) {
					$users[] = [
						'id' => $user->getUID(),
						'displayName' => $user->getDisplayName(),
						'email' => $user->getEMailAddress(),
					];
				}
			}

			// Also include users from super user groups if configured.
			$superUserGroups = $this->getSuperUserGroups();
			foreach ($superUserGroups as $groupName) {
				$group = $groupManager->get($groupName);
				if ($group !== null) {
					foreach ($group->getUsers() as $user) {
						// Avoid duplicates.
						$exists = array_filter($users, fn ($u) => $u['id'] === $user->getUID());
						if (empty($exists) === true) {
							$users[] = [
								'id' => $user->getUID(),
								'displayName' => $user->getDisplayName(),
								'email' => $user->getEMailAddress(),
							];
						}
					}
				}
			}

			return [
				'success' => true,
				'users' => $users,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get available users for cronjobs',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get available users: ' . $e->getMessage(),
				'users' => [],
			];
		}//end try
	}//end getAvailableUsersForCronjobs()

	/**
	 * Get available organisations for cronjob configuration.
	 *
	 * @deprecated Cronjob context is no longer needed since sync operations use _rbac: false.
	 *             Will be removed in a future version.
	 *
	 * @return array List of organisations with uuid and name
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAvailableOrganisationsForCronjobs(): array {
		try {
			if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
				return [
					'success' => false,
					'message' => 'OpenRegister is not installed',
					'organisations' => [],
				];
			}

			$organisationMapper = $this->container->get(\OCA\OpenRegister\Db\OrganisationMapper::class);

			// Get all organisations (bypass RBAC for admin access).
			$organisations = $organisationMapper->findAll(null, null, [], [], [], [], null, false, false);

			$result = [];
			foreach ($organisations as $org) {
				$result[] = [
					'uuid' => $org->getUuid(),
					'name' => $org->getName(),
					'description' => $org->getDescription(),
				];
			}

			return [
				'success' => true,
				'organisations' => $result,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get available organisations for cronjobs',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get available organisations: ' . $e->getMessage(),
				'organisations' => [],
			];
		}//end try
	}//end getAvailableOrganisationsForCronjobs()

	// ===.
	// EOL SYNC CONFIGURATION (eol-feed-integration).
	// ===.

	/**
	 * The IAppConfig key backing the EOL sync configuration blob.
	 *
	 * @var string
	 */
	private const EOL_SYNC_CONFIG_KEY = 'eol_sync_config';

	/**
	 * The IAppConfig key backing the EOL sync last-run status blob.
	 *
	 * @var string
	 */
	private const EOL_SYNC_STATUS_KEY = 'eol_sync_status';

	/**
	 * The register slug the sibling openconnector `endoflife-date-source`
	 * change provisions `eolProduct`/`eolCycle` into, used as the default
	 * when no admin override is configured.
	 *
	 * @var string
	 */
	private const EOL_DEFAULT_REGISTER = 'openconnector';

	/**
	 * The default `eolProduct` schema slug (design.md Decision 5).
	 *
	 * @var string
	 */
	private const EOL_DEFAULT_PRODUCT_SCHEMA = 'eolProduct';

	/**
	 * The default `eolCycle` schema slug (design.md Decision 5).
	 *
	 * @var string
	 */
	private const EOL_DEFAULT_CYCLE_SCHEMA = 'eolCycle';

	/**
	 * The default scheduled-sync interval in seconds (24 hours).
	 *
	 * @var integer
	 */
	private const EOL_DEFAULT_INTERVAL_SECONDS = 86400;

	/**
	 * Get the EOL sync configuration: whether the feature is enabled, the
	 * register/schema slugs to read `eolProduct`/`eolCycle` from, and the
	 * scheduled-sync interval. Defaults match what the sibling openconnector
	 * `endoflife-date-source` change provisions (design.md Decision 5) —
	 * the feature is disabled by default until an admin opts in.
	 *
	 * @return array{enabled: bool, register: string, productSchema: string, cycleSchema: string, intervalSeconds: int}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function getEolSyncConfig(): array {
		$configJson = $this->config->getValueString($this->appName, self::EOL_SYNC_CONFIG_KEY, '{}');
		$decoded = json_decode($configJson, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return [
			'enabled' => ($decoded['enabled'] ?? false) === true,
			'register' => (string)($decoded['register'] ?? self::EOL_DEFAULT_REGISTER),
			'productSchema' => (string)($decoded['productSchema'] ?? self::EOL_DEFAULT_PRODUCT_SCHEMA),
			'cycleSchema' => (string)($decoded['cycleSchema'] ?? self::EOL_DEFAULT_CYCLE_SCHEMA),
			'intervalSeconds' => (int)($decoded['intervalSeconds'] ?? self::EOL_DEFAULT_INTERVAL_SECONDS),
		];
	}//end getEolSyncConfig()

	/**
	 * Persist the EOL sync configuration. Unknown keys are ignored; missing
	 * keys keep their current value (partial updates are supported, unlike
	 * the OpenRegister object PUT semantics this config intentionally does
	 * NOT share — this is a flat IAppConfig blob, not an OR object).
	 *
	 * @param array $data The submitted configuration fields.
	 *
	 * @return array{success: bool, config: array} The persisted configuration.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	public function updateEolSyncConfig(array $data): array {
		$current = $this->getEolSyncConfig();

		if (array_key_exists('enabled', $data) === true) {
			$current['enabled'] = ($data['enabled'] === true || $data['enabled'] === 'true');
		}

		if (array_key_exists('register', $data) === true && trim((string)$data['register']) !== '') {
			$current['register'] = trim((string)$data['register']);
		}

		if (array_key_exists('productSchema', $data) === true && trim((string)$data['productSchema']) !== '') {
			$current['productSchema'] = trim((string)$data['productSchema']);
		}

		if (array_key_exists('cycleSchema', $data) === true && trim((string)$data['cycleSchema']) !== '') {
			$current['cycleSchema'] = trim((string)$data['cycleSchema']);
		}

		if (array_key_exists('intervalSeconds', $data) === true && (int)$data['intervalSeconds'] > 0) {
			$current['intervalSeconds'] = (int)$data['intervalSeconds'];
		}

		$this->config->setValueString($this->appName, self::EOL_SYNC_CONFIG_KEY, json_encode($current));

		return [
			'success' => true,
			'config' => $current,
		];
	}//end updateEolSyncConfig()

	/**
	 * Get the last-recorded EOL sync status: whether the feed is currently
	 * available, a reason when it is not, and the outcome counts of the most
	 * recent run. Defaults to an "unavailable / never run" status before the
	 * first run.
	 *
	 * @return array{available: bool, reason: string|null, matched: int, skipped: int, lastRunAt: string|null}
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function getEolSyncStatus(): array {
		$statusJson = $this->config->getValueString($this->appName, self::EOL_SYNC_STATUS_KEY, '{}');
		$decoded = json_decode($statusJson, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return [
			'available' => ($decoded['available'] ?? false) === true,
			'reason' => $decoded['reason'] ?? 'not-yet-run',
			'matched' => (int)($decoded['matched'] ?? 0),
			'skipped' => (int)($decoded['skipped'] ?? 0),
			'lastRunAt' => $decoded['lastRunAt'] ?? null,
		];
	}//end getEolSyncStatus()

	/**
	 * Persist the outcome of an EOL sync run (scheduled or manual).
	 *
	 * @param array $status The status fields (`available`, `reason`, `matched`, `skipped`, `lastRunAt`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
	 */
	public function setEolSyncStatus(array $status): void {
		$this->config->setValueString($this->appName, self::EOL_SYNC_STATUS_KEY, json_encode($status));
	}//end setEolSyncStatus()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * EXCEPTION (catalog-ratings, stackiq#375): any key literally named
	 * `authorization` switches its entire subtree to REPLACE semantics for list
	 * values, instead of the general concatenation above. Concatenating an RBAC
	 * rule list is a fail-OPEN trap: if the base already carries an unconditional
	 * entry such as `read: ["public"]`, concatenating a narrower overlay rule onto
	 * it produces `["public", {...}]` — the dangerous unconditional entry is still
	 * present, so the schema stays fully world-readable no matter what the overlay
	 * adds (the same class of bug as OR's veto-after-grant trap, or#2025, one layer
	 * up in the config-merge step). A fragment narrowing a schema's authorization
	 * MUST be able to remove a dangerous base entry outright, so `authorization`
	 * lists are replaced wholesale. This is scoped to that one key name — every
	 * other merge (including every fragment that predates this one) is unaffected.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 * @param bool $replaceLists Whether list values in this subtree replace
	 *                           (true, inside an `authorization` block)
	 *                           rather than concatenate (false, the general
	 *                           case).
	 *
	 * @return array<mixed> The merged config.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$replaceLists` is not a caller-facing mode
	 * switch — it is the recursion state of this private static helper. Public callers always
	 * enter with the default `false`; the flag is set to `true` only by the recursive call once
	 * an `authorization` key has been crossed, and it stays true for that whole subtree. Turning
	 * it into two methods would mean duplicating the merge for the sole purpose of removing a
	 * parameter that no external caller ever passes, and would make the fail-open trap this flag
	 * exists to close (stackiq#375) easier to reintroduce.
	 */
	private static function deepMergeConfig(array $base, array $overlay, bool $replaceLists = false): array {
		foreach ($overlay as $key => $value) {
			$childReplaceLists = ($replaceLists === true || $key === 'authorization');

			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					if ($childReplaceLists === true) {
						$base[$key] = $value;
					} else {
						$base[$key] = array_merge($base[$key], $value);
					}
				} else {
					$base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value, replaceLists: $childReplaceLists);
				}
			} else {
				$base[$key] = $value;
			}
		}//end foreach

		return $base;
	}//end deepMergeConfig()
}//end class
