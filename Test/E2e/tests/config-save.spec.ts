import { Page, expect, test } from '@playwright/test';

import { config } from '../playwright.config';
import { loginToAdmin } from './support';

/**
 * The module hooks into every configuration save, its own settings or not, so a section of any
 * module can stop saving because of it: in 1.9.0 one with a multiselect or a table field did,
 * with "Array to string conversion". Every section of Stores > Configuration is saved as it
 * stands, at the default scope and at the first website, and has to answer that it was saved.
 * Seeds nothing, so it runs with `MAGENTO_SKIP_SEED=1` too.
 */
const website = process.env.E2E_CONFIG_WEBSITE_ID ?? '1';
const SAVED = 'You saved the configuration.';

async function sections(page: Page): Promise<string[]> {
  await page.goto(`${config.adminPath}/admin/system_config/`);
  await expect(page.locator('#config-edit-form')).toBeVisible();

  const hrefs = await page
    .locator('#system_config_tabs a[href*="/section/"]')
    .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
  const ids = hrefs.map((href) => /\/section\/([^/]+)/.exec(href)?.[1]).filter((id): id is string => !!id);

  return [...new Set(ids)];
}

type Outcome = { saved: boolean; problem?: string; unsent?: string };

const NO_FORM = 'no form to save';

/** Saves the section as it stands */
async function save(page: Page, section: string, scope: string): Promise<Outcome> {
  await page.goto(`${config.adminPath}/admin/system_config/edit/section/${section}/${scope}`);
  await expect(page.locator('.page-wrapper')).toBeVisible();

  // A section that is a page of its own, with nothing to save
  if (!(await page.locator('#config-edit-form').count()) || !(await page.locator('#save').isVisible())) {
    return { saved: false, unsent: NO_FORM };
  }

  // The button is wired by a script: a click before that does nothing
  await page.waitForLoadState('networkidle');
  await page.locator('#save').click();

  const answer = page.locator('#messages .message-success, #messages .message-error, label.mage-error:visible').first();
  await expect(answer, `${section}: no answer to the save`).toBeVisible({ timeout: 120_000 });

  const text = ((await answer.textContent()) ?? '').trim().slice(0, 300);

  return text.includes(SAVED) ? { saved: true } : { saved: false, problem: text };
}

test.describe('saving the store configuration with the module installed', () => {
  // every section twice, and a save flushes the configuration cache
  test.setTimeout(30 * 60_000);

  test.beforeEach(async ({ page }) => {
    await loginToAdmin(page);
  });

  for (const [name, scope] of [
    ['the default scope', ''],
    ['a website', `website/${website}/`],
  ]) {
    test(`every section saves at ${name}`, async ({ page }) => {
      const all = await sections(page);
      const saved: string[] = [];
      const failed: string[] = [];

      for (const section of all) {
        const outcome = await test.step(section, () => save(page, section, scope));

        if (outcome.saved) {
          saved.push(section);
        } else if (outcome.problem) {
          failed.push(`${section}: ${outcome.problem}`);
        } else {
          test.info().annotations.push({ type: 'not sent', description: `${section}: ${outcome.unsent}` });
        }
      }

      expect(failed).toEqual([]);
      // the module's own settings and the multiselects of other modules are among the saved ones
      expect(saved).toEqual(expect.arrayContaining(['payment', 'general', 'carriers']));
    });
  }
});
