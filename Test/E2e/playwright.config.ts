import { defineConfig } from '@playwright/test';

/**
 * The store under test is a local Magento with the module installed, see README.md.
 * Both values can be overridden, so the same suite runs against 2.4.8 and 2.4.9.
 */
export const config = {
  baseURL: process.env.MAGENTO_BASE_URL ?? 'https://localhost:8445',
  container: process.env.MAGENTO_CONTAINER ?? 'magento249-phpfpm-1',
  adminPath: process.env.MAGENTO_ADMIN_PATH ?? '/admin',
  adminUser: process.env.MAGENTO_ADMIN_USER ?? 'admin',
  adminPassword: process.env.MAGENTO_ADMIN_PASSWORD ?? 'Admin123!',
};

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  // a Magento in developer mode compiles on the first hit of every page
  timeout: 180_000,
  expect: { timeout: 30_000 },
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: config.baseURL,
    ignoreHTTPSErrors: true,
    actionTimeout: 60_000,
    navigationTimeout: 120_000,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
