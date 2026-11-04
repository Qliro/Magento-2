import { expect, test } from '@playwright/test';
import { runSeeder } from '../seeder';

/**
 * A log line whose insert into `qliroone_log` hits a deadlock is written again as it was. A trigger
 * answers the first inserts of the line with MariaDB's own deadlock error, 1213.
 *
 * Needs `Test/E2e/seed/log-deadlock.php` in the store's `var/`.
 */
type Row = { level: string; message: string; reference: string; tags: string; process_id: string; extra: string };
type Logged = { attempts: number; rows: Row[]; pid: number };

function log(failures: number): { reference: string; logged: Logged } {
  // no long run of digits, the redactor would mask it as a personal number
  const reference = `e2e-deadlock-${failures}-${Date.now().toString(36)}`;

  return { reference, logged: runSeeder<Logged>('log-deadlock.php', ['--do=log', `--reference=${reference}`, `--failures=${failures}`]) };
}

test.describe('a log line that hits a deadlock', () => {
  test('is stored as first written on the next attempt', () => {
    const { reference, logged } = log(1);

    expect(logged.attempts).toBe(2);
    expect(logged.rows).toHaveLength(1);
    const [row] = logged.rows;
    expect(row.message).toBe(`E2E DEADLOCK: Capture sent for ${reference}`);
    expect(row.level).toBe('INFO');
    expect(row.reference).toBe(reference);
    expect(row.tags).toBe('capture');
    expect(row.process_id).toBe(String(logged.pid));
    expect(JSON.parse(row.extra)).toEqual({ amount: 100 });
  });

  test('is given up after three attempts without breaking the caller', () => {
    const { logged } = log(3);

    expect(logged.attempts).toBe(3);
    expect(logged.rows).toHaveLength(0);
  });
});
