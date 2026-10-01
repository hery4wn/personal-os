import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/calendarPreview.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
const { calendarPreview } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

test('events follow account-local days across a month boundary', () => {
 const events = [{ starts_at: '2026-08-31T17:00:00Z', ends_at: '2026-09-01T17:00:00Z' }];
 const days = calendarPreview(events, [], '2026-09', 'Asia/Makassar');
 assert.equal(days['2026-09-01'].events, 1);
 assert.equal(days['2026-09-02'].events, 1);
 assert.equal(days['2026-09-03'].events, 0);
 const western = calendarPreview(events, [], '2026-09', 'America/Los_Angeles');
 assert.equal(western['2026-09-01'].events, 1);
 assert.equal(western['2026-09-02'].events, 0);
});

test('check-ins and overlapping events remain independently visible', () => {
 const events = [
  { starts_at: '2026-09-10T01:00:00Z', ends_at: '2026-09-10T02:00:00Z' },
  { starts_at: '2026-09-09T16:00:00Z', ends_at: '2026-09-10T15:59:59Z' },
 ];
 const days = calendarPreview(events, ['2026-09-10', '2026-08-01'], '2026-09', 'Asia/Makassar');
 assert.deepEqual(days['2026-09-10'], { checked: true, events: 2 });
 assert.deepEqual(days['2026-09-11'], { checked: false, events: 0 });
 assert.equal(days['2026-08-01'], undefined);
 assert.deepEqual(calendarPreview([], [], '2026-09', 'Asia/Makassar')['2026-09-10'], { checked: false, events: 0 });
});

test('leap day and year boundary months are represented correctly', () => {
 assert.equal(Object.keys(calendarPreview([], ['2028-02-29'], '2028-02', 'UTC')).length, 29);
 assert.equal(calendarPreview([], ['2028-02-29'], '2028-02', 'UTC')['2028-02-29'].checked, true);
 assert.equal(Object.keys(calendarPreview([], [], '2027-02', 'UTC')).length, 28);
 assert.equal(Object.keys(calendarPreview([], [], '2026-12', 'UTC')).length, 31);
});
