import { execFileSync } from 'node:child_process';
import { config } from './playwright.config';

/**
 * Runs one of the seed scripts in the store's `var/` directory and returns the JSON object it prints
 */
export function runSeeder<T>(script: string, args: string[]): T {
  const output = execFileSync(
    'docker',
    ['exec', config.container, 'sh', '-c', `cd /var/www/html && php var/${script} ${args.join(' ')}`],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }
  );

  return JSON.parse(output.slice(output.indexOf('{')));
}
