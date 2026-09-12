<?php

/**
 * Stackiq Application
 *
 * This file contains the main application class for the Stackiq app.
 *
 * @category  Application
 * @package   OCA\Stackiq\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\AppInfo;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationService as OpenRegisterOrganisationService;
use OCA\Stackiq\BackgroundJob\ContractStatusJob;
use OCA\Stackiq\BackgroundJob\EolSyncJob;
use OCA\Stackiq\BackgroundJob\FederationSyncJob;
use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\Dashboard\ConceptOrganisatiesWidget;
use OCA\Stackiq\EventListener\DecisionConcludedListener;
use OCA\Stackiq\EventListener\ModuleComplianceSubscriber;
use OCA\Stackiq\EventListener\ModuleRegistrationSubscriber;
use OCA\Stackiq\EventListener\TestEventListener;
use OCA\Stackiq\EventListener\UserProfileUpdatedEventListener;
use OCA\Stackiq\Service\ArchiMateExportService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ArchiMateService;
use OCA\Stackiq\Service\ContactpersoonService;
use OCA\Stackiq\Service\ContractApprovalService;
use OCA\Stackiq\Service\ContractStatusService;
use OCA\Stackiq\Service\EolMatcherService;
use OCA\Stackiq\Service\EolSyncService;
use OCA\Stackiq\Service\FacetService;
use OCA\Stackiq\Service\Federation\FederationConfig;
use OCA\Stackiq\Service\Federation\FederationMerger;
use OCA\Stackiq\Service\Federation\FederationService;
use OCA\Stackiq\Service\GebruikSyncService;
use OCA\Stackiq\Service\IntakeService;
use OCA\Stackiq\Service\MergeOrganisatieService;
use OCA\Stackiq\Service\ModerationService;
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\OrganisatieService;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\PublicationService;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCA\Stackiq\Service\SbomImportService;
use OCA\Stackiq\Service\SbomParserService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Stackiq\ContactPersonHandler;
use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCA\Stackiq\Service\Stackiq\HierarchyHandler;
use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCA\Stackiq\Service\StackiqContactSyncService;
use OCA\Stackiq\Service\SymfonyEmailService;
use OCA\Stackiq\Service\ViewQueryBuilder;
use OCA\Stackiq\Service\ViewService;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main Application class for Stackiq
 *
 * @category Application
 * @package  OCA\Stackiq\AppInfo
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class Application extends App implements IBootstrap {
	/**
	 * The application ID
	 */
	public const APP_ID = 'stackiq';

	/**
	 * Application constructor
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function register(IRegistrationContext $context): void {

		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);
		include_once __DIR__ . '/../../vendor/autoload.php';

		$this->registerHandlerServices(context: $context);

		// Wire up event-listener bindings — extracted to a single
		// single-responsibility helper per
		// `openspec/changes/method-decomposition/tasks.md` task 9.1.
		$this->registerEventListeners(context: $context);

		$this->registerDomainServices(context: $context);
	}//end register()

	/**
	 * Wire the four Stackiq handler services as DI bindings.
	 *
	 * Single-responsibility helper extracted from `register()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.1.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
	 */
	private function registerHandlerServices(IRegistrationContext $context): void {
		$context->registerService(
			'OCA\Stackiq\Service\Stackiq\OrganizationHandler',
			function (ContainerInterface $c) {
				return new OrganizationHandler(
					_groupManager: $c->get(IGroupManager::class),
					_userManager: $c->get(IUserManager::class),
					_container: $c,
					_appManager: $c->get(IAppManager::class),
					_logger: $c->get(LoggerInterface::class)
				);
			}
		);

		$context->registerService(
			'OCA\Stackiq\Service\Stackiq\ContactPersonHandler',
			function (ContainerInterface $c) {
				return new ContactPersonHandler(
					_userManager: $c->get(IUserManager::class),
					_secureRandom: $c->get(ISecureRandom::class),
					_groupManager: $c->get(IGroupManager::class),
					_config: $c->get(IAppConfig::class),
					_container: $c,
					_appManager: $c->get(IAppManager::class),
					_logger: $c->get(LoggerInterface::class),
					_emailService: $c->get(SymfonyEmailService::class),
					config: $c->get(IConfig::class)
				);
			}
		);

		$context->registerService(
			'OCA\Stackiq\Service\Stackiq\GroupHandler',
			function (ContainerInterface $c) {
				return new GroupHandler(
					_groupManager: $c->get(IGroupManager::class),
					_userManager: $c->get(IUserManager::class),
					_appConfig: $c->get(IAppConfig::class),
					_container: $c,
					_appManager: $c->get(IAppManager::class),
					_logger: $c->get(LoggerInterface::class)
				);
			}
		);

		$context->registerService(
			'OCA\Stackiq\Service\Stackiq\HierarchyHandler',
			function (ContainerInterface $c) {
				return new HierarchyHandler(
					_organizationHandler: $c->get('OCA\Stackiq\Service\Stackiq\OrganizationHandler'),
					_contactPersonHandler: $c->get('OCA\Stackiq\Service\Stackiq\ContactPersonHandler'),
					_logger: $c->get(LoggerInterface::class),
					_userManager: $c->get(IUserManager::class),
					_groupManager: $c->get(IGroupManager::class)
				);
			}
		);

	}//end registerHandlerServices()

	/**
	 * Wire all domain-level services (sync/email/settings/ArchiMate/etc.).
	 *
	 * Single-responsibility helper extracted from `register()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.1.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) 498 lines of flat, branch-free DI
	 * wiring — one `registerService()` closure per domain service. The length is genuine
	 * and this method SHOULD be split into per-domain registrars the way
	 * `registerHandlerServices()` already was; that split is a deliberate follow-up and is
	 * deferred here only because it is a behaviour change, not a quality-gate change.
	 */
	private function registerDomainServices(IRegistrationContext $context): void {
		// Organization event listeners removed - now using cron job for organization synchronization.
		// Contact person event listeners are still active for real-time processing.
		// Register new focused services.
		$context->registerService(
			OrganisatieService::class,
			function ($container) {
				return new OrganisatieService(
					organizationHandler: $container->get(
						OrganizationHandler::class
					),
					logger: $container->get('Psr\Log\LoggerInterface'),
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
					config: $container->get(IAppConfig::class),
					userManager: $container->get(IUserManager::class),
					emailService: $container->get(SymfonyEmailService::class),
				);
			}
		);

		// Register the organisation-merge service (VNG Softwarecatalogus #141 —
		// gemeentelijke herindeling / leveranciersovername).
		$context->registerService(
			MergeOrganisatieService::class,
			function ($container) {
				return new MergeOrganisatieService(
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
					groupManager: $container->get(IGroupManager::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					eventDispatcher: $container->get(IEventDispatcher::class),
					settingsService: $container->get(SettingsService::class),
					organisationService: $container->get(OrganisatieService::class),
					progressTracker: $container->get(ProgressTracker::class),
					organizationHandler: $container->get(OrganizationHandler::class),
				);
			}
		);

		$context->registerService(
			ContactpersoonService::class,
			function ($container) {
				return new ContactpersoonService(
					contactPersonHandler: $container->get(
						ContactPersonHandler::class
					),
					groupHandler: $container->get(
						GroupHandler::class
					),
					hierarchyHandler: $container->get(
						HierarchyHandler::class
					),
					logger: $container->get('Psr\Log\LoggerInterface'),
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
					config: $container->get(IAppConfig::class),
					settingsService: $container->get(SettingsService::class)
				);
			}
		);

		// Register email service.
		$context->registerService(
			SymfonyEmailService::class,
			function ($container) {
				return new SymfonyEmailService(
					config: $container->get(IAppConfig::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class)
				);
			}
		);

		// Register settings service.
		$context->registerService(
			SettingsService::class,
			function ($container) {
				return new SettingsService(
					config: $container->get(IAppConfig::class),
					request: $container->get('OCP\IRequest'),
					container: $container,
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					groupManager: $container->get(IGroupManager::class),
					l10n: $container->get('OCP\IL10N')
				);
			}
		);

		// Register organization sync service.
		$context->registerService(
			OrganizationSyncService::class,
			function ($container) {
				return new OrganizationSyncService(
					organisationService: $container->get(OrganisatieService::class),
					contactPersonService: $container->get(ContactpersoonService::class),
					emailService: $container->get(SymfonyEmailService::class),
					config: $container->get(IAppConfig::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					db: $container->get(IDBConnection::class),
					contactpersonHandler: $container->get(ContactPersonHandler::class),
					container: $container,
					// ADR-084: the published contract, resolved through the alias
					// registered above, not the concrete OpenRegister class.
					objectService: $container->get(ObjectServiceInterface::class),
					organisationMapper: $container->get('OCA\OpenRegister\Db\OrganisationMapper'),
				);
			}
		);

		// Register the Nextcloud-Contacts bridge (identity → NC addressbook,
		// relationship records keyed by contactsUid; ADR-019/ADR-022).
		$context->registerService(
			StackiqContactSyncService::class,
			function ($container) {
				return new StackiqContactSyncService(
					contactsManager: $container->get('OCP\Contacts\IManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register gebruik sync service.
		$context->registerService(
			GebruikSyncService::class,
			function ($container) {
				return new GebruikSyncService(
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					objectService: $container->get(ObjectServiceInterface::class),
				);
			}
		);

		// Event listener uses direct service access like OpenCatalogi - no service registration needed.
		// Register module compliance service.
		$context->registerService(
			ModuleComplianceService::class,
			function ($container) {
				return new ModuleComplianceService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register module registration service (auto-sets registeredBy).
		$context->registerService(
			ModuleRegistrationService::class,
			function ($container) {
				return new ModuleRegistrationService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register open-data publication service (sets/clears publicatiedatum — the
		// live OR RBAC publish gate; no @self.published, no app-local flag).
		$context->registerService(
			PublicationService::class,
			function ($container) {
				return new PublicationService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the anonymous-registration intake service (queues submissions
		// as registratiestatus=pending, no publicatiedatum — invisible until approved).
		$context->registerService(
			IntakeService::class,
			function ($container) {
				return new IntakeService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the registration/review moderation/approval-queue service
		// (generalised to also moderate beoordeeling — stackiq#375).
		$context->registerService(
			ModerationService::class,
			function ($container) {
				return new ModerationService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the authenticated review-submission service (catalog-ratings,
		// stackiq#375). Author identity comes from IUserSession, never
		// from client input.
		$context->registerService(
			ReviewService::class,
			function ($container) {
				return new ReviewService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					userSession: $container->get(\OCP\IUserSession::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the public approved-only review aggregate/read service
		// (catalog-ratings, stackiq#375) — split from ReviewService
		// to keep each class under the complexity budget.
		$context->registerService(
			ReviewAggregateService::class,
			function ($container) {
				return new ReviewAggregateService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register module version service (creates default 1.0.0 version for new modules).
		$context->registerService(
			ModuleVersionService::class,
			function ($container) {
				return new ModuleVersionService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register the pure SBOM parser (no OR/HTTP dependency — ADR-008).
		$context->registerService(
			SbomParserService::class,
			function () {
				return new SbomParserService();
			}
		);

		// Register the SBOM import orchestrator (parse → replace previous
		// component set → bulk-save new set → record provenance).
		$context->registerService(
			SbomImportService::class,
			function ($container) {
				return new SbomImportService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					parser: $container->get(SbomParserService::class),
					progressTracker: $container->get(ProgressTracker::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register ArchiMate import service.
		$context->registerService(
			ArchiMateImportService::class,
			function ($container) {
				return new ArchiMateImportService(
					config: $container->get(IAppConfig::class),
					rootFolder: $container->get('OCP\Files\IRootFolder'),
					userSession: $container->get('OCP\IUserSession'),
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					organisationService: $container->get(OpenRegisterOrganisationService::class),
					dbConnection: $container->get(IDBConnection::class)
				);
			}
		);

		// Register ArchiMate export service.
		$context->registerService(
			ArchiMateExportService::class,
			function ($container) {
				return new ArchiMateExportService(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register ArchiMate import/export service.
		$context->registerService(
			ArchiMateService::class,
			function ($container) {
				return new ArchiMateService(
					config: $container->get(IAppConfig::class),
					rootFolder: $container->get('OCP\Files\IRootFolder'),
					userSession: $container->get('OCP\IUserSession'),
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					importService: $container->get(ArchiMateImportService::class),
					exportService: $container->get(ArchiMateExportService::class)
				);
			}
		);

		// Register View service for ArchiMate views with enrichment capabilities.
		$context->registerService(
			ViewService::class,
			function ($container) {
				return new ViewService(
					config: $container->get(IAppConfig::class),
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					userSession: $container->get('OCP\IUserSession'),
					cacheFactory: $container->get(ICacheFactory::class)
				);
			}
		);

		// Register Facet service for GEMMA-dimension facet aggregation
		// (gemma-faceted-search) — mirrors ViewService's cache-factory wiring.
		$context->registerService(
			FacetService::class,
			function ($container) {
				return new FacetService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					archiMateService: $container->get(ArchiMateService::class),
					queryBuilder: $container->get(ViewQueryBuilder::class),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					cacheFactory: $container->get(ICacheFactory::class)
				);
			}
		);

		// Register progress tracking service.
		$context->registerService(
			ProgressTracker::class,
			function ($container) {
				return new ProgressTracker(
					session: $container->get('OCP\ISession'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Register background job for organization contact synchronization.
		$context->registerService(
			OrganizationContactSyncJob::class,
			function ($container) {
				return new OrganizationContactSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					orgSyncService: $container->get(OrganizationSyncService::class),
					contactSync: $container->get(StackiqContactSyncService::class),
					settingsService: $container->get(SettingsService::class),
					appManager: $container->get(IAppManager::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		// Register the contract-status maintenance service + its daily job.
		$context->registerService(
			ContractStatusService::class,
			function ($container) {
				return new ContractStatusService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);
		$context->registerService(
			ContractStatusJob::class,
			function ($container) {
				return new ContractStatusJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					statusService: $container->get(ContractStatusService::class),
					appManager: $container->get(IAppManager::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		// Register the contract-approval delegation service. Delegation runs
		// in-process via decidesk's DecisionRequestedEvent / DecisionConcludedEvent
		// contract (IEventDispatcher) — no HTTP client, no polling reconcile job.
		$context->registerService(
			ContractApprovalService::class,
			function ($container) {
				return new ContractApprovalService(
					container: $container,
					settingsService: $container->get(SettingsService::class),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		// Register the federation config + service + scheduled job.
		$context->registerService(
			FederationConfig::class,
			function ($container) {
				return new FederationConfig(appConfig: $container->get(IAppConfig::class));
			}
		);
		$context->registerService(
			FederationMerger::class,
			function () {
				return new FederationMerger();
			}
		);
		$context->registerService(
			FederationService::class,
			function ($container) {
				return new FederationService(
					container: $container,
					appManager: $container->get(IAppManager::class),
					config: $container->get(FederationConfig::class),
					merger: $container->get(FederationMerger::class),
					settingsService: $container->get(SettingsService::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);
		$context->registerService(
			FederationSyncJob::class,
			function ($container) {
				return new FederationSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					federation: $container->get(FederationService::class),
					config: $container->get(FederationConfig::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		// Register the EOL matcher + sync orchestration + scheduled job
		// (eol-feed-integration). EolMatcherService has zero OCP dependencies
		// by design (design.md "Nextcloud Integration" — pure matching logic).
		$context->registerService(
			EolMatcherService::class,
			function () {
				return new EolMatcherService();
			}
		);
		$context->registerService(
			EolSyncService::class,
			function ($container) {
				return new EolSyncService(
					settingsService: $container->get(SettingsService::class),
					matcher: $container->get(EolMatcherService::class),
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);
		$context->registerService(
			EolSyncJob::class,
			function ($container) {
				return new EolSyncJob(
					timeFactory: $container->get('OCP\AppFramework\Utility\ITimeFactory'),
					eolSyncService: $container->get(EolSyncService::class),
					logger: $container->get(LoggerInterface::class)
				);
			}
		);

		// Register ContactpersonenController with explicit dependencies for /me endpoint.
		$context->registerService(
			ContactpersonenController::class,
			function ($container) {
				return new ContactpersonenController(
					appName: self::APP_ID,
					request: $container->get('OCP\IRequest'),
					settingsService: $container->get(SettingsService::class),
					contactPersonHandler: $container->get(
						'OCA\Stackiq\Service\Stackiq\ContactPersonHandler'
					),
					contactSvc: $container->get(ContactpersoonService::class),
					userManager: $container->get('OCP\IUserManager'),
					groupManager: $container->get('OCP\IGroupManager'),
					userSession: $container->get('OCP\IUserSession'),
					secureRandom: $container->get('OCP\Security\ISecureRandom'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					// ADR-084 added these two to the constructor; this hand-written
					// factory bypasses Nextcloud's autowiring, so it does not gain
					// them automatically. Omitting them is an ArgumentCountError at
					// request time and nothing catches it before then — see
					// tests/Unit/AppInfo/CompositionRootArgumentsTest.php.
					objectService: $container->get(ObjectServiceInterface::class),
					organisationService: $container->get(OpenRegisterOrganisationService::class)
				);
			}
		);

		// Dashboard widgets — see lib/Dashboard/*.php and src/*Widget.js.
		$context->registerDashboardWidget(ConceptOrganisatiesWidget::class);
	}//end registerDomainServices()

	/**
	 * Wire all OpenRegister event listeners.
	 *
	 * Single-responsibility helper extracted from `register()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 9.1. Keeps
	 * `register()` focused on service-binding wiring while this method
	 * owns the listener catalogue.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-9-1
	 */
	private function registerEventListeners(IRegistrationContext $context): void {
		// TEST event listener for easily triggerable Nextcloud events.
		$context->registerEventListener(UserLoggedInEvent::class, TestEventListener::class);

		// OpenRegister object lifecycle events are NO LONGER broadcast to
		// StackiqEventListener.
		//
		// That listener's own docblock has said "DISABLED: All processing is now
		// handled by cron-based OrganizationSyncService to avoid race conditions"
		// for some time — and the work really did move: OrganizationContactSyncJob
		// runs performOrganizationsSync / performContactSync / performUserSync on
		// a schedule. Only these registrations were never removed, so the listener
		// kept running its full body on every object event in the instance and
		// then discarded the result.
		//
		// The cost was not theoretical. Importing OpenCatalogi's configuration —
		// twelve seeded objects — produced 657 Stackiq event handlings.
		// Each one resolved three services from the container and wrote six log
		// lines BEFORE reaching the schema check that decides the event is not
		// ours. `occ maintenance:repair` reached 119 of 120 steps and then sat in
		// that last step for 25+ minutes at 100% CPU.
		//
		// Deliberately not "fixed" by making the listener filter earlier: the
		// documented design is that it does not run at all.
		// Module-compliance subscriber — runs on create/update only.
		$context->registerEventListener(ObjectCreatedEvent::class, ModuleComplianceSubscriber::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class);

		// Module registration — sets registeredBy on each save.
		$context->registerEventListener(ObjectCreatedEvent::class, ModuleRegistrationSubscriber::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ModuleRegistrationSubscriber::class);

		// Sync user profile updates into the contactpersoon mirror.
		$context->registerEventListener(UserProfileUpdatedEvent::class, UserProfileUpdatedEventListener::class);

		// Project a concluded contract-approval Decision from the decision app
		// onto the catalog contract. The listener filters by sourceApp and
		// IDOR-checks the decision id before projecting (the In onderhandeling
		// -> Actief transition is reached only here). Replaces the former HTTP
		// outcome-callback + daily reconcile poll.
		//
		// BOTH SPELLINGS, by FQN STRING rather than `::class`. That app renamed
		// its PSR-4 root from OCA\Decidesk to OCA\Decidiq with no compatibility
		// alias, and `::class` on an imported name resolves at COMPILE TIME — so
		// this registered a class nothing dispatches any more, the listener
		// never fired, and every approved contract stayed in `In onderhandeling`.
		// An event with no listener and a listener on no event look identical
		// from here: nothing throws, nothing is logged.
		//
		// Registering a name that does not resolve is harmless, because dispatch
		// matches on the concrete event class, but the guard is kept so this
		// stays symmetric with ContractApprovalService::isDelegationConfigured()
		// on the outbound side.
		foreach (ContractApprovalService::DECISION_CONCLUDED_EVENTS as $concludedEvent) {
			if (class_exists($concludedEvent) === false) {
				continue;
			}

			$context->registerEventListener($concludedEvent, DecisionConcludedListener::class);
		}

	}//end registerEventListeners()

	/**
	 * Boot the application
	 *
	 * @param IBootContext $context Boot context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function boot(IBootContext $context): void {
		// Background jobs are registered declaratively in appinfo/info.xml.
		// Initialization is handled by the Repair step (InitializeSettings).
		// Provision IAppConfig keys referenced as `@resolve:<key>` sentinels in
		// src/manifest.json so @conduction/nextcloud-vue's resolver chain can
		// pick them up synchronously via @nextcloud/initial-state (Step 1)
		// without falling through to the deferred backend fetch (Step 2).
		// See node_modules/@conduction/nextcloud-vue/src/utils/resolveManifestSentinels.js
		// — readInitialState() calls loadState(appId, key, undefined).
		$container = $this->getContainer();
		$initialState = $container->get(IInitialState::class);
		$appConfig = $container->get(IAppConfig::class);

		// Keys must match every distinct `@resolve:<key>` sentinel in
		// src/manifest.json. Discover with:
		// grep -oE '@resolve:[a-z_]+' src/manifest.json | sort -u.
		//
		// The canonical home for the voorzieningen register id is the
		// `voorzieningen_config` JSON blob (`{"register":"11", ...}`) written
		// by the settings UI / auto-configure flow. The flat scalar key
		// `voorzieningen_register` is a legacy fallback that is usually unset.
		// We must provision the resolved numeric id from whichever source has
		// it, otherwise the manifest sentinel resolves to null and every index
		// page fires `GET .../objects/@resolve:voorzieningen_register/<schema>`
		// (404). See node_modules/@conduction/nextcloud-vue/src/utils/resolveManifestSentinels.js.
		$registerId = $this->resolveVoorzieningenRegisterId(appConfig: $appConfig);
		$provisioned = null;
		if ($registerId !== '') {
			$provisioned = $registerId;
		}

		$initialState->provideInitialState('voorzieningen_register', $provisioned);

		// The SECOND register this app owns. lib/Settings/softwarecatalogus_register.json
		// declares two: `voorzieningen` (15 schemas) and `vng-gemma` / AMEF
		// (element, model, property-definition, relation, view). A schema is only
		// fetchable through /api/objects/{register}/{schema} from a register that
		// ATTACHES it — OpenRegister's ObjectService::setSchema() resolves a slug
		// register-scoped and, since its 2026-08-16 change, THROWS on a scoped miss
		// instead of falling back to a global lookup. So the manifest pages whose
		// schema lives in the AMEF register (Standaarden / StandaardDetail, both
		// `schema: element`) need their OWN sentinel; pointing them at
		// `voorzieningen_register` yields `404 {"message":"Schema not found:
		// 'element'"}` on every fetch.
		//
		// Its canonical home is the `amef_config` JSON blob written by
		// SettingsService::configureAmef(), exactly as `voorzieningen_config` is for
		// the register above. configureAmef() detects the register by the PRESENCE of
		// the AMEF core schemas rather than by slug, which is the property this
		// sentinel needs — whatever it selects is by construction a register that
		// carries `element`.
		$amefRegisterId = $this->resolveAmefRegisterId(appConfig: $appConfig);
		$amefProvisioned = null;
		if ($amefRegisterId !== '') {
			$amefProvisioned = $amefRegisterId;
		}

		$initialState->provideInitialState('amef_register', $amefProvisioned);
	}//end boot()

	/**
	 * Resolve the numeric AMEF (vng-gemma) register id from the canonical config.
	 *
	 * Mirrors resolveVoorzieningenRegisterId() below, over the AMEF key family.
	 * Resolution order:
	 *  1. `amef_config` JSON blob's `register` field — the canonical home written
	 *     by SettingsService::setAmefConfig() from configureAmef().
	 *  2. The same blob's `register_id` field — the legacy shape
	 *     SettingsService::getAmefConfig() assembles when the blob is absent.
	 *  3. The flat `amef_register_id` scalar key — legacy fallback.
	 *
	 * @param IAppConfig $appConfig The app config service.
	 *
	 * @return string The numeric register id, or '' when none is configured.
	 */
	private function resolveAmefRegisterId(IAppConfig $appConfig): string {
		$configJson = $appConfig->getValueString(self::APP_ID, 'amef_config', '');
		if ($configJson !== '') {
			$decoded = json_decode($configJson, true);
			if (is_array($decoded) === true) {
				foreach (['register', 'register_id'] as $key) {
					if (isset($decoded[$key]) === true && $decoded[$key] !== '') {
						return (string)$decoded[$key];
					}
				}
			}
		}

		return $appConfig->getValueString(self::APP_ID, 'amef_register_id', '');
	}//end resolveAmefRegisterId()

	/**
	 * Resolve the numeric voorzieningen register id from the canonical config.
	 *
	 * Resolution order:
	 *  1. `voorzieningen_config` JSON blob's `register` field — the canonical
	 *     home written by the settings UI / auto-configure flow.
	 *  2. `voorzieningen` JSON blob's `configured.register` field — the legacy
	 *     auto-configure result envelope.
	 *  3. The flat `voorzieningen_register` scalar key — legacy fallback.
	 *
	 * @param IAppConfig $appConfig The app config service.
	 *
	 * @return string The numeric register id, or '' when none is configured.
	 */
	private function resolveVoorzieningenRegisterId(IAppConfig $appConfig): string {
		$configJson = $appConfig->getValueString(self::APP_ID, 'voorzieningen_config', '');
		if ($configJson !== '') {
			$decoded = json_decode($configJson, true);
			if (is_array($decoded) === true
				&& isset($decoded['register']) === true
				&& $decoded['register'] !== ''
			) {
				return (string)$decoded['register'];
			}
		}

		$envelopeJson = $appConfig->getValueString(self::APP_ID, 'voorzieningen', '');
		if ($envelopeJson !== '') {
			$decoded = json_decode($envelopeJson, true);
			if (is_array($decoded) === true
				&& isset($decoded['configured']['register']) === true
				&& $decoded['configured']['register'] !== ''
			) {
				return (string)$decoded['configured']['register'];
			}
		}

		return $appConfig->getValueString(self::APP_ID, 'voorzieningen_register', '');
	}//end resolveVoorzieningenRegisterId()
}//end class
