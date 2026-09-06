<?php

/**
 * Review (beoordeeling) submission + approved-only read/aggregate controller.
 *
 * `submit` requires an authenticated Nextcloud session (`#[NoAdminRequired]`,
 * normal CSRF protection — unlike `IntakeController`, this is never
 * anonymous, so no `#[PublicPage]`/`#[NoCSRFRequired]`/rate-limit). The
 * author identity is always taken from the session inside `ReviewService`,
 * never from the request body.
 *
 * `aggregate` is `#[PublicPage]` (read-only, approved-only reviews are
 * intentionally public per the fail-closed schema RBAC) so it works on an
 * anonymous module/dienst detail page view, mirroring `FacetController`.
 *
 * @category  Controller
 * @package   OCA\Stackiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\ReviewAggregateService;
use OCA\Stackiq\Service\ReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Authenticated review submission + public approved-only aggregate.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
class ReviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ReviewService $reviews The review submission service.
	 * @param ReviewAggregateService $aggregate The review aggregate/read service.
	 */
	public function __construct(
		IRequest $request,
		private readonly ReviewService $reviews,
		private readonly ReviewAggregateService $aggregate,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Submit an authenticated review into the moderation queue.
	 *
	 * @param array<string,mixed> $review The review payload (naam, waardering, beschrijvingKort/Lang).
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst being reviewed.
	 *
	 * @return JSONResponse `{ok, uuid, status}` (202 Accepted) or a 400/401.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
	 */
	#[NoAdminRequired]
	public function submit(array $review = [], string $subjectType = '', string $subjectId = ''): JSONResponse {
		$result = $this->reviews->submit(payload: $review, subjectType: $subjectType, subjectId: $subjectId);
		if ($result['ok'] === false) {
			$statusCode = Http::STATUS_BAD_REQUEST;
			if ($result['reason'] === 'not authenticated') {
				$statusCode = Http::STATUS_UNAUTHORIZED;
			}

			return new JSONResponse(data: ['message' => $result['reason']], statusCode: $statusCode);
		}

		return new JSONResponse(
			data: [
				'ok' => true,
				'uuid' => $result['uuid'],
				'status' => $result['status'],
				'message' => 'Review received and queued for moderation',
			],
			statusCode: Http::STATUS_ACCEPTED
		);
	}//end submit()

	/**
	 * The approved-only aggregate (average + count) and a bounded list of
	 * approved reviews for a module or dienst.
	 *
	 * @param string $subjectType 'module' or 'catalogService'.
	 * @param string $subjectId The uuid of the module/dienst.
	 *
	 * @return JSONResponse `{average, count, items}` or a 400.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt The lookup IS constrained to the scope this
	 *      endpoint declares public, which is the remedy for
	 *      `publicpage-unscoped-object-lookup` (there is no session identity on
	 *      a public page, so an ownership check is not available and not the
	 *      fix). `$subjectId` never reaches storage: the query in
	 *      ReviewAggregateService::fetchApprovedReviews() is keyed on
	 *      `@self.register` / `@self.schema` plus `status = approved` — with
	 *      the metadata filters correctly nested under `@self` — and the
	 *      subject is matched in memory afterwards. `status` is then re-checked
	 *      in PHP over every row, which that method's own comment names as the
	 *      real enforcement point because `_rbac: false` bypasses
	 *      OpenRegister's enforcement of the predicate. So an arbitrary
	 *      `subjectId` can only ever select from already-approved reviews.
	 * @spec           openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	 *
	 * Rate limit: a read of already-published aggregate review scores — no
	 * credential, and the data is public by design, so a volume ceiling only.
	 * Much looser than IntakeController's 5/3600: that one accepts a SUBMISSION,
	 * this one answers a page render, and a catalogue page listing many subjects
	 * will legitimately call it repeatedly.
	 *
	 * (This note lived between the attributes and the signature, where PHPCS
	 * reads any comment as the function's doc comment and requires docblock
	 * syntax. It belongs up here regardless — nothing should sit between an
	 * attribute list and the thing it annotates.)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function aggregate(string $subjectType = '', string $subjectId = ''): JSONResponse {
		$result = $this->aggregate->getAggregate(subjectType: $subjectType, subjectId: $subjectId);
		if ($result['ok'] === false) {
			return new JSONResponse(data: ['message' => $result['reason']], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			data: [
				'average' => $result['average'],
				'count' => $result['count'],
				'items' => $result['items'],
			]
		);
	}//end aggregate()
}//end class
