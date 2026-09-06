<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Ratings & reviews body widget (stackiq#375) — registered via
  - src/customComponents.js and placed on ModuleDetail's `bodyWidgets`
  - (same escape hatch as ContractApprovalPanel/OrganisationMergePanel).
  - Shows the approved-only aggregate (average + count) computed server-side
  - by ReviewService, a short list of approved reviews, and a "Write a
  - review" action opening SubmitReviewModal.vue. Reviews are fetched
  - PUBLICLY (GET /api/reviews/aggregate is #[PublicPage]) so the panel
  - renders correctly for anonymous visitors of a published module/dienst;
  - only the submit action requires being signed in.
  -
  - @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
  -->

<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Ratings & reviews')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="Star" :size="20" />
		</template>
		<div class="reviews-panel">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading reviews')" />

			<template v-else>
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<div class="reviews-panel__summary">
					<div class="reviews-panel__average">
						<span
							v-if="average !== null"
							class="reviews-panel__average-value"
							>{{ average
							}}<span class="reviews-panel__average-max"
								>/10</span
							></span
						>
						<span
							v-else
							class="reviews-panel__average-value reviews-panel__average-value--empty"
							>—</span
						>
						<span class="reviews-panel__count">
							{{
								count === 1
									? t('stackiq', '1 review')
									: t('stackiq', '{count} reviews', {
											count,
										})
							}}
						</span>
					</div>
					<NcButton variant="secondary" @click="openSubmitModal">
						<template #icon>
							<Star :size="20" />
						</template>
						{{ t('stackiq', 'Write a review') }}
					</NcButton>
				</div>

				<NcEmptyContent
					v-if="items.length === 0"
					:name="t('stackiq', 'No reviews yet')"
					:description="
						t('stackiq', 'Be the first to share your experience.')
					">
					<template #icon>
						<Star :size="40" />
					</template>
				</NcEmptyContent>

				<ul v-else class="reviews-panel__list">
					<li
						v-for="item in items"
						:key="item.id"
						class="reviews-panel__item">
						<div class="reviews-panel__item-header">
							<span class="reviews-panel__item-title">{{
								item.name
							}}</span>
							<span class="reviews-panel__item-rating"
								>{{ item.rating }}/10</span
							>
						</div>
						<p
							v-if="item.longDescription"
							class="reviews-panel__item-body">
							{{ item.longDescription }}
						</p>
						<span class="reviews-panel__item-author help-text">{{
							item.auteur
						}}</span>
					</li>
				</ul>
			</template>
		</div>

		<SubmitReviewModal
			:show="showSubmitModal"
			:subjectType="subjectType"
			:subjectId="objectId"
			@close="showSubmitModal = false"
			@reviewSubmitted="loadAggregate" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Star from 'vue-material-design-icons/Star.vue'
import SubmitReviewModal from '../../modals/SubmitReviewModal.vue'
import { apiRequest } from '../../utils/adminApi.js'
import { aggregatePath, normaliseAggregate } from '../../utils/reviewAggregate.js'

/**
 * Aggregate rating + submit-review body widget for a module/dienst detail page.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
export default {
	name: 'ReviewsPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		CnWidgetWrapper,
		CnIcon,
		Star,
		SubmitReviewModal,
	},

	props: {
		/**
		 * The module/dienst OR object uuid (passed by the manifest's
		 * bodyWidgets `@objectId` placeholder).
		 */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** 'module' or 'catalogService' — set per-page in src/manifest.json. */
		subjectType: {
			type: String,
			default: 'module',
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			average: null,
			count: 0,
			items: [],
			showSubmitModal: false,
		}
	},

	async created() {
		await this.loadAggregate()
	},

	methods: {
		t,

		/**
		 * Load the approved-only aggregate + review list for this subject.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
		 */
		async loadAggregate() {
			if (!this.objectId) {
				this.loading = false
				return
			}

			this.loading = true
			this.error = ''
			try {
				const data = await apiRequest(
					aggregatePath(this.subjectType, String(this.objectId)),
				)
				const normalised = normaliseAggregate(data)
				this.average = normalised.average
				this.count = normalised.count
				this.items = normalised.items
			} catch (fetchError) {
				this.error =
					t('stackiq', 'Could not load reviews')
					+ ': '
					+ fetchError.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the submit-review modal.
		 *
		 * @return {void}
		 * @spec openspec/specs/catalog-ratings/spec.md
		 */
		openSubmitModal() {
			this.showSubmitModal = true
		},
	},
}
</script>

<style scoped>
.reviews-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.reviews-panel__summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.reviews-panel__average {
	display: flex;
	flex-direction: column;
}

.reviews-panel__average-value {
	font-size: 28px;
	font-weight: 600;
	color: var(--color-main-text);
}

.reviews-panel__average-value--empty {
	color: var(--color-text-maxcontrast);
}

.reviews-panel__average-max {
	font-size: 14px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.reviews-panel__count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.reviews-panel__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.reviews-panel__item {
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.reviews-panel__item-header {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-weight: 600;
}

.reviews-panel__item-body {
	margin: 4px 0;
	color: var(--color-main-text);
}

.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
}
</style>
