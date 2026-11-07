/**
 * Account Storage
 *
 * Handles loading and saving account configuration to disk.
 * When REMOTE_STORAGE_URL is set, delegates to the PHP remote API instead
 * of the local filesystem (required for stateless deployments like Render).
 */

import { readFile, writeFile, mkdir, access, rename } from 'fs/promises';
import { constants as fsConstants } from 'fs';
import { dirname } from 'path';
import { ACCOUNT_CONFIG_PATH } from '../constants.js';
import { getAuthStatus, isDatabaseAccessible } from '../auth/database.js';
import { logger } from '../utils/logger.js';
import { remoteLoadAccounts, remoteSaveAccounts } from './remote-storage.js';

/** True when a remote PHP storage backend is configured */
const USE_REMOTE_STORAGE = Boolean(process.env.REMOTE_STORAGE_URL);

let writeLock = null;

/**
 * Load accounts from the config file (or remote PHP API when configured)
 *
 * @param {string} configPath - Path to the config file (ignored when remote)
 * @returns {Promise<{accounts: Array, settings: Object, activeIndex: number}>}
 */
export async function loadAccounts(configPath = ACCOUNT_CONFIG_PATH) {
    // ── Remote mode ──────────────────────────────────────────────────────────
    if (USE_REMOTE_STORAGE) {
        try {
            return await remoteLoadAccounts();
        } catch (error) {
            logger.error(`[AccountManager] Remote load failed, falling back to local: ${error.message}`);
            // Fall through to local file load below
        }
    }

    // ── Local file mode ───────────────────────────────────────────────────────
    try {
        // Check if config file exists using async access
        await access(configPath, fsConstants.F_OK);
        const configData = await readFile(configPath, 'utf-8');
        const config = JSON.parse(configData);

        const accounts = (config.accounts || []).map(acc => ({
            ...acc,
            lastUsed: acc.lastUsed || null,
            enabled: acc.enabled !== false, // Default to true if not specified
            // Reset invalid flag on startup - give accounts a fresh chance
            // EXCEPT accounts with a verifyUrl — those need user intervention
            isInvalid: acc.verifyUrl ? (acc.isInvalid || false) : false,
            invalidReason: acc.verifyUrl ? (acc.invalidReason || null) : null,
            verifyUrl: acc.verifyUrl || null,
            modelRateLimits: acc.modelRateLimits || {},
            // New fields for subscription and quota tracking
            subscription: acc.subscription || { tier: 'unknown', projectId: null, detectedAt: null },
            quota: acc.quota || { models: {}, lastChecked: null },
            // Quota threshold settings (per-account and per-model overrides)
            quotaThreshold: acc.quotaThreshold,  // undefined means use global
            modelQuotaThresholds: acc.modelQuotaThresholds || {}
        }));

        const settings = config.settings || {};
        let activeIndex = config.activeIndex || 0;

        // Clamp activeIndex to valid range
        if (activeIndex >= accounts.length) {
            activeIndex = 0;
        }

        logger.info(`[AccountManager] Loaded ${accounts.length} account(s) from config`);

        return { accounts, settings, activeIndex };
    } catch (error) {
        if (error.code === 'ENOENT') {
            // No config file - return empty
            logger.info('[AccountManager] No config file found. Using Antigravity database (single account mode)');
        } else {
            logger.error('[AccountManager] Failed to load config:', error.message);
        }
        return { accounts: [], settings: {}, activeIndex: 0 };
    }
}

/**
 * Load the default account from Antigravity's database
 *
 * @param {string} dbPath - Optional path to the database
 * @returns {{accounts: Array, tokenCache: Map}}
 */
export function loadDefaultAccount(dbPath) {
    try {
        if (!isDatabaseAccessible(dbPath)) {
            logger.debug('[AccountManager] Antigravity database not accessible (cloud/headless mode)');
            return { accounts: [], tokenCache: new Map() };
        }
        const authData = getAuthStatus(dbPath);
        if (authData?.apiKey) {
            const account = {
                email: authData.email || 'default@antigravity',
                source: 'database',
                lastUsed: null,
                modelRateLimits: {}
            };

            const tokenCache = new Map();
            tokenCache.set(account.email, {
                token: authData.apiKey,
                extractedAt: Date.now()
            });

            logger.info(`[AccountManager] Loaded default account: ${account.email}`);

            return { accounts: [account], tokenCache };
        }
    } catch (error) {
        logger.error('[AccountManager] Failed to load default account:', error.message);
    }

    return { accounts: [], tokenCache: new Map() };
}

/**
 * Save account configuration to disk (or remote PHP API when configured)
 *
 * @param {string} configPath - Path to the config file (ignored when remote)
 * @param {Array}  accounts
 * @param {Object} settings
 * @param {number} activeIndex
 */
export async function saveAccounts(configPath, accounts, settings, activeIndex) {
    // ── Remote mode ──────────────────────────────────────────────────────────
    if (USE_REMOTE_STORAGE) {
        // remoteSaveAccounts is fire-and-forget with internal error logging
        await remoteSaveAccounts(accounts, settings, activeIndex);
        return;
    }

    // ── Local file mode ───────────────────────────────────────────────────────
    // Serialize writes to prevent concurrent corruption
    const previousLock = writeLock;
    let resolve;
    writeLock = new Promise(r => { resolve = r; });

    try {
        if (previousLock) await previousLock;
    } catch {
        // Previous write failed, proceed anyway
    }

    try {
        const dir = dirname(configPath);
        await mkdir(dir, { recursive: true });

        const config = {
            accounts: accounts.map(acc => ({
                email: acc.email,
                source: acc.source,
                enabled: acc.enabled !== false,
                dbPath: acc.dbPath || null,
                refreshToken: acc.source === 'oauth' ? acc.refreshToken : undefined,
                apiKey: acc.source === 'manual' ? acc.apiKey : undefined,
                projectId: acc.projectId || undefined,
                addedAt: acc.addedAt || undefined,
                isInvalid: acc.isInvalid || false,
                invalidReason: acc.invalidReason || null,
                verifyUrl: acc.verifyUrl || null,
                modelRateLimits: acc.modelRateLimits || {},
                lastUsed: acc.lastUsed,
                subscription: acc.subscription || { tier: 'unknown', projectId: null, detectedAt: null },
                quota: acc.quota || { models: {}, lastChecked: null },
                quotaThreshold: acc.quotaThreshold,
                modelQuotaThresholds: Object.keys(acc.modelQuotaThresholds || {}).length > 0 ? acc.modelQuotaThresholds : undefined
            })),
            settings: settings,
            activeIndex: activeIndex
        };

        const json = JSON.stringify(config, null, 2);

        // Validate JSON before writing (prevent saving corrupt data)
        JSON.parse(json);

        // Atomic write: write to temp file then rename
        const tmpPath = configPath + '.tmp';
        await writeFile(tmpPath, json);
        await rename(tmpPath, configPath);
    } catch (error) {
        logger.error('[AccountManager] Failed to save config:', error.message);
    } finally {
        resolve();
    }
}
