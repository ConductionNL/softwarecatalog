<?php

/**
 * Runtime doubles for the decision app's conclusion event, under BOTH of the
 * namespaces it has shipped.
 *
 * Required explicitly by DecisionConcludedListenerNamespaceTest, NOT placed in
 * `tests/Stubs/`. `tests/bootstrap.php` globs that directory and lets whatever
 * it finds win over the real classes for every test in the suite; the
 * analysis-stub headers already record why the decision-event classes must stay
 * out of that glob. One test needs these to exist as real, instantiable classes
 * so it can dispatch one at the listener, and nothing else should see them.
 *
 * Each declaration is guarded, so on an instance where the real app is
 * installed and autoloadable the real class wins and this file adds nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace {
	// The base class both doubles extend, and the interface the listener under
	// test implements. `nextcloud/ocp` ships it as a real,
	// self-contained class (its only dependency is the PSR
	// StoppableEventInterface, which composer does autoload) but declares NO
	// autoload section, so in pure-unit mode — a checkout outside an installed
	// Nextcloud root, which is how this suite runs on a developer machine and
	// how tests/bootstrap.php deliberately arranges it — nothing resolves it.
	//
	// Without this the listener cannot be called at all: `handle(Event $event)`
	// resolves its parameter type at call time. Skipping instead would make the
	// one test that proves the fix unable to fail locally, which is the same as
	// not having it.
	//
	// Guarded: when a real Nextcloud root has booted, the real class is already
	// loaded and this does nothing.
	$ocpDir = __DIR__ . '/../../../vendor/nextcloud/ocp/OCP/EventDispatcher/';
	if (interface_exists(\OCP\EventDispatcher\IEventListener::class, false) === false
		&& file_exists($ocpDir . 'IEventListener.php') === true
	) {
		require_once $ocpDir . 'IEventListener.php';
	}

	if (class_exists(\OCP\EventDispatcher\Event::class, false) === false
		&& file_exists($ocpDir . 'Event.php') === true
	) {
		require_once $ocpDir . 'Event.php';
	}
}

namespace OCA\Decidiq\Event {
	if (class_exists(DecisionConcludedEvent::class, false) === false) {
		/**
		 * Runtime double for decidiq's DecisionConcludedEvent.
		 */
		class DecisionConcludedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param string $decisionId The concluded Decision id.
			 * @param string $status The derived outcome status.
			 * @param string $sourceApp The consumer app that raised the decision.
			 * @param string|null $subjectId The originating object id.
			 * @param string $externalReference The consumer's own reference.
			 */
			public function __construct(
				private readonly string $decisionId,
				private readonly string $status,
				private readonly string $sourceApp,
				private readonly ?string $subjectId = null,
				private readonly string $externalReference = '',
			) {
				parent::__construct();
			}

			/** @return string The decision id. */
			public function getDecisionId(): string {
				return $this->decisionId;
			}

			/** @return string The status. */
			public function getStatus(): string {
				return $this->status;
			}

			/** @return string The source app. */
			public function getSourceApp(): string {
				return $this->sourceApp;
			}

			/** @return string|null The subject id. */
			public function getSubjectId(): ?string {
				return $this->subjectId;
			}

			/** @return string The external reference. */
			public function getExternalReference(): string {
				return $this->externalReference;
			}
		}
	}
}

namespace OCA\Decidesk\Event {
	if (class_exists(DecisionConcludedEvent::class, false) === false) {
		/**
		 * Runtime double for the pre-rename spelling of the same event.
		 */
		class DecisionConcludedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param string $decisionId The concluded Decision id.
			 * @param string $status The derived outcome status.
			 * @param string $sourceApp The consumer app that raised the decision.
			 * @param string|null $subjectId The originating object id.
			 * @param string $externalReference The consumer's own reference.
			 */
			public function __construct(
				private readonly string $decisionId,
				private readonly string $status,
				private readonly string $sourceApp,
				private readonly ?string $subjectId = null,
				private readonly string $externalReference = '',
			) {
				parent::__construct();
			}

			/** @return string The decision id. */
			public function getDecisionId(): string {
				return $this->decisionId;
			}

			/** @return string The status. */
			public function getStatus(): string {
				return $this->status;
			}

			/** @return string The source app. */
			public function getSourceApp(): string {
				return $this->sourceApp;
			}

			/** @return string|null The subject id. */
			public function getSubjectId(): ?string {
				return $this->subjectId;
			}

			/** @return string The external reference. */
			public function getExternalReference(): string {
				return $this->externalReference;
			}
		}
	}
}
