/**
 * Remote Account Storage
 *
 * Drop-in replacement for the file-based loadAccounts / saveAccounts in
 * storage.js.  When REMOTE_STORAGE_URL is set, all reads/writes go to the
 * PHP API instead of the local filesystem.  Falls back to the local file
 * automatically when the env var is absent (keeps local dev working).
 *
 * Env vars (set on Render):
 *   REMOTE_STORAGE_URL      https://yourserver.com/proxy-storage/api.php
 *   REMOTE_STORAGE_SECRET   same as STORAGE_SECRET in php-api/.env
 *
 * Usage — in account-manager/storage.js replace:
 *   import { readFile, writeFile … } from 'fs/promises';
 * with the two exported functions from this file (see bottom of storage.js).
 */

import { logger } from '../utils/logger.js';

const REMOTE_URL    = process.env.REMOTE_STORAGE_URL?.replace(/\/$/, '') ?? null;
const REMOTE_SECRET = process.env.REMOTE_STORAGE_SECRET ?? '';

/** How long to wait for the PHP API before giving up (ms) */
const TIMEOUT_MS = 8_000;

// ─────────────────────────── Internal helpers ─────────────────────────────────

/**
 * Fetch with a hard timeout.
 * Node 18+ has built-in AbortSignal.timeout.
 */
async function fetchWithTimeout(url, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    try {
        return await fetch(url, { ...options, signal: controller.signal });
    } finally {
        clearTimeout(timer);
    }
}

function remoteHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-Api-Secret': REMOTE_SECRET,
    };
}

// ─────────────────────────── Public API ───────────────────────────────────────

/**
 * Load accounts from the remote PHP API.
 *
 * Returns the same shape as the file-based loadAccounts so it can be used
 * as a transparent replacement.
 *
 * @returns {Promise<{accounts: Array, settings: Object, activeIndex: number}>}
 */
export async function remoteLoadAccounts() {
    if (!REMOTE_URL) {
        throw new Error('[RemoteStorage] REMOTE_STORAGE_URL is not set');
    }

    logger.debug('[RemoteStorage] Loading accounts from remote API…');

    let res;
    try {
        res = await fetchWithTimeout(`${REMOTE_URL}?action=load`, {
            method: 'GET',
            headers: remoteHeaders(),
        });
    } catch (err) {
        throw new Error(`[RemoteStorage] Network error on load: ${err.message}`);
    }

    if (!res.ok) {
        const text = await res.text().catch(() => '');
        throw new Error(`[RemoteStorage] Load failed (${res.status}): ${text.slice(0, 200)}`);
    }

    const data = await res.json();

    // Normalise — same mapping as file-based loadAccounts
    const accounts = (data.accounts ?? []).map(acc => ({
        ...acc,
        lastUsed:            acc.lastUsed ?? null,
        enabled:             acc.enabled !== false,
        isInvalid:           acc.verifyUrl ? (acc.isInvalid ?? false) : false,
        invalidReason:       acc.verifyUrl ? (acc.invalidReason ?? null) : null,
        verifyUrl:           acc.verifyUrl ?? null,
        modelRateLimits:     acc.modelRateLimits ?? {},
        subscription:        acc.subscription ?? { tier: 'unknown', projectId: null, detectedAt: null },
        quota:               acc.quota ?? { models: {}, lastChecked: null },
        quotaThreshold:      acc.quotaThreshold,
        modelQuotaThresholds: acc.modelQuotaThresholds ?? {},
    }));

    const settings    = data.settings ?? {};
    let   activeIndex = data.activeIndex ?? 0;
    if (activeIndex >= accounts.length) activeIndex = 0;

    logger.info(`[RemoteStorage] Loaded ${accounts.length} account(s) from remote`);
    return { accounts, settings, activeIndex };
}

/**
 * Save accounts to the remote PHP API.
 *
 * Fire-and-forget style with error logging — a save failure should never
 * crash the proxy; the in-memory state is still valid.
 *
 * @param {Array}  accounts
 * @param {Object} settings
 * @param {number} activeIndex
 */
export async function remoteSaveAccounts(accounts, settings, activeIndex) {
    if (!REMOTE_URL) {
        throw new Error('[RemoteStorage] REMOTE_STORAGE_URL is not set');
    }

    const payload = {
        accounts: accounts.map(acc => ({
            email:               acc.email,
            source:              acc.source,
            enabled:             acc.enabled !== false,
            dbPath:              acc.dbPath ?? null,
            refreshToken:        acc.source === 'oauth'   ? acc.refreshToken : undefined,
            apiKey:              acc.source === 'manual'  ? acc.apiKey       : undefined,
            projectId:           acc.projectId ?? undefined,
            addedAt:             acc.addedAt   ?? undefined,
            isInvalid:           acc.isInvalid   ?? false,
            invalidReason:       acc.invalidReason ?? null,
            verifyUrl:           acc.verifyUrl    ?? null,
            modelRateLimits:     acc.modelRateLimits ?? {},
            lastUsed:            acc.lastUsed,
            subscription:        acc.subscription ?? { tier: 'unknown', projectId: null, detectedAt: null },
            quota:               acc.quota ?? { models: {}, lastChecked: null },
            quotaThreshold:      acc.quotaThreshold,
            modelQuotaThresholds:
                Object.keys(acc.modelQuotaThresholds ?? {}).length > 0
                    ? acc.modelQuotaThresholds
                    : undefined,
        })),
        settings,
        activeIndex,
    };

    logger.debug(`[RemoteStorage] Saving ${accounts.length} account(s) to remote…`);

    let res;
    try {
        res = await fetchWithTimeout(`${REMOTE_URL}?action=save`, {
            method:  'POST',
            headers: remoteHeaders(),
            body:    JSON.stringify(payload),
        });
    } catch (err) {
        logger.warn(`[RemoteStorage] Network error on save (state preserved in memory): ${err.message}`);
        return;
    }

    if (!res.ok) {
        const text = await res.text().catch(() => '');
        logger.warn(`[RemoteStorage] Save failed (${res.status}): ${text.slice(0, 200)}`);
        return;
    }

    logger.debug('[RemoteStorage] Save successful');
}

