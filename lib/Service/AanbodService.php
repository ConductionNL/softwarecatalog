<?php

/**
 * Aanbod Service for Stackiq.
 *
 * Handles operations related to aanbod (offers) which can be gebruik, dienst,
 * module, or koppeling objects where the active organization is involved as
 * either afnemer (consumer) or aanbieder (provider).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing aanbod (offers) operations.
 *
 * This service provides operations for querying aanbod objects (gebruik, dienst,
 * module, koppeling) where the active organization is involved either as the
 * afnemer (consumer) or aanbieder (provider), and for accepting or denying these offers.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class AanbodService {
	/**
	 * Constructor for AanbodService.
	 *
	 * @param IAppConfig $config Nextcloud app configuration service
	 * @param IAppManager $appManager App manager service for checking available apps
	 * @param ContainerInterface $container PSR-11 container interface for dependency injection
	 * @param LoggerInterface $logger Logger service for debugging and error reporting
	 * @param SettingsService $settingsService Settings service for retrieving configuration
	 * @param IUserSession $userSession User session service for current user context
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Get all aanbod objects (modules, diensten, koppelingen, gebruiks).
	 *
	 * Returns modules, diensten, and koppelingen where the current organisation
	 * is in the aanbieder property, or gebruiks where the current organisation
	 * is in the afnemer property. Excludes objects where @self.organisation
	 * equals the current organisation (already accepted).
	 *
	 * @param array $options Additional query options (limit, offset, filters, etc.)
	 *
	 * @return array Array with success status, aanbod objects data, and metadata
	 *
	 * @throws Exception When OpenRegister service is not available
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getAanbod(array $options = []): array {
		$this->logger->info(
			'Getting aanbod objects for active organisation',
			[
				'options' => $options,
			]
		);

		try {
			// Get ObjectService from OpenRegister.
			$objectService = $this->getObjectService();

			// Get current organization.
			$currentOrg = $this->getCurrentOrganisation();
			if ($currentOrg === null) {
				$this->logger->warning('No current organization available for aanbod filtering');
				return [
					'results' => [],
					'total' => 0,
					'page' => 1,
					'pages' => 0,
					'limit' => 20,
					'offset' => 0,
					'message' => 'No current organization available',
				];
			}

			// Get voorzieningen configuration.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzieningenConfig['register'] ?? null;
			$gebruikSchema = $voorzieningenConfig['gebruik_schema'] ?? null;
			$integrationSchema = $voorzieningenConfig['koppeling_schema'] ?? null;
			$moduleSchema = $voorzieningenConfig['module_schema'] ?? null;
			$dienstSchema = $voorzieningenConfig['dienst_schema'] ?? null;

			if ($registerId === null) {
				throw new Exception('Voorzieningen register not configured');
			}

			$allResults = [];
			$schemasToSearch = [];

			// Collect all schemas we need to search.
			if ($gebruikSchema !== null) {
				$schemasToSearch[] = ['schema' => $gebruikSchema, 'type' => 'usage', 'filter_field' => 'consumer'];
			}

			if ($integrationSchema !== null) {
				$schemasToSearch[] = ['schema' => $integrationSchema, 'type' => 'connection', 'filter_field' => 'provider'];
			}

			if ($moduleSchema !== null) {
				$schemasToSearch[] = ['schema' => $moduleSchema, 'type' => 'module', 'filter_field' => 'provider'];
			}

			if ($dienstSchema !== null) {
				$schemasToSearch[] = ['schema' => $dienstSchema, 'type' => 'catalogService', 'filter_field' => 'provider'];
			}

			// Search each schema type.
			foreach ($schemasToSearch as $schemaConfig) {
				try {
					$query = [
						'@self' => [
							'register' => $registerId,
							'schema' => $schemaConfig['schema'],
						],
						$schemaConfig['filter_field'] => $currentOrg,
					];

					// Add pagination and other filters from options.
					$query = $this->addQueryFilters(baseQuery: $query, options: $options);

					$this->logger->debug(
						'Searching aanbod objects',
						[
							'schema' => $schemaConfig['schema'],
							'type' => $schemaConfig['type'],
							'filter_field' => $schemaConfig['filter_field'],
							'current_org' => $currentOrg,
						]
					);

					// Execute search with RBAC and multitenancy disabled to find cross-organisation objects.
					$searchResult = $objectService->searchObjectsPaginated(
						query: $query,
						_rbac: false,
						_multitenancy: false
					);

					// Filter out objects where @self.organisation equals current org.
					foreach ($searchResult['results'] ?? [] as $result) {
						// Use jsonSerialize() instead of getObject() to include @self metadata.
						// GetObject() only returns raw object data without @self.organisation.
						$resultData = $result;
						if (is_array($result) === false) {
							$resultData = $result->jsonSerialize();
						}

						$selfOrg = $resultData['@self']['organisation'] ?? null;

						// Only include if @self.organisation is NOT set to the current organisation.
						if ($selfOrg !== $currentOrg) {
							// Add type information to result.
							$resultData['_aanbod_type'] = $schemaConfig['type'];
							$allResults[] = $resultData;
						}
					}

					$this->logger->debug(
						'Found aanbod objects for schema',
						[
							'schema' => $schemaConfig['schema'],
							'type' => $schemaConfig['type'],
							'count' => count($searchResult['results'] ?? []),
						]
					);
				} catch (Exception $e) {
					$this->logger->warning(
						'Failed to get aanbod objects from schema',
						[
							'schema' => $schemaConfig['schema'],
							'type' => $schemaConfig['type'],
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach

			// Apply pagination to combined results.
			$requestedLimit = $options['_limit'] ?? $options['limit'] ?? 20;
			$requestedPage = $options['_page'] ?? 1;

			$requestedOffset = (($requestedPage - 1) * $requestedLimit);
			if (isset($options['_offset']) === true) {
				$requestedOffset = (int)$options['_offset'];
			}

			$totalFiltered = count($allResults);
			$paginatedResults = array_slice($allResults, $requestedOffset, $requestedLimit);

			$totalPages = 1;
			if ($requestedLimit > 0) {
				$totalPages = (int)ceil($totalFiltered / $requestedLimit);
			}

			return [
				'results' => $paginatedResults,
				'total' => $totalFiltered,
				'page' => $requestedPage,
				'pages' => $totalPages,
				'limit' => $requestedLimit,
				'offset' => $requestedOffset,
			];
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to get aanbod objects',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'results' => [],
				'total' => 0,
				'page' => 1,
				'pages' => 0,
				'limit' => 20,
				'offset' => 0,
				'error' => 'Failed to retrieve aanbod: ' . $e->getMessage(),
			];
		}//end try
	}//end getAanbod()

	/**
	 * Accept an aanbod object (set @self.organisation to current organisation).
	 *
	 * This method updates the @self.organisation property of an aanbod object
	 * to the active organization, but only if the active organization is either
	 * the afnemer (for gebruiks) or aanbieder (for modules, diensten, koppelingen).
	 *
	 * @param string $aanbodId The UUID of the aanbod object to accept
	 * @param array $options Additional update options
	 *
	 * @return array Result with success status and updated object data
	 *
	 * @throws Exception When OpenRegister service is not available or operation fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function acceptAanbod(string $aanbodId, array $options = []): array {
		$this->logger->info(
			'Accepting aanbod object',
			[
				'aanbod_id' => $aanbodId,
				'options' => $options,
			]
		);

		try {
			// Validate input.
			if (empty($aanbodId) === true) {
				return [
					'success' => false,
					'error' => 'Aanbod ID is required',
					'aanbod' => null,
				];
			}

			// Get ObjectService from OpenRegister.
			$objectService = $this->getObjectService();

			// Get current organization.
			$currentOrg = $this->getCurrentOrganisation();
			if ($currentOrg === null) {
				return [
					'success' => false,
					'error' => 'No current organization available',
					'aanbod' => null,
				];
			}

			// Find the aanbod object across all possible schemas (gebruik, koppeling, module, dienst).
			$existingAanbod = $this->findAanbodObject(
				objectService: $objectService,
				aanbodId: $aanbodId
			);

			if ($existingAanbod === null) {
				return [
					'success' => false,
					'error' => 'Aanbod object not found',
					'aanbod' => null,
				];
			}

			// Verify that the active organization is either afnemer or aanbieder.
			$aanbodData = $existingAanbod->getObject();
			$consumerInfo = $aanbodData['consumer'] ?? null;
			$providerInfo = $aanbodData['provider'] ?? null;
			$consumerId = $this->resolvePartyId(partyInfo: $consumerInfo);
			$providerId = $this->resolvePartyId(partyInfo: $providerInfo);
			$isConsumer = ($consumerId !== null && $consumerId === $currentOrg);
			$isProvider = ($providerId !== null && $providerId === $currentOrg);

			if ($isConsumer === false && $isProvider === false) {
				return [
					'success' => false,
					'error' => 'Operation not allowed: active organization is not the afnemer or aanbieder',
					'aanbod' => null,
					'debug' => [
						'afnemer_in_object' => $consumerInfo,
						'resolved_afnemer_id' => $consumerId,
						'aanbieder_in_object' => $providerInfo,
						'resolved_aanbieder_id' => $providerId,
						'current_org' => $currentOrg,
					],
				];
			}

			// Update the @self.organisation and @self.owner properties.
			// Both must be set so the accepting user has permission to read/update the object.
			$currentUser = $this->userSession->getUser();
			$selfData = ['organisation' => $currentOrg];
			if ($currentUser !== null) {
				$selfData['owner'] = $currentUser->getUID();
			}

			$aanbodData['@self'] = $selfData;

			// Update registeredBy based on the accepting organisation's type.
			$aanbodData = $this->updateRegisteredBy(
				objectService: $objectService,
				objectData: $aanbodData,
				organisationUuid: $currentOrg
			);

			// Save the updated object with RBAC and multitenancy disabled.
			// The PAYLOAD is what is sent — `setObject()` on the entity is not on
			// the published ObjectEntityInterface (ADR-084) and the mutated copy
			// was never read back anyway; `saveObject()` is PUT-semantic, so
			// $aanbodData already carries every unchanged field forward.
			$updatedAanbod = $objectService->saveObject(
				object: $aanbodData,
				register: $existingAanbod->getRegister(),
				schema: $existingAanbod->getSchema(),
				uuid: $aanbodId,
				_rbac: false,
				_multitenancy: false
			);

			$this->logger->info(
				'Successfully accepted aanbod object',
				[
					'aanbod_id' => $aanbodId,
					'organisation' => $currentOrg,
					'owner' => $currentUser?->getUID(),
					'is_afnemer' => $isConsumer,
					'is_aanbieder' => $isProvider,
				]
			);

			return [
				'success' => true,
				'message' => 'Aanbod object accepted successfully',
				'aanbod' => $updatedAanbod->getObject(),
				'updated_fields' => ['@self.organisation', '@self.owner'],
			];
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to accept aanbod object',
				[
					'aanbod_id' => $aanbodId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'success' => false,
				'error' => 'Failed to accept aanbod: ' . $e->getMessage(),
				'aanbod' => null,
			];
		}//end try
	}//end acceptAanbod()

	/**
	 * Deny an aanbod object (delete it).
	 *
	 * This method deletes an aanbod object, but only if the active organization
	 * is either the afnemer (for gebruiks) or aanbieder (for modules, diensten, koppelingen).
	 *
	 * @param string $aanbodId The UUID of the aanbod object to deny
	 * @param array $options Additional options for the operation
	 *
	 * @return array Result array with success status and details
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function denyAanbod(string $aanbodId, array $options = []): array {
		$this->logger->info(
			'Denying aanbod object',
			[
				'aanbod_id' => $aanbodId,
				'options' => $options,
			]
		);

		try {
			// Validate input.
			if (empty($aanbodId) === true) {
				return [
					'success' => false,
					'error' => 'Aanbod ID is required',
					'deleted' => false,
				];
			}

			// Get ObjectService from OpenRegister.
			$objectService = $this->getObjectService();

			// Get current organization.
			$currentOrg = $this->getCurrentOrganisation();
			if ($currentOrg === null) {
				return [
					'success' => false,
					'error' => 'No current organization available',
					'deleted' => false,
				];
			}

			// Find the aanbod object across all possible schemas (gebruik, koppeling, module, dienst).
			$existingAanbod = $this->findAanbodObject(
				objectService: $objectService,
				aanbodId: $aanbodId
			);

			if ($existingAanbod === null) {
				return [
					'success' => false,
					'error' => 'Aanbod object not found',
					'deleted' => false,
				];
			}

			$aanbodData = $existingAanbod->getObject();

			// SECURITY CHECK: Verify that the active organization is either afnemer or aanbieder.
			$consumerInfo = $aanbodData['consumer'] ?? null;
			$providerInfo = $aanbodData['provider'] ?? null;
			$consumerId = $this->resolvePartyId(partyInfo: $consumerInfo);
			$providerId = $this->resolvePartyId(partyInfo: $providerInfo);
			$isConsumer = ($consumerId !== null && $consumerId === $currentOrg);
			$isProvider = ($providerId !== null && $providerId === $currentOrg);

			if ($isConsumer === false && $isProvider === false) {
				$this->logger->warning(
					'Unauthorized delete attempt - user is not afnemer or aanbieder',
					[
						'aanbod_id' => $aanbodId,
						'current_org' => $currentOrg,
						'afnemer_in_object' => $consumerInfo,
						'resolved_afnemer_id' => $consumerId,
						'aanbieder_in_object' => $providerInfo,
						'resolved_aanbieder_id' => $providerId,
					]
				);

				return [
					'success' => false,
					'error' => 'Operation not allowed: active organization is not the afnemer or aanbieder',
					'deleted' => false,
					'debug' => [
						'afnemer_in_object' => $consumerInfo,
						'resolved_afnemer_id' => $consumerId,
						'aanbieder_in_object' => $providerInfo,
						'resolved_aanbieder_id' => $providerId,
						'current_org' => $currentOrg,
					],
				];
			}//end if

			// Delete the object with RBAC and multitenancy disabled.
			$objectService->setRegister(register: $existingAanbod->getRegister());
			$objectService->setSchema(schema: $existingAanbod->getSchema());

			$deleteResult = $objectService->deleteObject(
				uuid: $aanbodId,
				_rbac: false,
				_multitenancy: false
			);

			$this->logger->info(
				'Successfully denied aanbod object',
				[
					'aanbod_id' => $aanbodId,
					'organisation' => $currentOrg,
					'is_afnemer' => $isConsumer,
					'is_aanbieder' => $isProvider,
				]
			);

			return [
				'success' => true,
				'message' => 'Aanbod object denied successfully',
				'deleted' => true,
				'aanbod_id' => $aanbodId,
				'organisation' => $currentOrg,
			];
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to deny aanbod object',
				[
					'aanbod_id' => $aanbodId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'success' => false,
				'error' => 'Failed to deny aanbod: ' . $e->getMessage(),
				'deleted' => false,
			];
		}//end try
	}//end denyAanbod()

	/**
	 * Resolve an afnemer / aanbieder party id from its polymorphic shape.
	 *
	 * Aanbod payloads store party references either as a `['id' => '…']`
	 * tuple (relation expansion) or as the raw UUID string. Returns null
	 * for any other shape.
	 *
	 * @param mixed $partyInfo The raw party value from the aanbod payload
	 *
	 * @return string|null The resolved party id, or null when not present
	 */
	private function resolvePartyId(mixed $partyInfo): ?string {
		if (is_array($partyInfo) === true && isset($partyInfo['id']) === true) {
			return (string)$partyInfo['id'];
		}

		if (is_string($partyInfo) === true && $partyInfo !== '') {
			return $partyInfo;
		}

		return null;
	}//end resolvePartyId()

	/**
	 * Find an aanbod object by UUID across all possible schemas.
	 *
	 * Searches gebruik, koppeling, module, and dienst schemas because
	 * an aanbod can be any of these types. Register/schema context is
	 * required to find objects stored in magic tables.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param string $aanbodId The UUID of the aanbod object
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface|null The found object or null
	 */
	private function findAanbodObject(
		ObjectServiceInterface $objectService,
		string $aanbodId,
	): ?\OCA\OpenRegister\Contract\ObjectEntityInterface {
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;

		if ($registerId === null) {
			$this->logger->warning('Cannot find aanbod object: no register configured');
			return null;
		}

		// Collect all schema IDs to search across.
		$schemasToTry = array_filter(
			[
				$voorzieningenConfig['gebruik_schema'] ?? null,
				$voorzieningenConfig['koppeling_schema'] ?? null,
				$voorzieningenConfig['module_schema'] ?? null,
				$voorzieningenConfig['dienst_schema'] ?? null,
			]
		);

		foreach ($schemasToTry as $schemaId) {
			try {
				$object = $objectService->find(
					id: $aanbodId,
					register: $registerId,
					schema: $schemaId,
					_rbac: false,
					_multitenancy: false
				);
				if ($object !== null) {
					return $object;
				}
			} catch (Exception $e) {
				// Object not found in this schema, try next.
				continue;
			}
		}

		$this->logger->warning(
			'Aanbod object not found in any schema',
			[
				'aanbod_id' => $aanbodId,
				'schemas_tried' => $schemasToTry,
			]
		);

		return null;
	}//end findAanbodObject()

	/**
	 * Get current active organisation for filtering.
	 *
	 * @return string|null Current organisation identifier or null if no user session
	 */
	private function getCurrentOrganisation(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		try {
			// Get the OpenRegister OrganisationService to get the active organisation.
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$activeOrg = $organisationService->getActiveOrganisation();

			if ($activeOrg !== null) {
				return $activeOrg->getUuid();
			}

			return null;
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to get current organisation from OpenRegister',
				[
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end getCurrentOrganisation()

	/**
	 * Get ObjectService from OpenRegister app.
	 *
	 * @return ObjectServiceInterface The OpenRegister object service
	 *
	 * @throws Exception When OpenRegister service is not available
	 */
	private function getObjectService(): ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Exception $e) {
			throw new Exception('Failed to get OpenRegister service: ' . $e->getMessage());
		}
	}//end getObjectService()

	/**
	 * Add query filters from options to the base query.
	 *
	 * @param array $baseQuery The base query to extend
	 * @param array $options Filter options to apply
	 *
	 * @return array Extended query with additional filters
	 */
	private function addQueryFilters(array $baseQuery, array $options): array {
		// Add limit if specified.
		$limit = $options['_limit'] ?? $options['limit'] ?? null;
		if ($limit !== null && is_numeric($limit) === true) {
			$baseQuery['_limit'] = (int)$limit;
		}

		// Add offset if specified.
		$offset = $options['_offset'] ?? $options['offset'] ?? null;
		if ($offset !== null && is_numeric($offset) === true) {
			$baseQuery['_offset'] = (int)$offset;
		}

		// Add page if specified.
		if (isset($options['_page']) === true && is_numeric($options['_page']) === true) {
			$baseQuery['_page'] = (int)$options['_page'];
		}

		// Add source parameter if specified.
		if (isset($options['_source']) === true && empty($options['_source']) === false) {
			$baseQuery['_source'] = $options['_source'];
		}

		return $baseQuery;
	}//end addQueryFilters()

	/**
	 * Mapping from organisatie.type to registeredBy value.
	 */
	private const TYPE_MAP = [
		'Municipality' => 'Municipality',
		'Supplier' => 'Supplier',
		'Collaboration' => 'Collaboration',
		'Community' => 'Community',
	];

	/**
	 * Update registeredBy on object data based on the organisation's type.
	 *
	 * Looks up the organisation object by UUID, reads its type, and maps it
	 * to the appropriate registeredBy value using TYPE_MAP.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param array $objectData The object data to update
	 * @param string $organisationUuid The UUID of the organisation to look up
	 *
	 * @return array The updated object data
	 */
	private function updateRegisteredBy(
		ObjectServiceInterface $objectService,
		array $objectData,
		string $organisationUuid,
	): array {
		try {
			$organisationSchemaId = $this->settingsService->getSchemaIdForObjectType('organization');
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzieningenConfig['register'] ?? null;

			if ($organisationSchemaId === null || $registerId === null) {
				return $objectData;
			}

			$organisationObject = $objectService->find(
				id: $organisationUuid,
				register: (int)$registerId,
				schema: (int)$organisationSchemaId,
				_rbac: false,
				_multitenancy: false
			);

			if ($organisationObject === null) {
				return $objectData;
			}

			$organisationData = $organisationObject->getObject();
			$orgType = $organisationData['type'] ?? null;

			if ($orgType !== null && isset(self::TYPE_MAP[$orgType]) === true) {
				$objectData['registeredBy'] = self::TYPE_MAP[$orgType];

				$this->logger->info(
					'Updated registeredBy during transfer',
					[
						'organisationUuid' => $organisationUuid,
						'orgType' => $orgType,
						'registeredBy' => self::TYPE_MAP[$orgType],
					]
				);
			}
		} catch (Exception $e) {
			$this->logger->warning(
				'Failed to update registeredBy during transfer',
				[
					'organisationUuid' => $organisationUuid,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $objectData;
	}//end updateGeregistreerdDoor()
}//end class
